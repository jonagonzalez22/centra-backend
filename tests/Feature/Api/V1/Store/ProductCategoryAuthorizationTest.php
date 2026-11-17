<?php

use App\Models\Category;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\PermissionFeatureResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    PermissionFeatureResolver::clearCache();

    $this->storeAdminRole = Role::findOrCreate('STORE_ADMIN', 'web');
    Role::findOrCreate('STORE_USER', 'web');

    foreach ([
        'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.delete',
        'categories.view', 'categories.create', 'categories.edit', 'categories.delete',
        'pos.view', 'orders.edit',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $this->storeAdminRole->givePermissionTo([
        'inventory.view', 'inventory.create', 'inventory.edit', 'inventory.delete',
        'categories.view', 'categories.create', 'categories.edit', 'categories.delete',
    ]);

    $this->inventory = Feature::firstOrCreate(['code' => 'inventory'], ['name' => 'Inventario']);
    $this->categoriesFeature = Feature::firstOrCreate(['code' => 'categories'], ['name' => 'Categorías']);
    $this->pos = Feature::firstOrCreate(['code' => 'pos'], ['name' => 'Punto de Venta']);

    $this->plan = Plan::factory()->create();
    // The legacy categories feature deliberately remains unassigned: category
    // administration is commercially available through Inventory.
    $this->plan->features()->sync([$this->inventory->id]);
    $this->store = Store::factory()->create(['plan_id' => $this->plan->id]);

    $this->admin = User::factory()->create(['store_id' => $this->store->id]);
    $this->admin->assignRole('STORE_ADMIN');
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_USER');
    $this->category = Category::factory()->belongsToStore($this->store)->create();
    $this->product = Product::factory()->forStore($this->store)->create(['category_id' => $this->category->id]);
});

function productAuthorizationPayload(Category $category, string $sku = 'AUTH-001'): array
{
    return [
        'name' => 'Producto autorizado',
        'sku' => $sku,
        'category_id' => $category->id,
        'price' => '100.00',
        'stock' => '0.0000',
        'stock_min' => '0.0000',
    ];
}

test('a store user without permissions cannot access product or category administration', function (): void {
    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/products')->assertForbidden();
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/products', productAuthorizationPayload($this->category))->assertForbidden();
    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/categories')->assertForbidden();
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/categories', ['name' => 'Nueva'])->assertForbidden();
});

test('view permissions allow only product and category reads', function (): void {
    $this->user->givePermissionTo(['inventory.view', 'categories.view']);

    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/products')->assertOk();
    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/categories')->assertOk();
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/products', productAuthorizationPayload($this->category))->assertForbidden();
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/categories/{$this->category->id}", ['name' => 'Cambio no permitido'])->assertForbidden();
});

test('operational category options follow inventory permissions without category administration', function (): void {
    $this->user->givePermissionTo('inventory.create');
    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/products/category-options')
        ->assertOk()->assertJsonPath('data.items.0.id', $this->category->id);
    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/categories')->assertForbidden();
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/categories', ['name' => 'No'])->assertForbidden();
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/categories/{$this->category->id}", ['name' => 'No'])->assertForbidden();
    $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/store/categories/{$this->category->id}")->assertForbidden();

    $this->user->givePermissionTo('inventory.edit');
    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/products/category-options?include_inactive=1')->assertOk();
    $this->user->givePermissionTo('inventory.view');
    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/products/category-options')->assertOk();
});

test('operational category options require inventory and stay within the store', function (): void {
    $otherStore = Store::factory()->create(['plan_id' => $this->plan->id]);
    $otherCategory = Category::factory()->belongsToStore($otherStore)->create();
    $this->user->givePermissionTo('inventory.create');

    $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/products/category-options')->assertOk();
    expect(collect($response->json('data.items'))->pluck('id'))->not->toContain($otherCategory->id);
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/products', productAuthorizationPayload($otherCategory, 'FOREIGN-CATEGORY'))->assertUnprocessable();

    $withoutFeaturePlan = Plan::factory()->create();
    $withoutFeatureStore = Store::factory()->create(['plan_id' => $withoutFeaturePlan->id]);
    $withoutFeatureUser = User::factory()->create(['store_id' => $withoutFeatureStore->id]);
    $withoutFeatureUser->assignRole('STORE_USER');
    $withoutFeatureUser->givePermissionTo('inventory.create');
    $this->actingAs($withoutFeatureUser, 'sanctum')->getJson('/api/v1/store/products/category-options')->assertForbidden();
});

test('product creation and editing work without categories view using operational category reads', function (): void {
    $this->user->givePermissionTo(['inventory.create', 'inventory.edit']);
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/products', productAuthorizationPayload($this->category, 'NO-CATEGORY-VIEW'))->assertCreated();
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/products/{$this->product->id}", ['name' => 'Editado'])->assertOk()->assertJsonPath('data.category.id', $this->category->id);
});

test('create edit and delete permissions are enforced independently', function (): void {
    $this->user->givePermissionTo('inventory.create');
    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', productAuthorizationPayload($this->category))
        ->assertCreated();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$this->product->id}", ['name' => 'Cambio no permitido'])
        ->assertForbidden();

    $this->user->givePermissionTo('inventory.edit');
    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$this->product->id}", ['name' => 'Cambio permitido'])
        ->assertOk();

    $this->actingAs($this->user, 'sanctum')
        ->deleteJson("/api/v1/store/products/{$this->product->id}")
        ->assertForbidden();

    $this->user->givePermissionTo('inventory.delete');
    $this->actingAs($this->user, 'sanctum')
        ->deleteJson("/api/v1/store/products/{$this->product->id}")
        ->assertOk();

    $this->user->givePermissionTo('categories.create');
    $createdCategory = $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/categories', ['name' => 'Categoría de prueba'])
        ->assertCreated()
        ->json('data.id');

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/categories/{$createdCategory}", ['name' => 'Edición no permitida'])
        ->assertForbidden();

    $this->user->givePermissionTo('categories.edit');
    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/categories/{$createdCategory}", ['name' => 'Edición permitida'])
        ->assertOk();

    $this->actingAs($this->user, 'sanctum')
        ->deleteJson("/api/v1/store/categories/{$createdCategory}")
        ->assertForbidden();

    $this->user->givePermissionTo('categories.delete');
    $this->actingAs($this->user, 'sanctum')
        ->deleteJson("/api/v1/store/categories/{$createdCategory}")
        ->assertOk();
});

test('store admins retain authorized product and category administration', function (): void {
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/store/products')->assertOk();
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/store/products', productAuthorizationPayload($this->category))->assertCreated();
    $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/store/categories', ['name' => 'Nueva categoría'])->assertCreated();
});

test('the legacy categories feature alone does not enable category administration', function (): void {
    $restrictedPlan = Plan::factory()->create();
    $restrictedPlan->features()->sync([$this->categoriesFeature->id]);
    $restrictedStore = Store::factory()->create(['plan_id' => $restrictedPlan->id]);
    $restrictedUser = User::factory()->create(['store_id' => $restrictedStore->id]);
    $restrictedUser->assignRole('STORE_USER');
    $restrictedUser->givePermissionTo(['inventory.view', 'categories.view']);

    $this->actingAs($restrictedUser, 'sanctum')->getJson('/api/v1/store/categories')->assertForbidden();
});

test('product and category mutations remain isolated to the authenticated store', function (): void {
    $this->user->givePermissionTo(['inventory.edit', 'categories.edit']);
    $otherStore = Store::factory()->create(['plan_id' => $this->plan->id]);
    $otherCategory = Category::factory()->belongsToStore($otherStore)->create();
    $otherProduct = Product::factory()->forStore($otherStore)->create(['category_id' => $otherCategory->id]);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$otherProduct->id}", ['name' => 'Ajeno'])
        ->assertNotFound();
    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/categories/{$otherCategory->id}", ['name' => 'Ajena'])
        ->assertNotFound();
});

test('the POS and order catalog remains available without inventory administration', function (): void {
    $posPlan = Plan::factory()->create();
    $posPlan->features()->sync([$this->pos->id]);
    $posStore = Store::factory()->create(['plan_id' => $posPlan->id]);
    $posUser = User::factory()->create(['store_id' => $posStore->id]);
    $posUser->assignRole('STORE_USER');
    $posUser->givePermissionTo('pos.view');
    Product::factory()->forStore($posStore)->create(['name' => 'Producto POS', 'sku' => 'POS-001']);

    $this->actingAs($posUser, 'sanctum')
        ->getJson('/api/v1/store/operations/products/search?q=Producto')
        ->assertOk();
    $this->actingAs($posUser, 'sanctum')->getJson('/api/v1/store/products')->assertForbidden();

    $orderUser = User::factory()->create(['store_id' => $posStore->id]);
    $orderUser->assignRole('STORE_USER');
    $orderUser->givePermissionTo('orders.edit');
    $this->actingAs($orderUser, 'sanctum')
        ->getJson('/api/v1/store/operations/products/search?q=Producto')
        ->assertOk();

    $this->plan->features()->sync([$this->inventory->id, $this->pos->id]);
    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/operations/products/search?q=Producto')
        ->assertForbidden();
});
