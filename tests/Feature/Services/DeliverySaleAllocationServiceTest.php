<?php

use App\Models\CommercialOperation;
use App\Models\DeliveryRoute;
use App\Models\DeliverySaleAllocation;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
use App\Models\Store;
use App\Models\User;
use App\Services\DeliverySaleAllocationService;
use App\Services\DriverExecutionService;
use App\Support\QuantityMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function saleAllocationContext(array $lines, array $delivered, bool $extra = false): array
{
    $store = Store::factory()->create();
    $route = DeliveryRoute::factory()->forStore($store)->create();
    $operation = CommercialOperation::factory()->forStore($store)->create();
    $stop = RouteStop::factory()->create(['route_id' => $route->id, 'order_id' => $operation->id, 'status' => 'completed']);
    $products = [];
    $items = [];

    foreach ($lines as $index => $attributes) {
        $product = $products[$attributes['product_key'] ?? $index] ??= Product::factory()->forStore($store)->create();
        $products[$attributes['product_key'] ?? $index] = $product;
        OperationItem::factory()->forOperation($operation)->forProduct($product)->create([
            'quantity' => $attributes['quantity'], 'price' => $attributes['price'],
            'subtotal' => $attributes['subtotal'], 'discount_amount' => $attributes['discount'] ?? '0.00',
            'tax_amount' => $attributes['tax'] ?? '0.00', 'discount_percentage' => $attributes['discount_percentage'] ?? '0.00',
        ]);
    }
    foreach ($delivered as $key => $quantity) {
        $product = $products[$key];
        $items[$key] = RouteStopItem::factory()->create([
            'route_stop_id' => $stop->id, 'product_id' => $product->id,
            'quantity_loaded' => $quantity, 'quantity_planned' => $quantity,
            'quantity_delivered' => $quantity, 'is_extra' => $extra,
        ]);
    }

    return compact('store', 'route', 'operation', 'stop', 'products', 'items');
}

function persistSaleAllocations(array $context): void
{
    app(DeliverySaleAllocationService::class)->persist($context['stop'], $context['route'], now());
}

test('persists a complete delivery with its commercial monetary snapshot', function () {
    $context = saleAllocationContext([['quantity' => '10', 'price' => '100.00', 'subtotal' => '1000.00']], [0 => '10']);
    $context['products'][0]->update(['cost' => '200.00']);
    persistSaleAllocations($context);

    $allocation = DeliverySaleAllocation::sole();
    expect($allocation->quantity_delivered)->toBe('10.0000')
        ->and($allocation->store_id)->toBe($context['store']->id)
        ->and($allocation->unit_price_snapshot)->toBe('100.00')
        ->and($allocation->unit_cost_snapshot)->toBe('200.00')
        ->and($allocation->gross_amount)->toBe('1000.00')
        ->and($allocation->net_amount)->toBe('1000.00')
        ->and($allocation->occurred_at)->not->toBeNull();
});

test('freezes the commercial cost independently for partial re-deliveries', function () {
    $first = saleAllocationContext([['quantity' => '10', 'price' => '10.00', 'subtotal' => '100.00']], [0 => '6']);
    $first['products'][0]->update(['cost' => '200.00']);
    persistSaleAllocations($first);

    $first['products'][0]->update(['cost' => '250.00']);
    $secondRoute = DeliveryRoute::factory()->forStore($first['store'])->create();
    $secondStop = RouteStop::factory()->create(['route_id' => $secondRoute->id, 'order_id' => $first['operation']->id, 'status' => 'completed']);
    $secondItem = RouteStopItem::factory()->create([
        'route_stop_id' => $secondStop->id,
        'product_id' => $first['products'][0]->id,
        'quantity_loaded' => '4',
        'quantity_planned' => '4',
        'quantity_delivered' => '4',
    ]);
    app(DeliverySaleAllocationService::class)->persist($secondStop, $secondRoute, now()->addMinute());

    expect(DeliverySaleAllocation::where('route_stop_item_id', $first['items'][0]->id)->sole()->unit_cost_snapshot)->toBe('200.00')
        ->and(DeliverySaleAllocation::where('route_stop_item_id', $secondItem->id)->sole()->unit_cost_snapshot)->toBe('250.00');
});

test('uses the shared-stock child commercial cost for delivery, not its physical source cost', function () {
    $context = saleAllocationContext([['quantity' => '1', 'price' => '2000.00', 'subtotal' => '2000.00']], [0 => '1']);
    $source = Product::factory()->forStore($context['store'])->create(['cost' => '30000.00']);
    $context['products'][0]->update([
        'cost' => '1000.00',
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '5.0000',
    ]);

    persistSaleAllocations($context);

    expect(DeliverySaleAllocation::sole()->unit_cost_snapshot)->toBe('1000.00');
});

test('preserves null and explicit zero commercial costs for deliveries', function () {
    $nullCost = saleAllocationContext([['quantity' => '1', 'price' => '10.00', 'subtotal' => '10.00']], [0 => '1']);
    $nullCost['products'][0]->update(['cost' => null]);
    persistSaleAllocations($nullCost);

    $zeroCost = saleAllocationContext([['quantity' => '1', 'price' => '10.00', 'subtotal' => '10.00']], [0 => '1']);
    $zeroCost['products'][0]->update(['cost' => '0.00']);
    persistSaleAllocations($zeroCost);

    expect(DeliverySaleAllocation::where('operation_id', $nullCost['operation']->id)->sole()->unit_cost_snapshot)->toBeNull()
        ->and(DeliverySaleAllocation::where('operation_id', $zeroCost['operation']->id)->sole()->unit_cost_snapshot)->toBe('0.00');
});

test('keeps delivery cost snapshots isolated by store', function () {
    $storeA = saleAllocationContext([['quantity' => '1', 'price' => '10.00', 'subtotal' => '10.00']], [0 => '1']);
    $storeB = saleAllocationContext([['quantity' => '1', 'price' => '10.00', 'subtotal' => '10.00']], [0 => '1']);
    $storeA['products'][0]->update(['cost' => '111.00']);
    $storeB['products'][0]->update(['cost' => '222.00']);

    persistSaleAllocations($storeA);
    persistSaleAllocations($storeB);

    expect(DeliverySaleAllocation::where('store_id', $storeA['store']->id)->sole()->unit_cost_snapshot)->toBe('111.00')
        ->and(DeliverySaleAllocation::where('store_id', $storeB['store']->id)->sole()->unit_cost_snapshot)->toBe('222.00');
});

test('persists partial deliveries independently and preserves cent residuals', function () {
    $first = saleAllocationContext([['quantity' => '10', 'price' => '10.00', 'subtotal' => '100.00']], [0 => '6']);
    persistSaleAllocations($first);
    $secondRoute = DeliveryRoute::factory()->forStore($first['store'])->create();
    $secondStop = RouteStop::factory()->create(['route_id' => $secondRoute->id, 'order_id' => $first['operation']->id, 'status' => 'completed']);
    $secondItem = RouteStopItem::factory()->create(['route_stop_id' => $secondStop->id, 'product_id' => $first['products'][0]->id, 'quantity_loaded' => '4', 'quantity_planned' => '4', 'quantity_delivered' => '4']);
    app(DeliverySaleAllocationService::class)->persist($secondStop, $secondRoute, now()->addMinute());

    expect(DeliverySaleAllocation::count())->toBe(2)
        ->and(DeliverySaleAllocation::where('route_stop_item_id', $first['items'][0]->id)->sole()->net_amount)->toBe('60.00')
        ->and(DeliverySaleAllocation::where('route_stop_item_id', $secondItem->id)->sole()->net_amount)->toBe('40.00');
});

test('keeps a first delivery immutable when the pending line price changes', function () {
    $context = saleAllocationContext([['quantity' => '10', 'price' => '100.00', 'subtotal' => '1000.00']], [0 => '6']);
    persistSaleAllocations($context);
    $line = OperationItem::sole();
    $line->update(['price' => '120.00', 'subtotal' => '1200.00']);
    $secondStop = RouteStop::factory()->create(['route_id' => $context['route']->id, 'order_id' => $context['operation']->id, 'status' => 'completed']);
    $secondItem = RouteStopItem::factory()->create(['route_stop_id' => $secondStop->id, 'product_id' => $context['products'][0]->id, 'quantity_loaded' => '4', 'quantity_planned' => '4', 'quantity_delivered' => '4']);
    app(DeliverySaleAllocationService::class)->persist($secondStop, $context['route'], now()->addMinute());

    expect(DeliverySaleAllocation::where('route_stop_item_id', $context['items'][0]->id)->sole()->net_amount)->toBe('600.00')
        ->and(DeliverySaleAllocation::where('route_stop_item_id', $secondItem->id)->sole()->net_amount)->toBe('480.00');
});

test('does not alter a delivered snapshot when the pending quantity is cancelled', function () {
    $context = saleAllocationContext([['quantity' => '10', 'price' => '100.00', 'subtotal' => '1000.00']], [0 => '6']);
    persistSaleAllocations($context);

    OperationItem::sole()->update(['quantity' => '6', 'subtotal' => '600.00']);

    expect(DeliverySaleAllocation::sole()->quantity_delivered)->toBe('6.0000')
        ->and(DeliverySaleAllocation::sole()->net_amount)->toBe('600.00');
});

test('captures percentage discount tax and decimal quantities without floats', function () {
    $context = saleAllocationContext([['quantity' => '1.2500', 'price' => '80.00', 'subtotal' => '100.00', 'discount' => '10.00', 'tax' => '21.00', 'discount_percentage' => '10.00']], [0 => '0.5000']);
    persistSaleAllocations($context);

    $allocation = DeliverySaleAllocation::sole();
    expect($allocation->gross_amount)->toBe('40.00')
        ->and($allocation->discount_amount)->toBe('4.00')
        ->and($allocation->tax_amount)->toBe('8.40')
        ->and($allocation->net_amount)->toBe('44.40');
});

test('uses FIFO operation lines for the same product and records an extra sale', function () {
    $context = saleAllocationContext([
        ['product_key' => 'product', 'quantity' => '5', 'price' => '100.00', 'subtotal' => '500.00'],
        ['product_key' => 'product', 'quantity' => '5', 'price' => '120.00', 'subtotal' => '600.00'],
    ], ['product' => '8'], true);
    persistSaleAllocations($context);

    $allocations = DeliverySaleAllocation::orderBy('gross_amount')->get();
    $allocatedQuantity = $allocations->reduce(
        fn (string $total, DeliverySaleAllocation $allocation): string => QuantityMath::add($total, $allocation->quantity_delivered),
        '0',
    );
    expect($allocations)->toHaveCount(2)
        ->and($allocations->pluck('quantity_delivered')->all())->toBe(['3.0000', '5.0000'])
        ->and($allocations->pluck('gross_amount')->all())->toBe(['360.00', '500.00'])
        ->and($allocatedQuantity)->toBe('8.0000')
        ->and($allocations->every(fn ($allocation) => $allocation->is_extra))->toBeTrue();
});

test('rejects insufficient commercial capacity without retaining a partial allocation', function () {
    $context = saleAllocationContext([['quantity' => '5', 'price' => '50.00', 'subtotal' => '250.00']], [0 => '8']);

    expect(fn () => persistSaleAllocations($context))->toThrow(ValidationException::class);
    expect(DeliverySaleAllocation::count())->toBe(0);
});

test('rejects a delivered item with no assignable commercial line', function () {
    $store = Store::factory()->create();
    $route = DeliveryRoute::factory()->forStore($store)->create();
    $operation = CommercialOperation::factory()->forStore($store)->create();
    $product = Product::factory()->forStore($store)->create();
    $stop = RouteStop::factory()->create(['route_id' => $route->id, 'order_id' => $operation->id, 'status' => 'completed']);
    RouteStopItem::factory()->create([
        'route_stop_id' => $stop->id, 'product_id' => $product->id,
        'quantity_planned' => '1', 'quantity_loaded' => '1', 'quantity_delivered' => '1',
    ]);

    expect(fn () => app(DeliverySaleAllocationService::class)->persist($stop, $route, now()))
        ->toThrow(ValidationException::class);
    expect(DeliverySaleAllocation::count())->toBe(0);
});

test('does not create allocations for a zero delivered quantity', function () {
    $context = saleAllocationContext([['quantity' => '1', 'price' => '50.00', 'subtotal' => '50.00']], [0 => '0']);

    persistSaleAllocations($context);
    expect(DeliverySaleAllocation::count())->toBe(0);
});

test('is idempotent', function () {
    $context = saleAllocationContext([['quantity' => '2', 'price' => '50.00', 'subtotal' => '100.00']], [0 => '2']);
    persistSaleAllocations($context);
    persistSaleAllocations($context);
    expect(DeliverySaleAllocation::count())->toBe(1);
});

test('rejects a Store B operation for a Store A route without persisting allocations', function () {
    $context = saleAllocationContext([['quantity' => '2', 'price' => '50.00', 'subtotal' => '100.00']], [0 => '2']);
    $otherStore = Store::factory()->create();
    $context['operation']->update(['store_id' => $otherStore->id]);

    expect(fn () => persistSaleAllocations($context))->toThrow(ValidationException::class);
    expect(DeliverySaleAllocation::count())->toBe(0);
});

test('rejects a Store B product for a Store A route and operation without persisting allocations', function () {
    $context = saleAllocationContext([['quantity' => '2', 'price' => '50.00', 'subtotal' => '100.00']], [0 => '2']);
    $otherProduct = Product::factory()->forStore(Store::factory()->create())->create();
    OperationItem::sole()->update(['product_id' => $otherProduct->id]);
    $context['items'][0]->update(['product_id' => $otherProduct->id]);

    expect(fn () => persistSaleAllocations($context))->toThrow(ValidationException::class);
    expect(DeliverySaleAllocation::count())->toBe(0);
});

test('does not use an operation item belonging to another operation', function () {
    $context = saleAllocationContext([['quantity' => '2', 'price' => '50.00', 'subtotal' => '100.00']], [0 => '2']);
    $foreignOperation = CommercialOperation::factory()->forStore($context['store'])->create();
    OperationItem::sole()->update(['operation_id' => $foreignOperation->id]);

    expect(fn () => persistSaleAllocations($context))->toThrow(ValidationException::class);
    expect(DeliverySaleAllocation::count())->toBe(0);
});

test('rejects a route that does not own the stop', function () {
    $context = saleAllocationContext([['quantity' => '2', 'price' => '50.00', 'subtotal' => '100.00']], [0 => '2']);
    $otherRoute = DeliveryRoute::factory()->forStore($context['store'])->create();

    expect(fn () => app(DeliverySaleAllocationService::class)->persist($context['stop'], $otherRoute, now()))
        ->toThrow(ValidationException::class);
    expect(DeliverySaleAllocation::count())->toBe(0);
});

test('rolls back physical completion when a cross-store operation prevents its allocation', function () {
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $driver = User::factory()->create(['store_id' => $storeA->id]);
    $route = DeliveryRoute::factory()->forStore($storeA)->create(['driver_id' => $driver->id, 'status' => 'dispatched']);
    $product = Product::factory()->forStore($storeA)->create();
    $operationB = CommercialOperation::factory()->forStore($storeB)->create();
    OperationItem::factory()->forOperation($operationB)->forProduct($product)->create([
        'quantity' => '1', 'price' => '50.00', 'subtotal' => '50.00', 'tax_amount' => '0.00', 'discount_amount' => '0.00',
    ]);
    $stop = RouteStop::factory()->create(['route_id' => $route->id, 'order_id' => $operationB->id, 'status' => 'pending']);
    $item = RouteStopItem::factory()->create([
        'route_stop_id' => $stop->id, 'product_id' => $product->id,
        'quantity_planned' => '1', 'quantity_loaded' => '1', 'quantity_delivered' => '0',
    ]);

    expect(fn () => app(DriverExecutionService::class)->completeStop($stop, [
        'items' => [['route_stop_item_id' => $item->id, 'quantity_delivered' => '1']],
    ], $driver))->toThrow(ValidationException::class);

    expect($stop->fresh()->status)->toBe('pending')
        ->and($stop->fresh()->completed_at)->toBeNull()
        ->and($item->fresh()->quantity_delivered)->toBe('0.0000')
        ->and(DeliverySaleAllocation::count())->toBe(0);
});

test('rolls back physical completion when commercial capacity is insufficient', function () {
    $store = Store::factory()->create();
    $driver = User::factory()->create(['store_id' => $store->id]);
    $route = DeliveryRoute::factory()->forStore($store)->create(['driver_id' => $driver->id, 'status' => 'dispatched']);
    $product = Product::factory()->forStore($store)->create();
    $operation = CommercialOperation::factory()->forStore($store)->create();
    OperationItem::factory()->forOperation($operation)->forProduct($product)->create([
        'quantity' => '5', 'price' => '50.00', 'subtotal' => '250.00', 'tax_amount' => '0.00', 'discount_amount' => '0.00',
    ]);
    $stop = RouteStop::factory()->create(['route_id' => $route->id, 'order_id' => $operation->id, 'status' => 'pending']);
    $item = RouteStopItem::factory()->create([
        'route_stop_id' => $stop->id, 'product_id' => $product->id,
        'quantity_planned' => '8', 'quantity_loaded' => '8', 'quantity_delivered' => '0',
    ]);

    expect(fn () => app(DriverExecutionService::class)->completeStop($stop, [
        'items' => [['route_stop_item_id' => $item->id, 'quantity_delivered' => '8']],
    ], $driver))->toThrow(ValidationException::class);

    expect($stop->fresh()->status)->toBe('pending')
        ->and($stop->fresh()->completed_at)->toBeNull()
        ->and($item->fresh()->quantity_delivered)->toBe('0.0000')
        ->and(DeliverySaleAllocation::count())->toBe(0);
});
