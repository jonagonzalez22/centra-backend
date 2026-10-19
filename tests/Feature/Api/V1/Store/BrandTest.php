<?php

use App\Models\Brand;
use App\Models\Category;
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
    $this->store = Store::factory()->create();
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
