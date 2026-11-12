<?php

declare(strict_types=1);

use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\MeasurementUnit;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\CancelSaleService;
use App\Services\CommercialOperationService;
use App\Services\OrderItemEditService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
    $this->kilogram = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();
});

function snapshotProduct(object $test, array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'store_id' => $test->store->id,
        'stock_measurement_unit_id' => $test->kilogram->id,
        'sale_quantity_step' => '0.2500',
        'stock' => '20.0000',
        'stock_reserved' => '0.0000',
    ], $attributes));
}

function createSnapshotOperation(object $test, string $type, Product $product, string $quantity): CommercialOperation
{
    return app(CommercialOperationService::class)->create([
        'type' => $type,
        'customer_id' => $test->customer->id,
        'requested_delivery_date' => $type === 'order' ? now()->addDay()->toDateString() : null,
        'items' => [[
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->price,
        ]],
        'payments' => [],
    ], $test->store->id, $test->user->id);
}

function expectCommercialSnapshot(OperationItem $item, string $step = '0.2500'): void
{
    expect($item)
        ->measurement_unit_code->toBe('kg')
        ->measurement_unit_symbol->toBe('kg')
        ->sale_quantity_step->toBe($step);
}

test('captures the commercial UOM snapshot for an initial sale', function () {
    $product = snapshotProduct($this);
    $sale = createSnapshotOperation($this, 'sale', $product, '1.2500');

    expectCommercialSnapshot($sale->items()->sole());
});

test('freezes the normal SKU commercial cost for a POS sale and uses the new cost for a later sale', function () {
    $product = snapshotProduct($this, ['cost' => '200.00', 'price' => '600.00']);

    $firstSale = createSnapshotOperation($this, 'sale', $product, '2.0000');
    $product->update(['cost' => '250.00']);
    $secondSale = createSnapshotOperation($this, 'sale', $product->fresh(), '1.0000');

    expect($firstSale->items()->sole()->unit_cost_snapshot)->toBe('200.00')
        ->and($secondSale->items()->sole()->unit_cost_snapshot)->toBe('250.00');
});

test('freezes the commercial child SKU cost, not the shared physical source cost, for a POS sale', function () {
    $source = snapshotProduct($this, ['cost' => '30000.00', 'stock' => '20.0000']);
    $child = snapshotProduct($this, [
        'cost' => '1000.00',
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '5.0000',
        'stock' => '0.0000',
    ]);

    $sale = createSnapshotOperation($this, 'sale', $child, '1.0000');

    expect($sale->items()->sole()->unit_cost_snapshot)->toBe('1000.00');
});

test('preserves null and explicit zero as distinct POS commercial cost snapshots', function () {
    $withoutCost = snapshotProduct($this, ['cost' => null]);
    $zeroCost = snapshotProduct($this, ['cost' => '0.00']);

    $nullSale = createSnapshotOperation($this, 'sale', $withoutCost, '1.0000');
    $zeroSale = createSnapshotOperation($this, 'sale', $zeroCost, '1.0000');

    expect($nullSale->items()->sole()->unit_cost_snapshot)->toBeNull()
        ->and($zeroSale->items()->sole()->unit_cost_snapshot)->toBe('0.00');
});

test('keeps the POS commercial cost snapshot after cancellation', function () {
    $product = snapshotProduct($this, ['cost' => '200.00']);
    $sale = createSnapshotOperation($this, 'sale', $product, '1.0000');

    app(CancelSaleService::class)->cancel($sale, 'customer_request', null, $this->user);

    expect($sale->items()->sole()->fresh()->unit_cost_snapshot)->toBe('200.00');
});

test('captures the commercial UOM snapshot for an initial order', function () {
    $product = snapshotProduct($this);
    $order = createSnapshotOperation($this, 'order', $product, '1.2500');

    expectCommercialSnapshot($order->items()->sole());
});

test('captures the current product snapshot when editing an order creates a new line', function () {
    $initialProduct = snapshotProduct($this, ['name' => 'Producto inicial']);
    $addedProduct = snapshotProduct($this, [
        'name' => 'Producto agregado',
        'sale_quantity_step' => '0.5000',
    ]);
    $order = createSnapshotOperation($this, 'order', $initialProduct, '1.0000');

    app(OrderItemEditService::class)->update(
        $order,
        [
            ['product_id' => $initialProduct->id, 'quantity' => '1.0000'],
            ['product_id' => $addedProduct->id, 'quantity' => '1.5000'],
        ],
        null,
        null,
        null,
        $this->user,
    );

    $newLine = OperationItem::query()
        ->where('operation_id', $order->id)
        ->where('product_id', $addedProduct->id)
        ->sole();

    expectCommercialSnapshot($newLine, '0.5000');
});

test('preserves snapshots when reducing an operation item quantity', function () {
    $product = snapshotProduct($this);
    $order = createSnapshotOperation($this, 'order', $product, '2.0000');
    $line = $order->items()->sole();

    app(CommercialOperationService::class)->reduceCommercialObligation(
        $order->id,
        $product->id,
        '1.0000',
    );

    expect($line->fresh()->quantity)->toBe('1.0000');
    expectCommercialSnapshot($line->fresh());
});

test('preserves snapshots when cancelling an operation', function () {
    $product = snapshotProduct($this);
    $order = createSnapshotOperation($this, 'order', $product, '1.0000');
    $line = $order->items()->sole();

    app(CommercialOperationService::class)->cancel(
        $order,
        'customer_request',
        null,
        $this->user,
    );

    expect($line->fresh())->not->toBeNull();
    expectCommercialSnapshot($line->fresh());
});

test('allows historical operation items without commercial snapshots', function () {
    $item = OperationItem::factory()->create([
        'measurement_unit_code' => null,
        'measurement_unit_symbol' => null,
        'sale_quantity_step' => null,
        'unit_cost_snapshot' => null,
    ]);

    expect($item->fresh())
        ->measurement_unit_code->toBeNull()
        ->measurement_unit_symbol->toBeNull()
        ->sale_quantity_step->toBeNull()
        ->unit_cost_snapshot->toBeNull();
});
