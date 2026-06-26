<?php

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_USER', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();
    $this->category = Category::factory()->belongsToStore($this->store)->create();
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

function createProductForStore(Store $store, Category $category, int $stock = 10): Product
{
    return Product::factory()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'stock' => $stock,
    ]);
}

function adjustInventory(array $data): \Illuminate\Testing\TestResponse
{
    $response = test()->withHeader('Authorization', 'Bearer '.test()->token)
        ->postJson('/api/v1/store/inventory/adjust', $data);

    return $response;
}

describe('POST /api/v1/store/inventory/adjust', function () {
    test('input type with positive quantity succeeds and increases stock', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        $previousStock = $product->stock;

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => 5,
            'type' => 'input',
            'concept' => 'Entrada por compra',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $product->refresh();
        expect($product->stock)->toBe($previousStock + 5);

        $movement = InventoryMovement::latest()->first();
        expect($movement->quantity)->toBe(5)
            ->and($movement->previous_stock)->toBe($previousStock)
            ->and($movement->current_stock)->toBe($product->stock)
            ->and($movement->type)->toBe('input');
    });

    test('input type with zero quantity returns 422', function () {
        $product = createProductForStore($this->store, $this->category, 10);

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => 0,
            'type' => 'input',
            'concept' => 'Entrada inválida',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Para entradas, la cantidad debe ser mayor a cero.');
    });

    test('input type with negative quantity returns 422', function () {
        $product = createProductForStore($this->store, $this->category, 10);

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => -5,
            'type' => 'input',
            'concept' => 'Entrada inválida',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Para entradas, la cantidad debe ser mayor a cero.');
    });

    test('output type with positive quantity succeeds and stores negative value', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        $previousStock = $product->stock;

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => 3,
            'type' => 'output',
            'concept' => 'Venta',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $product->refresh();
        expect($product->stock)->toBe($previousStock - 3);

        $movement = InventoryMovement::latest()->first();
        expect($movement->quantity)->toBe(-3)
            ->and($movement->previous_stock)->toBe($previousStock)
            ->and($movement->current_stock)->toBe($product->stock)
            ->and($movement->type)->toBe('output');
    });

    test('output type with already negative quantity stores correctly (double negative)', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        $previousStock = $product->stock;

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => -3,
            'type' => 'output',
            'concept' => 'Venta',
        ]);

        $response->assertStatus(201);

        $product->refresh();
        expect($product->stock)->toBe($previousStock - 3);

        $movement = InventoryMovement::latest()->first();
        expect($movement->quantity)->toBe(-3);
    });

    test('output type that would result in negative stock returns 422', function () {
        $product = createProductForStore($this->store, $this->category, 5);

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => 10,
            'type' => 'output',
            'concept' => 'Venta mayor al stock',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El stock resultante no puede ser negativo.');

        $product->refresh();
        expect($product->stock)->toBe(5);
    });

    test('adjustment type with positive quantity succeeds', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        $previousStock = $product->stock;

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => 5,
            'type' => 'adjustment',
            'concept' => 'Ajuste positivo por inventario',
        ]);

        $response->assertStatus(201);

        $product->refresh();
        expect($product->stock)->toBe($previousStock + 5);

        $movement = InventoryMovement::latest()->first();
        expect($movement->quantity)->toBe(5)
            ->and($movement->type)->toBe('adjustment');
    });

    test('adjustment type with negative quantity succeeds and decreases stock', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        $previousStock = $product->stock;

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => -3,
            'type' => 'adjustment',
            'concept' => 'Ajuste negativo por merma',
        ]);

        $response->assertStatus(201);

        $product->refresh();
        expect($product->stock)->toBe($previousStock - 3);

        $movement = InventoryMovement::latest()->first();
        expect($movement->quantity)->toBe(-3)
            ->and($movement->type)->toBe('adjustment');
    });

    test('adjustment type that would result in negative stock returns 422', function () {
        $product = createProductForStore($this->store, $this->category, 5);

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => -10,
            'type' => 'adjustment',
            'concept' => 'Ajuste negativo mayor al stock',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'El stock resultante no puede ser negativo.');
    });

    test('concept is required', function () {
        $product = createProductForStore($this->store, $this->category, 10);

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => 5,
            'type' => 'input',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['concept']);
    });

    test('invalid type returns 422', function () {
        $product = createProductForStore($this->store, $this->category, 10);

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => 5,
            'type' => 'invalid_type',
            'concept' => 'Prueba',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    });

    test('product from different store returns 422', function () {
        $otherStore = Store::factory()->create();
        $otherCategory = Category::factory()->belongsToStore($otherStore)->create();
        $otherProduct = Product::factory()->create([
            'store_id' => $otherStore->id,
            'category_id' => $otherCategory->id,
        ]);

        $response = adjustInventory([
            'product_id' => $otherProduct->id,
            'quantity' => 5,
            'type' => 'input',
            'concept' => 'Prueba',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['product_id']);
    });

    test('unauthenticated request returns 401', function () {
        $product = createProductForStore($this->store, $this->category, 10);

        $response = $this->postJson('/api/v1/store/inventory/adjust', [
            'product_id' => $product->id,
            'quantity' => 5,
            'type' => 'input',
            'concept' => 'Prueba',
        ]);

        $response->assertStatus(401);
    });

    test('quantity must be integer', function () {
        $product = createProductForStore($this->store, $this->category, 10);

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => 'abc',
            'type' => 'input',
            'concept' => 'Prueba',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);
    });
});
