<?php

use App\Http\Resources\RouteStopItemResource;
use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryRoute;
use App\Models\Locality;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\Province;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\RouteManagementService;
use App\Services\RouteStopItemStockConsumptionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::factory()->create([
        'latitude' => -34.6037,
        'longitude' => -58.3816,
    ]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->vehicle = Vehicle::factory()->forStore($this->store)->create(['is_active' => true]);
    $this->driver = User::factory()->create(['store_id' => $this->store->id]);
});

function routeSnapshotProduct(object $test, array $attributes = []): Product
{
    return Product::factory()->create([
        'store_id' => $test->store->id,
        'stock' => '0.0000',
        'stock_reserved' => '0.0000',
        'stock_min' => '0.0000',
        'sale_quantity_step' => '1.0000',
        ...$attributes,
    ]);
}

function routeSnapshotOrder(object $test, array $items): array
{
    $province = Province::factory()->create();
    $locality = Locality::factory()->for($province)->create();
    $customer = Customer::factory()->create(['store_id' => $test->store->id]);
    CustomerAddress::factory()->forCustomer($customer)->for($locality)->asMain()->create([
        'latitude' => -34.6037,
        'longitude' => -58.3816,
    ]);

    $order = CommercialOperation::factory()->create([
        'store_id' => $test->store->id,
        'user_id' => $test->user->id,
        'customer_id' => $customer->id,
        'type' => 'order',
        'status' => 'confirmed',
        'requested_delivery_date' => now()->addDay()->toDateString(),
    ]);

    foreach ($items as $item) {
        OperationItem::factory()->create([
            'operation_id' => $order->id,
            'product_id' => $item['product']->id,
            'product_name' => $item['product']->name,
            'quantity' => $item['quantity'],
            'stock_source_product_id' => $item['source']?->id,
            'stock_consumption_quantity' => $item['factor'] ?? null,
        ]);
    }

    $route = DeliveryRoute::create([
        'store_id' => $test->store->id,
        'vehicle_id' => $test->vehicle->id,
        'driver_id' => $test->driver->id,
        'operational_date' => now()->addDay()->toDateString(),
        'status' => 'draft',
    ]);
    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    Http::fake([
        'routes.googleapis.com/*' => Http::response([
            'routes' => [[
                'optimizedIntermediateWaypointIndex' => [0],
                'legs' => [
                    ['duration' => '600s', 'polyline' => ['encodedPolyline' => 'abc123']],
                    ['duration' => '300s', 'polyline' => ['encodedPolyline' => 'def456']],
                ],
            ]],
        ], 200),
    ]);

    app(RouteManagementService::class)->plan($route, '08:00', $test->user);

    return [$order, $route, $stop];
}

test('a normal commercial product keeps the self and factor-one convention through null route snapshots', function () {
    $product = routeSnapshotProduct($this);
    [, , $stop] = routeSnapshotOrder($this, [[
        'product' => $product,
        'source' => null,
        'quantity' => '2.0000',
    ]]);

    $item = $stop->items()->sole();
    $consumption = app(RouteStopItemStockConsumptionResolver::class)->resolve($item);

    expect($item->product_id)->toBe($product->id)
        ->and($item->stock_source_product_id)->toBeNull()
        ->and($item->stock_consumption_quantity)->toBeNull()
        ->and($consumption->stockProductId)->toBe($product->id)
        ->and($consumption->consumptionFactor)->toBe('1.0000');
});

test('an associated product copies the physical source factor and source UOM from its operation item', function () {
    $source = routeSnapshotProduct($this, ['stock' => '20.0000']);
    $child = routeSnapshotProduct($this, [
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '2.5000',
        'sale_quantity_step' => '0.2500',
    ]);
    [, , $stop] = routeSnapshotOrder($this, [[
        'product' => $child,
        'source' => $source,
        'factor' => '2.5000',
        'quantity' => '1.2500',
    ]]);

    $item = $stop->items()->sole();
    $source->load('stockMeasurementUnit');

    expect($item->product_id)->toBe($child->id)
        ->and($item->quantity_planned)->toBe('1.2500')
        ->and($item->stock_source_product_id)->toBe($source->id)
        ->and($item->stock_consumption_quantity)->toBe('2.5000')
        ->and($item->stock_source_measurement_unit_code)->toBe($source->stockMeasurementUnit->code)
        ->and($item->stock_source_measurement_unit_symbol)->toBe($source->stockMeasurementUnit->symbol);

    $payload = RouteStopItemResource::make($item->load('product.stockMeasurementUnit'))->resolve();

    expect($payload['sale_quantity_step'])->toBe('0.2500')
        ->and($payload['stock_measurement_unit']['symbol'])->toBe($child->stockMeasurementUnit->symbol)
        ->and($payload['physical_stock']['source_product_id'])->toBe($source->id)
        ->and($payload['physical_stock']['measurement_unit']['symbol'])->toBe($source->stockMeasurementUnit->symbol);
});

test('children sharing a source remain separate commercial route stop items', function () {
    $source = routeSnapshotProduct($this, ['stock' => '30.0000']);
    $firstChild = routeSnapshotProduct($this, [
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '5.0000',
    ]);
    $secondChild = routeSnapshotProduct($this, [
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '2.0000',
    ]);
    [, , $stop] = routeSnapshotOrder($this, [
        ['product' => $firstChild, 'source' => $source, 'factor' => '5.0000', 'quantity' => '2.0000'],
        ['product' => $secondChild, 'source' => $source, 'factor' => '2.0000', 'quantity' => '3.0000'],
    ]);

    $items = $stop->items()->orderBy('product_id')->get();

    expect($items)->toHaveCount(2)
        ->and($items->pluck('product_id')->all())->toContain($firstChild->id, $secondChild->id)
        ->and($items->pluck('stock_source_product_id')->unique()->all())->toBe([$source->id]);
});

test('same commercial product with distinct historical physical snapshots is not merged', function () {
    $firstSource = routeSnapshotProduct($this, ['stock' => '20.0000']);
    $secondSource = routeSnapshotProduct($this, ['stock' => '20.0000']);
    $child = routeSnapshotProduct($this, [
        'stock_source_product_id' => $secondSource->id,
        'stock_consumption_quantity' => '4.0000',
    ]);
    [, , $stop] = routeSnapshotOrder($this, [
        ['product' => $child, 'source' => $firstSource, 'factor' => '5.0000', 'quantity' => '1.0000'],
        ['product' => $child, 'source' => $secondSource, 'factor' => '4.0000', 'quantity' => '2.0000'],
    ]);

    $items = $stop->items()->where('product_id', $child->id)->orderBy('stock_consumption_quantity')->get();

    expect($items)->toHaveCount(2)
        ->and($items->pluck('quantity_planned')->all())->toBe(['2.0000', '1.0000'])
        ->and($items->pluck('stock_source_product_id')->all())->toBe([$secondSource->id, $firstSource->id])
        ->and($items->pluck('stock_consumption_quantity')->all())->toBe(['4.0000', '5.0000']);
});

test('route item physical snapshots remain unchanged after the product association changes', function () {
    $firstSource = routeSnapshotProduct($this, ['stock' => '20.0000']);
    $secondSource = routeSnapshotProduct($this, ['stock' => '20.0000']);
    $child = routeSnapshotProduct($this, [
        'stock_source_product_id' => $firstSource->id,
        'stock_consumption_quantity' => '5.0000',
    ]);
    [, , $stop] = routeSnapshotOrder($this, [[
        'product' => $child,
        'source' => $firstSource,
        'factor' => '5.0000',
        'quantity' => '1.0000',
    ]]);

    $child->update([
        'stock_source_product_id' => $secondSource->id,
        'stock_consumption_quantity' => '4.0000',
    ]);

    $item = $stop->items()->sole();

    expect($item->stock_source_product_id)->toBe($firstSource->id)
        ->and($item->stock_consumption_quantity)->toBe('5.0000');
});

test('a legacy associated operation item uses the current association only as a compatibility fallback', function () {
    $source = routeSnapshotProduct($this, ['stock' => '20.0000']);
    $child = routeSnapshotProduct($this, [
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '5.0000',
    ]);
    [, , $stop] = routeSnapshotOrder($this, [[
        'product' => $child,
        'source' => null,
        'quantity' => '1.0000',
    ]]);

    $item = $stop->items()->sole();

    expect($item->stock_source_product_id)->toBe($source->id)
        ->and($item->stock_consumption_quantity)->toBe('5.0000');
});

test('route optimization leaves physical snapshots untouched', function () {
    $source = routeSnapshotProduct($this, ['stock' => '20.0000']);
    $child = routeSnapshotProduct($this, [
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '5.0000',
    ]);
    [, $route, $stop] = routeSnapshotOrder($this, [[
        'product' => $child,
        'source' => $source,
        'factor' => '5.0000',
        'quantity' => '1.0000',
    ]]);

    Http::fake([
        'routes.googleapis.com/*' => Http::response([
            'routes' => [[
                'optimizedIntermediateWaypointIndex' => [0],
                'legs' => [
                    ['duration' => '600s', 'polyline' => ['encodedPolyline' => 'ghi789']],
                    ['duration' => '300s', 'polyline' => ['encodedPolyline' => 'jkl012']],
                ],
            ]],
        ], 200),
    ]);

    app(RouteManagementService::class)->optimize($route->fresh(), $this->user);

    $item = $stop->items()->sole();
    expect($item->stock_source_product_id)->toBe($source->id)
        ->and($item->stock_consumption_quantity)->toBe('5.0000');
});

test('a legacy route stop item falls back to the current product association without claiming a historical snapshot', function () {
    $source = routeSnapshotProduct($this, ['stock' => '20.0000']);
    $child = routeSnapshotProduct($this, [
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '5.0000',
    ]);
    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
    ]);
    $stop = RouteStop::factory()->create(['route_id' => $route->id]);
    $item = RouteStopItem::create([
        'route_stop_id' => $stop->id,
        'product_id' => $child->id,
        'quantity_planned' => '1.0000',
        'quantity_loaded' => '0.0000',
        'quantity_delivered' => '0.0000',
    ]);

    $consumption = app(RouteStopItemStockConsumptionResolver::class)->resolve($item);

    expect($item->stock_source_product_id)->toBeNull()
        ->and($consumption->stockProductId)->toBe($source->id)
        ->and($consumption->consumptionFactor)->toBe('5.0000');
});
