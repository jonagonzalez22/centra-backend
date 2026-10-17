<?php

use App\Models\Category;
use App\Models\CommercialOperation;
use App\Models\DeliveryRoute;
use App\Models\InventoryMovement;
use App\Models\MeasurementUnit;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
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
        ->and($product->sale_quantity_step)->toBe('0.2500')
        ->and($product->stock_reserved)->toBe('0.0000');
});

test('product creation rejects manually supplied reserved stock', function () {
    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', [
            'name' => 'Producto con reserva manual',
            'sku' => 'RES-001',
            'category_id' => $this->category->id,
            'price' => 100,
            'stock_reserved' => '100.0000',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_reserved'])
        ->assertJsonPath('errors.stock_reserved.0', 'El stock reservado no puede modificarse manualmente.');

    expect(Product::query()->where('sku', 'RES-001')->exists())->toBeFalse();
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

test('product update rejects manually setting reserved stock to zero', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '10.0000',
        'stock_reserved' => '3.0000',
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_reserved' => '0.0000',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_reserved'])
        ->assertJsonPath('errors.stock_reserved.0', 'El stock reservado no puede modificarse manualmente.');

    expect($product->fresh()->stock_reserved)->toBe('3.0000');
});

test('product update rejects manually setting reserved stock to a positive value', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '10.0000',
        'stock_reserved' => '3.0000',
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_reserved' => '100.0000',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_reserved'])
        ->assertJsonPath('errors.stock_reserved.0', 'El stock reservado no puede modificarse manualmente.');

    expect($product->fresh()->stock_reserved)->toBe('3.0000');
});

test('product update without reserved stock continues to work', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '10.0000',
        'stock_reserved' => '3.0000',
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'name' => 'Producto actualizado',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Producto actualizado')
        ->assertJsonPath('data.stock_reserved', '3.0000');

    expect($product->fresh()->stock_reserved)->toBe('3.0000');
});

test('product update rejects direct stock changes for an associated product', function () {
    $source = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '10.0000',
    ]);
    $child = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '0.0000',
        'stock_reserved' => '0.0000',
        'stock_min' => '0.0000',
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '5.0000',
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$child->id}", [
            'stock' => '1.0000',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock'])
        ->assertJsonPath('errors.stock.0', 'El stock de este producto se administra desde su producto fuente.');

    expect($child->fresh()->stock)->toBe('0.0000');
});

test('a normal source may serve multiple children but cannot become a child itself', function () {
    $source = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '0.0000',
        'stock_reserved' => '0.0000',
        'stock_min' => '0.0000',
    ]);
    $otherSource = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '0.0000',
        'stock_reserved' => '0.0000',
        'stock_min' => '0.0000',
    ]);

    $childPayload = fn (string $name, string $sku): array => [
        'name' => $name,
        'sku' => $sku,
        'category_id' => $this->category->id,
        'price' => 100,
        'stock' => '0.0000',
        'stock_min' => '0.0000',
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '1.0000',
    ];

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', $childPayload('Child A', 'CHILD-A'))
        ->assertCreated();
    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', $childPayload('Child B', 'CHILD-B'))
        ->assertCreated();
    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/store/products', $childPayload('Child C', 'CHILD-C'))
        ->assertCreated();

    expect($source->stockDependentProducts()->count())->toBe(3);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$source->id}", [
            'stock_source_product_id' => $otherSource->id,
            'stock_consumption_quantity' => '1.0000',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_source_product_id'])
        ->assertJsonPath('errors.stock_source_product_id.0', 'No se puede usar stock de otro producto porque este producto ya es fuente de stock de otros productos.');

    expect($source->fresh()->stock_source_product_id)->toBeNull();
});

test('a product UOM can change with physical stock and preserves stock and stock minimum', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '10.0000',
        'stock_min' => '3.0000',
    ]);
    $unit = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $unit->id,
            'sale_quantity_step' => '0.2500',
        ])
        ->assertOk()
        ->assertJsonPath('data.stock_measurement_unit.code', 'kg')
        ->assertJsonPath('data.sale_quantity_step', '0.2500')
        ->assertJsonPath('data.stock', '10.0000')
        ->assertJsonPath('data.stock_min', '3.0000');

    expect($product->fresh()->stock_measurement_unit_id)->toBe($unit->id)
        ->and($product->fresh()->stock)->toBe('10.0000')
        ->and($product->fresh()->stock_min)->toBe('3.0000');
});

test('a product UOM can change after an inventory movement without commitments', function () {
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
            'sale_quantity_step' => '0.5000',
        ])
        ->assertOk()
        ->assertJsonPath('data.stock_measurement_unit.code', 'l')
        ->assertJsonPath('data.sale_quantity_step', '0.5000');
});

test('a product UOM can change after complete B6 commercial history without commitments', function () {
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
        'measurement_unit_code' => 'unit',
        'measurement_unit_symbol' => 'u',
        'sale_quantity_step' => '1.0000',
    ]);
    $unit = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $unit->id,
            'sale_quantity_step' => '0.2500',
        ])
        ->assertOk()
        ->assertJsonPath('data.stock_measurement_unit.code', 'kg');
});

test('a product UOM can change after legacy commercial history without commitments', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '0.0000',
        'stock_reserved' => '0.0000',
    ]);
    $operation = CommercialOperation::factory()->forStore($this->store)->create([
        'user_id' => $this->user->id,
        'type' => 'sale',
        'status' => 'confirmed',
    ]);
    OperationItem::factory()->forOperation($operation)->forProduct($product)->create([
        'quantity' => '1.0000',
    ]);
    $unit = MeasurementUnit::query()->where('code', 'l')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $unit->id,
            'sale_quantity_step' => '0.5000',
        ])
        ->assertOk()
        ->assertJsonPath('data.stock_measurement_unit.code', 'l');
});

test('a product UOM can change after a completed route stop without commitments', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '0.0000',
        'stock_reserved' => '0.0000',
    ]);
    $order = CommercialOperation::factory()->forStore($this->store)->create([
        'user_id' => $this->user->id,
        'type' => 'order',
        'status' => 'delivered',
    ]);
    $route = DeliveryRoute::factory()->forStore($this->store)->create(['status' => 'completed']);
    $stop = RouteStop::factory()->create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'status' => 'completed',
    ]);
    RouteStopItem::factory()->create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
        'quantity_planned' => '1.0000',
        'quantity_loaded' => '1.0000',
        'quantity_delivered' => '1.0000',
    ]);
    $unit = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $unit->id,
            'sale_quantity_step' => '0.2500',
        ])
        ->assertOk()
        ->assertJsonPath('data.stock_measurement_unit.code', 'kg');
});

test('a product UOM change requires an explicit sale quantity step', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock_reserved' => '0.0000',
    ]);
    $unit = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $unit->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sale_quantity_step'])
        ->assertJsonPath(
            'errors.sale_quantity_step.0',
            'Al cambiar la unidad de medida se debe informar también la cantidad mínima de venta.'
        );

    expect($product->fresh()->stock_measurement_unit_id)
        ->toBe(MeasurementUnit::query()->where('code', 'unit')->value('id'));
});

test('a product UOM change is blocked by reserved stock', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '10.0000',
        'stock_reserved' => '1.0000',
    ]);
    $unit = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $unit->id,
            'sale_quantity_step' => '0.2500',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_measurement_unit_id'])
        ->assertJsonPath(
            'message',
            'No se puede cambiar la unidad de medida mientras el producto tenga cantidades reservadas o compromisos activos.'
        );
});

test('active orders block a product UOM change even with inconsistent zero reserved stock', function (string $status) {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '10.0000',
        'stock_reserved' => '0.0000',
    ]);
    $order = CommercialOperation::factory()->forStore($this->store)->create([
        'user_id' => $this->user->id,
        'type' => 'order',
        'status' => $status,
    ]);
    OperationItem::factory()->forOperation($order)->forProduct($product)->create();
    $unit = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $unit->id,
            'sale_quantity_step' => '0.2500',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_measurement_unit_id']);
})->with(['open', 'confirmed', 'partially_delivered']);

test('active routes block a product UOM change even with inconsistent zero reserved stock', function (string $status) {
    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock' => '10.0000',
        'stock_reserved' => '0.0000',
    ]);
    $route = DeliveryRoute::factory()->forStore($this->store)->create(['status' => $status]);
    $stop = RouteStop::factory()->create(['route_id' => $route->id]);
    RouteStopItem::factory()->create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
    ]);
    $unit = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $unit->id,
            'sale_quantity_step' => '0.2500',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_measurement_unit_id']);
})->with(['draft', 'planned', 'loaded', 'dispatched', 'awaiting_reconciliation']);

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

    $product = Product::factory()->forStore($this->store)->create([
        'category_id' => $this->category->id,
        'stock_reserved' => '0.0000',
    ]);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/products/{$product->id}", [
            'stock_measurement_unit_id' => $inactiveUnit->id,
            'sale_quantity_step' => '1.0000',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['stock_measurement_unit_id']);
});
