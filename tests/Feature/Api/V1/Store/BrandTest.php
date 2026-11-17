<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    foreach (['brands.view', 'brands.create', 'brands.edit', 'brands.delete'] as $name) {
        Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
    $inventory = Feature::factory()->create(['code' => 'inventory']);
    $plan = Plan::factory()->create();
    $plan->features()->attach($inventory->id);
    $this->store = Store::factory()->create(['plan_id' => $plan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['brands.view', 'brands.create', 'brands.edit', 'brands.delete']);
});

test('brands are store scoped, searchable and trimmed', function () {
    Brand::factory()->create(['store_id' => $this->store->id, 'name' => 'Bosch']);
    Brand::factory()->create(['name' => 'Makita']);
    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/brands?name=Bos')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.name', 'Bosch');
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/brands', ['name' => '  DeWalt  '])->assertCreated()->assertJsonPath('data.name', 'DeWalt');
});

test('brand CRUD validates uniqueness, permissions and protected deletion', function () {
    $brand = Brand::factory()->create(['store_id' => $this->store->id, 'name' => 'Bosch']);
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/brands', ['name' => 'Bosch'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/brands/{$brand->id}", ['name' => 'Makita', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    Product::factory()->forStore($this->store)->create(['category_id' => Category::factory()->belongsToStore($this->store), 'brand_id' => $brand->id]);
    $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/store/brands/{$brand->id}")->assertStatus(409);
    $noPermission = User::factory()->create(['store_id' => $this->store->id]);
    $noPermission->assignRole('STORE_ADMIN');
    $this->actingAs($noPermission, 'sanctum')->getJson('/api/v1/store/brands')->assertForbidden();
});

test('brands require inventory and reject identifiers from another store', function () {
    $withoutInventory = Store::factory()->create(['plan_id' => Plan::factory()->create()->id]);
    $disabledUser = User::factory()->create(['store_id' => $withoutInventory->id]);
    $disabledUser->assignRole('STORE_ADMIN');
    $disabledUser->givePermissionTo(['brands.view', 'brands.create', 'brands.edit', 'brands.delete']);
    $this->actingAs($disabledUser, 'sanctum')->getJson('/api/v1/store/brands')->assertForbidden();

    $otherPlan = Plan::factory()->create();
    $otherPlan->features()->attach(Feature::query()->where('code', 'inventory')->firstOrFail()->id);
    $otherBrand = Brand::factory()->create(['store_id' => Store::factory()->create(['plan_id' => $otherPlan->id])->id]);
    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/brands/{$otherBrand->id}")->assertNotFound();
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/brands/{$otherBrand->id}", ['name' => 'Ajena'])->assertNotFound();
    $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/store/brands/{$otherBrand->id}")->assertNotFound();
});

test('brand endpoints enforce each CRUD permission independently', function () {
    $brand = Brand::factory()->create(['store_id' => $this->store->id]);
    foreach ([
        ['brands.view', 'getJson', '/api/v1/store/brands', null],
        ['brands.create', 'postJson', '/api/v1/store/brands', ['name' => 'Nueva']],
        ['brands.edit', 'putJson', "/api/v1/store/brands/{$brand->id}", ['name' => 'Editada']],
        ['brands.delete', 'deleteJson', "/api/v1/store/brands/{$brand->id}", null],
    ] as [$permission, $method, $url, $payload]) {
        $user = User::factory()->create(['store_id' => $this->store->id]);
        $user->assignRole('STORE_ADMIN');
        $this->actingAs($user, 'sanctum')->{$method}($url, $payload ?? [])->assertForbidden();
    }
});

test('product editors can read operational brand options without brands view', function () {
    Permission::firstOrCreate(['name' => 'inventory.create', 'guard_name' => 'web']);
    Brand::factory()->create(['store_id' => $this->store->id, 'name' => 'Operativa']);
    $editor = User::factory()->create(['store_id' => $this->store->id]);
    $editor->assignRole('STORE_ADMIN');
    $editor->givePermissionTo('inventory.create');

    $this->actingAs($editor, 'sanctum')->getJson('/api/v1/store/products/brand-options')
        ->assertOk()
        ->assertJsonPath('data.items.0.name', 'Operativa')
        ->assertJsonMissingPath('data.items.0.created_at');
    $this->actingAs($editor, 'sanctum')->getJson('/api/v1/store/brands')->assertForbidden();
});
