<?php

declare(strict_types=1);

use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\MeasurementUnit;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
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
    ]);

    expect($item->fresh())
        ->measurement_unit_code->toBeNull()
        ->measurement_unit_symbol->toBeNull()
        ->sale_quantity_step->toBeNull();
});
