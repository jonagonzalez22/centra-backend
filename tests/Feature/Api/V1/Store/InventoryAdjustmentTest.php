<?php

use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\InventoryValuationBalance;
use App\Models\InventoryValuationEntry;
use App\Models\MeasurementUnit;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\QuantityMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_USER', 'guard_name' => 'web']);
    Permission::create(['name' => 'inventory.adjust', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();
    $this->category = Category::factory()->belongsToStore($this->store)->create();
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo('inventory.adjust');
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
    test('movements list exposes the product stock measurement unit', function () {
        $measurementUnit = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();
        $product = createProductForStore($this->store, $this->category, 10);
        $product->update(['stock_measurement_unit_id' => $measurementUnit->id]);

        adjustInventory([
            'product_id' => $product->id,
            'quantity' => '2.5000',
            'type' => 'input',
            'concept' => 'Ingreso en kilogramos',
        ])->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/store/inventory/movements')
            ->assertOk()
            ->assertJsonPath('data.items.0.product.stock_measurement_unit.code', 'kg')
            ->assertJsonPath('data.items.0.product.stock_measurement_unit.symbol', 'kg');
    });

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
        expect($product->stock)->toBe(QuantityMath::add($previousStock, 5));

        $movement = InventoryMovement::latest()->first();
        expect($movement->quantity)->toBe('5.0000')
            ->and($movement->previous_stock)->toBe($previousStock)
            ->and($movement->current_stock)->toBe($product->stock)
            ->and($movement->type)->toBe('input');
    });

    test('manual input with an explicit cost records known valuation and weighted average', function () {
        $product = createProductForStore($this->store, $this->category, 50);
        app(\App\Services\InventoryValuationService::class)->recordInitialStock($product, '1000.00', $this->user);

        adjustInventory([
            'product_id' => $product->id,
            'quantity' => '20.0000',
            'type' => 'input',
            'concept' => 'Ingreso manual valorizado',
            'unit_cost' => '1500.00',
        ])->assertCreated();

        $entry = InventoryValuationEntry::where('type', 'manual_adjustment')->latest()->firstOrFail();
        $balance = InventoryValuationBalance::where('stock_source_product_id', $product->id)->sole();

        expect($product->fresh()->stock)->toBe('70.0000')
            ->and($product->fresh()->cost)->not->toBe('1500.00')
            ->and($entry->quantity)->toBe('20.0000')
            ->and($entry->unit_cost)->toBe('1500.000000')
            ->and($entry->total_cost)->toBe('30000.000000')
            ->and($entry->cost_status)->toBe('known')
            ->and($balance->quantity)->toBe('70.0000')
            ->and($balance->known_quantity)->toBe('70.0000')
            ->and($balance->unknown_quantity)->toBe('0.0000')
            ->and($balance->total_value)->toBe('80000.000000')
            ->and($balance->average_unit_cost)->toBe('1142.857142')
            ->and($balance->cost_status)->toBe('known');
    });

    test('manual input without a cost remains unknown valuation', function () {
        $product = createProductForStore($this->store, $this->category, 50);
        app(\App\Services\InventoryValuationService::class)->recordInitialStock($product, '1000.00', $this->user);

        adjustInventory([
            'product_id' => $product->id,
            'quantity' => '20.0000',
            'type' => 'input',
            'concept' => 'Ingreso manual sin costo',
        ])->assertCreated();

        $entry = InventoryValuationEntry::where('type', 'manual_adjustment')->latest()->firstOrFail();
        $balance = InventoryValuationBalance::where('stock_source_product_id', $product->id)->sole();

        expect($entry->unit_cost)->toBeNull()
            ->and($entry->total_cost)->toBeNull()
            ->and($entry->cost_status)->toBe('unknown')
            ->and($balance->known_quantity)->toBe('50.0000')
            ->and($balance->unknown_quantity)->toBe('20.0000')
            ->and($balance->total_value)->toBe('50000.000000')
            ->and($balance->average_unit_cost)->toBe('1000.000000')
            ->and($balance->cost_status)->toBe('mixed');
    });

    test('zero is a known manual cost and can update the product reference cost', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        $product->update(['cost' => '100.00']);

        adjustInventory([
            'product_id' => $product->id,
            'quantity' => '2.5000',
            'type' => 'input',
            'concept' => 'Bonificación conocida',
            'unit_cost' => '0.00',
            'update_product_cost' => true,
        ])->assertCreated();

        $entry = InventoryValuationEntry::where('type', 'manual_adjustment')->latest()->firstOrFail();
        expect($entry->cost_status)->toBe('known')
            ->and($entry->unit_cost)->toBe('0.000000')
            ->and($entry->total_cost)->toBe('0.000000')
            ->and($product->fresh()->cost)->toBe('0.00');
    });

    test('manual input only updates the product reference cost when requested', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        $product->update(['cost' => '100.00']);

        adjustInventory([
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'input',
            'concept' => 'Costo conocido sin actualizar referencia',
            'unit_cost' => '99.50',
            'update_product_cost' => false,
        ])->assertCreated();

        expect($product->fresh()->cost)->toBe('100.00');
    });

    test('a lower decimal manual cost is recorded as known', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        app(\App\Services\InventoryValuationService::class)->recordInitialStock($product, '1000.00', $this->user);

        adjustInventory([
            'product_id' => $product->id,
            'quantity' => '1.2500',
            'type' => 'input',
            'concept' => 'Corrección de costo menor',
            'unit_cost' => '250.50',
        ])->assertCreated();

        expect(InventoryValuationEntry::where('type', 'manual_adjustment')->latest()->firstOrFail()->unit_cost)
            ->toBe('250.500000');
    });

    test('product cost update requires an explicit unit cost', function () {
        $product = createProductForStore($this->store, $this->category, 10);

        adjustInventory([
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'input',
            'concept' => 'Actualización inválida',
            'update_product_cost' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_cost']);
    });

    test('output rejects unit cost fields and does not create a movement', function () {
        $product = createProductForStore($this->store, $this->category, 10);

        adjustInventory([
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'output',
            'concept' => 'Salida sin costo manual',
            'unit_cost' => '10.00',
            'update_product_cost' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unit_cost', 'update_product_cost']);

        expect($product->fresh()->stock)->toBe('10.0000')
            ->and(InventoryMovement::count())->toBe(0);
    });

    test('valuation failure rolls back stock movement and optional product cost update', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        $product->update(['cost' => '100.00']);
        $this->mock(\App\Services\InventoryValuationService::class, function ($mock): void {
            $mock->shouldReceive('recordManualInput')->once()->andThrow(new \RuntimeException('forced valuation failure'));
        });
        $this->withoutExceptionHandling();

        expect(fn () => adjustInventory([
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'input',
            'concept' => 'Prueba rollback',
            'unit_cost' => '50.00',
            'update_product_cost' => true,
        ]))->toThrow(\RuntimeException::class, 'forced valuation failure');

        expect($product->fresh()->stock)->toBe('10.0000')
            ->and($product->fresh()->cost)->toBe('100.00')
            ->and(InventoryMovement::count())->toBe(0)
            ->and(InventoryValuationEntry::count())->toBe(0);
    });

    test('physical adjustment is not restricted by the commercial sale step', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        $product->update(['sale_quantity_step' => '0.5000']);

        adjustInventory([
            'product_id' => $product->id,
            'quantity' => '0.1250',
            'type' => 'input',
            'concept' => 'Ajuste físico fraccionario',
        ])->assertCreated();

        expect($product->fresh()->stock)->toBe('10.1250');
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
            ->assertJsonPath('message', 'Error de validación.')
            ->assertJsonPath('errors.quantity.0', 'La cantidad debe ser mayor a cero.');
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
            ->assertJsonPath('message', 'Error de validación.')
            ->assertJsonPath('errors.quantity.0', 'La cantidad debe ser mayor a cero.');
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
        expect($product->stock)->toBe(QuantityMath::subtract($previousStock, 3));

        $movement = InventoryMovement::latest()->first();
        expect($movement->quantity)->toBe('-3.0000')
            ->and($movement->previous_stock)->toBe($previousStock)
            ->and($movement->current_stock)->toBe($product->stock)
            ->and($movement->type)->toBe('output');
    });

    test('output type rejects a negative quantity at request validation', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        $previousStock = $product->stock;

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => -3,
            'type' => 'output',
            'concept' => 'Venta',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Error de validación.')
            ->assertJsonPath('errors.quantity.0', 'La cantidad debe ser mayor a cero.');

        expect($product->fresh()->stock)->toBe($previousStock)
            ->and(InventoryMovement::count())->toBe(0);
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
        expect($product->stock)->toBe('5.0000');
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
        expect($product->stock)->toBe(QuantityMath::add($previousStock, 5));

        $movement = InventoryMovement::latest()->first();
        expect($movement->quantity)->toBe('5.0000')
            ->and($movement->type)->toBe('adjustment');
    });

    test('adjustment type rejects a negative quantity at request validation', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        $previousStock = $product->stock;

        $response = adjustInventory([
            'product_id' => $product->id,
            'quantity' => -3,
            'type' => 'adjustment',
            'concept' => 'Ajuste negativo por merma',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Error de validación.')
            ->assertJsonPath('errors.quantity.0', 'La cantidad debe ser mayor a cero.');

        expect($product->fresh()->stock)->toBe($previousStock)
            ->and(InventoryMovement::count())->toBe(0);
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
            ->assertJsonPath('message', 'Error de validación.')
            ->assertJsonPath('errors.quantity.0', 'La cantidad debe ser mayor a cero.');
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

        expect($otherProduct->fresh()->stock)->toBe($otherProduct->stock)
            ->and(InventoryMovement::count())->toBe(0);
    });

    test('user without inventory adjustment permission is forbidden', function () {
        $product = createProductForStore($this->store, $this->category, 10);
        $this->user->revokePermissionTo('inventory.adjust');

        adjustInventory([
            'product_id' => $product->id,
            'quantity' => 1,
            'type' => 'input',
            'concept' => 'Intento no autorizado',
        ])->assertForbidden();

        expect($product->fresh()->stock)->toBe('10.0000')
            ->and(InventoryMovement::count())->toBe(0);
    });

    test('associated product rejects manual inventory adjustment while its source remains adjustable', function () {
        $source = createProductForStore($this->store, $this->category, 10);
        $child = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'stock' => '0.0000',
            'stock_reserved' => '0.0000',
            'stock_min' => '0.0000',
            'stock_source_product_id' => $source->id,
            'stock_consumption_quantity' => '5.0000',
        ]);

        adjustInventory([
            'product_id' => $child->id,
            'quantity' => '1.0000',
            'type' => 'input',
            'concept' => 'Ajuste inválido de child',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'El stock de este producto se administra desde su producto fuente.');

        adjustInventory([
            'product_id' => $source->id,
            'quantity' => '1.0000',
            'type' => 'input',
            'concept' => 'Ajuste válido de source',
        ])->assertCreated();

        expect($child->fresh()->stock)->toBe('0.0000')
            ->and($source->fresh()->stock)->toBe('11.0000');
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
