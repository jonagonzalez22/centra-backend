<?php

use App\Models\Category;
use App\Models\CommercialOperation;
use App\Models\InventoryMovement;
use App\Models\MeasurementUnit;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->category = Category::factory()->belongsToStore($this->store)->create();
});

test('the global measurement unit catalog contains the initial active units', function () {
    $units = MeasurementUnit::query()->orderBy('code')->get();

    expect($units->pluck('code')->all())->toBe(['kg', 'l', 'm', 'unit'])
        ->and($units->every(fn (MeasurementUnit $unit) => $unit->is_active))->toBeTrue()
        ->and(Schema::hasColumn('measurement_units', 'store_id'))->toBeFalse();
});

test('measurement unit codes are globally unique', function () {
    expect(fn () => MeasurementUnit::query()->create([
        'code' => 'unit',
        'name' => 'Otra unidad',
        'symbol' => 'ou',
        'category' => 'unit',
        'is_active' => true,
    ]))->toThrow(QueryException::class);
});

test('measurement units endpoint requires authentication and returns only active global units', function () {
    MeasurementUnit::query()->where('code', 'm')->update(['is_active' => false]);

    $this->getJson('/api/v1/store/measurement-units')->assertUnauthorized();

    $response = $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/measurement-units')
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect(collect($response->json('data'))->pluck('code')->all())
        ->toBe(['kg', 'l', 'unit']);
});

test('a product persists an explicit active measurement unit and decimal sale step', function () {
    $unit = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', [
            'name' => 'Cemento a granel',
            'sku' => 'CEM-KG-001',
            'category_id' => $this->category->id,
            'price' => 1000,
            'stock' => '10.0000',
            'stock_min' => '1.0000',
            'stock_measurement_unit_id' => $unit->id,
            'sale_quantity_step' => '0.25',
        ])
        ->assertCreated()
        ->assertJsonPath('data.stock_measurement_unit_id', $unit->id)
        ->assertJsonPath('data.stock_measurement_unit.code', 'kg')
        ->assertJsonPath('data.stock_measurement_unit.symbol', 'kg')
        ->assertJsonPath('data.sale_quantity_step', '0.2500')
        ->assertJsonPath('data.stock', '10.0000');

    $product = Product::query()->where('sku', 'CEM-KG-001')->firstOrFail();
    expect($product->stock_measurement_unit_id)->toBe($unit->id)
        ->and($product->sale_quantity_step)->toBe('0.2500');
});

test('product creation without UOM fields receives compatible defaults', function () {
    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', [
            'name' => 'Martillo',
            'sku' => 'MAR-001',
            'category_id' => $this->category->id,
            'price' => 5000,
            'stock' => '10.0000',
            'stock_min' => '1.0000',
        ])
        ->assertCreated()
        ->assertJsonPath('data.stock_measurement_unit.code', 'unit')
        ->assertJsonPath('data.sale_quantity_step', '1.0000')
        ->assertJsonPath('data.stock', '10.0000');
});

test('product update persists UOM and sale step without applying step business rules yet', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '0.0000',
        'stock_reserved' => '0.0000',
        'stock_min' => '5.0000',
    ]);
    $unit = MeasurementUnit::query()->where('code', 'l')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $unit->id,
            'sale_quantity_step' => '0.1000',
        ])
        ->assertOk()
        ->assertJsonPath('data.stock_measurement_unit.code', 'l')
        ->assertJsonPath('data.sale_quantity_step', '0.1000')
        ->assertJsonPath('data.stock', '0.0000')
        ->assertJsonPath('data.stock_reserved', '0.0000')
        ->assertJsonPath('data.stock_min', '5.0000');
});

test('a product UOM cannot reinterpret existing stock, reservations, or history', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '10.0000',
    ]);
    $unit = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $unit->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_measurement_unit_id']);

    expect($product->fresh()->stock_measurement_unit_id)
        ->toBe(MeasurementUnit::query()->where('code', 'unit')->value('id'));
});

test('a product UOM cannot change after an inventory movement even when current stock is zero', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '0.0000',
        'stock_reserved' => '0.0000',
        'stock_min' => '5.0000',
    ]);
    InventoryMovement::query()->create([
        'store_id' => $this->store->id,
        'product_id' => $product->id,
        'user_id' => $this->user->id,
        'type' => 'adjustment',
        'quantity' => '1.0000',
        'previous_stock' => '0.0000',
        'current_stock' => '1.0000',
        'concept' => 'Movimiento histórico',
    ]);
    $unit = MeasurementUnit::query()->where('code', 'l')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $unit->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_measurement_unit_id']);
});

test('a product UOM cannot change after commercial history even when current stock is zero', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '0.0000',
        'stock_reserved' => '0.0000',
        'stock_min' => '5.0000',
    ]);
    $operation = CommercialOperation::factory()->forStore($this->store)->create([
        'user_id' => $this->user->id,
        'type' => 'sale',
        'status' => 'confirmed',
    ]);
    OperationItem::factory()->forOperation($operation)->forProduct($product)->create([
        'quantity' => '1.0000',
    ]);
    $unit = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $unit->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_measurement_unit_id']);
});

test('a product cannot change its sale step while it has an active reservation', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '10.0000',
        'stock_reserved' => '1.0000',
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'sale_quantity_step' => '0.5000',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sale_quantity_step']);
});

test('product requests reject inactive UOMs and non-positive sale steps', function () {
    $inactiveUnit = MeasurementUnit::query()->where('code', 'm')->firstOrFail();
    $inactiveUnit->update(['is_active' => false]);

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', [
            'name' => 'Cable',
            'sku' => 'CAB-001',
            'category_id' => $this->category->id,
            'price' => 2000,
            'stock_measurement_unit_id' => $inactiveUnit->id,
            'sale_quantity_step' => '0',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_measurement_unit_id', 'sale_quantity_step']);
});
