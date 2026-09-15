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

function commercialProductDetail(User $user, string $productId): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($user, 'sanctum')
        ->getJson("/api/v1/store/operations/products/{$productId}");
}

describe('GET /api/v1/store/operations/products/{id}', function () {
    test('allows POS without Inventory to obtain only commercial product data', function () {
        $product = Product::factory()->forStore($this->store)->create([
            'name' => 'Cinta Métrica',
            'sku' => 'CINTA-001',
            'barcode' => '7790000000005',
            'price' => 7500,
            'cost' => 3100,
            'stock' => 10,
            'stock_reserved' => 4,
        ]);

        $response = commercialProductDetail($this->user, $product->id)
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Cinta Métrica')
            ->assertJsonPath('data.sku', 'CINTA-001')
            ->assertJsonPath('data.barcode', '7790000000005')
            ->assertJsonPath('data.price', 7500)
            ->assertJsonPath('data.available_stock', 6);

        expect($response->json('data'))->toHaveKeys([
            'id',
            'name',
            'sku',
            'barcode',
            'price',
            'available_stock',
        ])->not->toHaveKeys([
            'cost',
            'stock',
            'stock_reserved',
            'stock_min',
            'category',
            'created_at',
            'updated_at',
        ]);
    });

    test('does not return a negative available stock', function () {
        $product = Product::factory()->forStore($this->store)->create([
            'stock' => 3,
            'stock_reserved' => 5,
        ]);

        commercialProductDetail($this->user, $product->id)
            ->assertOk()
            ->assertJsonPath('data.available_stock', 0);
    });

    test('does not expose inactive or products from another store', function () {
        $inactive = Product::factory()->forStore($this->store)->create(['is_active' => false]);
        $otherStore = Store::factory()->create(['plan_id' => $this->posOnlyPlan->id]);
        $otherStoreProduct = Product::factory()->forStore($otherStore)->create();

        commercialProductDetail($this->user, $inactive->id)->assertNotFound();
        commercialProductDetail($this->user, $otherStoreProduct->id)->assertNotFound();
    });

    test('requires POS and does not change the existing product detail endpoint', function () {
        $inventoryOnlyPlan = Plan::factory()->create();
        $inventoryOnlyPlan->features()->sync([$this->inventory->id]);
        $inventoryStore = Store::factory()->create(['plan_id' => $inventoryOnlyPlan->id]);
        $inventoryUser = User::factory()->create(['store_id' => $inventoryStore->id]);
        $inventoryUser->assignRole('STORE_ADMIN');
        $product = Product::factory()->forStore($this->store)->create(['cost' => 125]);

        commercialProductDetail($inventoryUser, $product->id)->assertForbidden();
        test()->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/store/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.cost', 125);
    });
});
