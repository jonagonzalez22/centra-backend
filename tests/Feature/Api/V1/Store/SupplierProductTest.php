<?php

use App\Models\Category;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    foreach (['suppliers.view', 'suppliers.edit'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $feature = Feature::factory()->create(['code' => 'purchases']);
    $plan = Plan::factory()->create();
    $plan->features()->attach($feature->id);
    $this->store = Store::factory()->create(['plan_id' => $plan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['suppliers.view', 'suppliers.edit']);
    $this->category = Category::factory()->belongsToStore($this->store)->create();
    $this->supplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Química Mendoza']);
});

function supplierProduct(string $supplier, string $product): string
{
    return "/api/v1/store/suppliers/{$supplier}/products/{$product}";
}

function productForSupplier(Store $store, Category $category, array $attributes = []): Product
{
    return Product::factory()->forStore($store)->create([
        'category_id' => $category->id,
        ...$attributes,
    ]);
}

test('associates a physical product and normalizes optional supplier metadata', function () {
    $product = productForSupplier($this->store, $this->category, [
        'name' => 'Cloro granel',
        'sku' => 'LQUI-000006',
        'is_sellable' => false,
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", [
            'product_id' => $product->id,
            'supplier_code' => '',
            'supplier_product_name' => '  Hipoclorito jaula 500 litros  ',
        ])
        ->assertCreated()
        ->assertJsonPath('data.supplier_code', null)
        ->assertJsonPath('data.supplier_product_name', 'Hipoclorito jaula 500 litros')
        ->assertJsonPath('data.product.id', $product->id)
        ->assertJsonPath('data.product.sku', 'LQUI-000006')
        ->assertJsonPath('data.product.is_sellable', false);

    $this->assertDatabaseHas('supplier_products', [
        'store_id' => $this->store->id,
        'supplier_id' => $this->supplier->id,
        'product_id' => $product->id,
        'supplier_code' => null,
    ]);
});

test('rejects duplicate supplier product associations but permits the same product for another supplier', function () {
    $product = productForSupplier($this->store, $this->category);
    $otherSupplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Otro proveedor']);

    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $product->id])->assertCreated();
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $product->id])->assertUnprocessable()->assertJsonValidationErrors('product_id');
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$otherSupplier->id}/products", ['product_id' => $product->id])->assertCreated();
});

test('allows a supplier to provide multiple physical products and updates and deletes its metadata', function () {
    $first = productForSupplier($this->store, $this->category);
    $second = productForSupplier($this->store, $this->category);
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $first->id])->assertCreated();
    $association = SupplierProduct::query()->where('supplier_id', $this->supplier->id)->where('product_id', $first->id)->firstOrFail();
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $second->id])->assertCreated();

    $this->actingAs($this->user, 'sanctum')->putJson(supplierProduct($this->supplier->id, $association->id), [
        'supplier_code' => '  QMI-CL500  ',
        'supplier_product_name' => '  Cloro proveedor  ',
    ])->assertOk()->assertJsonPath('data.supplier_code', 'QMI-CL500')->assertJsonPath('data.supplier_product_name', 'Cloro proveedor');

    $this->actingAs($this->user, 'sanctum')->putJson(supplierProduct($this->supplier->id, $association->id), [
        'supplier_code' => '',
        'supplier_product_name' => '',
    ])->assertOk()->assertJsonPath('data.supplier_code', null)->assertJsonPath('data.supplier_product_name', null);

    $this->actingAs($this->user, 'sanctum')->deleteJson(supplierProduct($this->supplier->id, $association->id))->assertOk();
    $this->assertDatabaseMissing('supplier_products', ['id' => $association->id]);
    expect(SupplierProduct::query()->where('supplier_id', $this->supplier->id)->count())->toBe(1);
});

test('rejects shared-stock children while allowing a non-sellable physical source', function () {
    $source = productForSupplier($this->store, $this->category, ['is_sellable' => false]);
    $child = productForSupplier($this->store, $this->category, [
        'stock' => 0,
        'stock_min' => 0,
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '1.0000',
    ]);

    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $source->id])->assertCreated();
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $child->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.product_id.0', 'Los productos con stock compartido no pueden asociarse como productos de compra. Seleccioná el producto que administra el stock físico.');
});

test('isolates associations across stores and rejects foreign supplier and product ids', function () {
    $purchases = Feature::query()->where('code', 'purchases')->firstOrFail();
    $otherPlan = Plan::factory()->create();
    $otherPlan->features()->attach($purchases->id);
    $otherStore = Store::factory()->create(['plan_id' => $otherPlan->id]);
    $otherCategory = Category::factory()->belongsToStore($otherStore)->create();
    $otherSupplier = Supplier::create(['store_id' => $otherStore->id, 'business_name' => 'Proveedor ajeno']);
    $otherProduct = productForSupplier($otherStore, $otherCategory);
    $localProduct = productForSupplier($this->store, $this->category);
    $foreignAssociation = SupplierProduct::create(['store_id' => $otherStore->id, 'supplier_id' => $otherSupplier->id, 'product_id' => $otherProduct->id]);

    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => $otherProduct->id])->assertUnprocessable()->assertJsonValidationErrors('product_id');
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/suppliers/{$otherSupplier->id}/products", ['product_id' => $localProduct->id])->assertNotFound();
    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$otherSupplier->id}/products")->assertNotFound();
    $this->actingAs($this->user, 'sanctum')->putJson(supplierProduct($this->supplier->id, $foreignAssociation->id), ['supplier_code' => 'NO'])->assertNotFound();
    $this->actingAs($this->user, 'sanctum')->deleteJson(supplierProduct($this->supplier->id, $foreignAssociation->id))->assertNotFound();
});

test('lists only associations for the requested supplier and store with search and pagination', function () {
    $product = productForSupplier($this->store, $this->category, ['name' => 'Cloro granel', 'sku' => 'CL-001']);
    $second = productForSupplier($this->store, $this->category, ['name' => 'Detergente', 'sku' => 'DET-001']);
    $third = productForSupplier($this->store, $this->category, ['name' => 'Jabón', 'sku' => 'JAB-001']);
    $otherSupplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Otro proveedor']);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $product->id, 'supplier_code' => 'CL-500']);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $second->id, 'supplier_product_name' => 'Detergente industrial']);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $third->id]);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $otherSupplier->id, 'product_id' => productForSupplier($this->store, $this->category)->id]);

    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products")
        ->assertOk()
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.items.0.product.id', $product->id);
    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products?search=CL-001")
        ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.product.id', $product->id);
    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products?search=CL-500")
        ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.product.id', $product->id);
    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products?search=industrial")
        ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.product.id', $second->id);
    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products?per_page=1&page=2")
        ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.total', 3)->assertJsonPath('data.current_page', 2);
});

test('requires supplier permissions and the purchases feature', function () {
    $product = productForSupplier($this->store, $this->category);
    $association = SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $product->id]);
    $viewOnly = User::factory()->create(['store_id' => $this->store->id]);
    $viewOnly->assignRole('STORE_ADMIN');
    $viewOnly->givePermissionTo('suppliers.view');
    $editOnly = User::factory()->create(['store_id' => $this->store->id]);
    $editOnly->assignRole('STORE_ADMIN');
    $editOnly->givePermissionTo('suppliers.edit');

    $this->actingAs($editOnly, 'sanctum')->getJson("/api/v1/store/suppliers/{$this->supplier->id}/products")->assertForbidden();
    $this->actingAs($viewOnly, 'sanctum')->postJson("/api/v1/store/suppliers/{$this->supplier->id}/products", ['product_id' => productForSupplier($this->store, $this->category)->id])->assertForbidden();
    $this->actingAs($viewOnly, 'sanctum')->putJson(supplierProduct($this->supplier->id, $association->id), ['supplier_code' => 'X'])->assertForbidden();
    $this->actingAs($viewOnly, 'sanctum')->deleteJson(supplierProduct($this->supplier->id, $association->id))->assertForbidden();

    $disabledStore = Store::factory()->create(['plan_id' => Plan::factory()->create()->id]);
    $disabledUser = User::factory()->create(['store_id' => $disabledStore->id]);
    $disabledUser->assignRole('STORE_ADMIN');
    $disabledUser->givePermissionTo(['suppliers.view', 'suppliers.edit']);
    $disabledSupplier = Supplier::create(['store_id' => $disabledStore->id, 'business_name' => 'Sin feature']);
    $this->actingAs($disabledUser, 'sanctum')->getJson("/api/v1/store/suppliers/{$disabledSupplier->id}/products")->assertForbidden();
    $this->actingAs($disabledUser, 'sanctum')->postJson("/api/v1/store/suppliers/{$disabledSupplier->id}/products")->assertForbidden();
});
