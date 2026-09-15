<?php

declare(strict_types=1);

use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    $this->pos = Feature::firstOrCreate(['code' => 'pos'], ['name' => 'Punto de Venta']);
    $this->inventory = Feature::firstOrCreate(['code' => 'inventory'], ['name' => 'Gestión de Stock']);

    $this->posOnlyPlan = Plan::factory()->create();
    $this->posOnlyPlan->features()->sync([$this->pos->id]);
    $this->store = Store::factory()->create(['plan_id' => $this->posOnlyPlan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
});

function commercialCatalog(User $user, array $query): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($user, 'sanctum')
        ->getJson('/api/v1/store/operations/products/search?'.http_build_query($query));
}

describe('GET /api/v1/store/operations/products/search', function () {
    test('allows POS without Inventory to search active products by name and SKU', function () {
        Product::factory()->forStore($this->store)->create([
            'name' => 'Cinta Métrica',
            'sku' => 'CINTA-001',
            'barcode' => '7790000000001',
        ]);

        commercialCatalog($this->user, ['q' => 'Cinta'])
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'CINTA-001')
            ->assertJsonPath('data.0.barcode', '7790000000001');

        commercialCatalog($this->user, ['q' => '001'])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cinta Métrica');
    });

    test('searches exact barcodes and does not expose inactive or other-store products', function () {
        Product::factory()->forStore($this->store)->create([
            'name' => 'Producto activo',
            'barcode' => '7790000000002',
        ]);
        Product::factory()->forStore($this->store)->create([
            'name' => 'Producto inactivo',
            'barcode' => '7790000000003',
            'is_active' => false,
        ]);
        $otherStore = Store::factory()->create(['plan_id' => $this->posOnlyPlan->id]);
        Product::factory()->forStore($otherStore)->create([
            'name' => 'Producto ajeno',
            'barcode' => '7790000000004',
        ]);

        commercialCatalog($this->user, ['barcode' => '7790000000002'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Producto activo');
        commercialCatalog($this->user, ['barcode' => '7790000000003'])->assertOk()->assertJsonCount(0, 'data');
        commercialCatalog($this->user, ['barcode' => '7790000000004'])->assertOk()->assertJsonCount(0, 'data');
    });

    test('limits autocomplete results to ten and validates one search mode', function () {
        Product::factory()->count(11)->forStore($this->store)->create(['name' => 'Catálogo comercial']);

        commercialCatalog($this->user, ['q' => 'Catálogo'])->assertOk()->assertJsonCount(10, 'data');
        commercialCatalog($this->user, [])->assertStatus(422)->assertJsonValidationErrors(['q', 'barcode']);
        commercialCatalog($this->user, ['q' => 'Catálogo', 'barcode' => '7790000000001'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q', 'barcode']);
    });

    test('requires POS and leaves the Inventory search protected by Inventory', function () {
        $inventoryOnlyPlan = Plan::factory()->create();
        $inventoryOnlyPlan->features()->sync([$this->inventory->id]);
        $inventoryStore = Store::factory()->create(['plan_id' => $inventoryOnlyPlan->id]);
        $inventoryUser = User::factory()->create(['store_id' => $inventoryStore->id]);
        $inventoryUser->assignRole('STORE_ADMIN');

        commercialCatalog($inventoryUser, ['q' => 'Producto'])->assertForbidden();
        test()->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/store/products/search?q=Producto')
            ->assertForbidden();
    });
});
