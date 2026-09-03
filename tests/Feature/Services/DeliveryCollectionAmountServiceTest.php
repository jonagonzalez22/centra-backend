<?php

use App\Models\CommercialOperation;
use App\Models\DeliveryRoute;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopCollection;
use App\Models\RouteStopItem;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DeliveryCollectionAmountService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->vehicle = Vehicle::factory()->create(['store_id' => $this->store->id]);
    $this->route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->user->id,
        'status' => 'dispatched',
    ]);
    $this->order = CommercialOperation::factory()->create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'type' => 'order',
        'subtotal' => 5000,
        'tax' => 0,
        'discount' => 0,
        'total' => 5000,
    ]);
    $this->stop = RouteStop::factory()->create([
        'route_id' => $this->route->id,
        'order_id' => $this->order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);
    $this->stop->setRelation('route', $this->route);
});

function amountServiceLine(
    object $test,
    int $quantity,
    float $subtotal,
    float $tax = 0,
    float $discount = 0,
    ?Product $product = null,
    ?string $createdAt = null
): array {
    $product ??= Product::factory()->create(['store_id' => $test->store->id]);
    $line = OperationItem::factory()->create([
        'operation_id' => $test->order->id,
        'product_id' => $product->id,
        'quantity' => $quantity,
        'price' => $subtotal / $quantity,
        'subtotal' => $subtotal,
        'tax_amount' => $tax,
        'discount_amount' => $discount,
        'created_at' => $createdAt ?? now(),
    ]);
    $stopItem = RouteStopItem::factory()->create([
        'route_stop_id' => $test->stop->id,
        'product_id' => $product->id,
        'quantity_planned' => $quantity,
        'quantity_loaded' => $quantity,
        'quantity_delivered' => 0,
    ]);

    return [$line, $stopItem, $product];
}

function calculateAmounts(object $test, array $quantities): array
{
    return app(DeliveryCollectionAmountService::class)->calculate($test->stop, $quantities);
}

test('values full and partial deliveries exclusively from proposed delivered quantity', function () {
    [, $item] = amountServiceLine($this, 5, 5000);

    $full = calculateAmounts($this, [$item->id => 5]);
    $partial = calculateAmounts($this, [$item->id => 3]);

    expect($full['delivered_value_current_stop'])->toBe(5000.0)
        ->and($full['amount_to_collect_now'])->toBe(5000.0)
        ->and($partial['delivered_value_current_stop'])->toBe(3000.0)
        ->and($partial['delivered_value_cumulative'])->toBe(3000.0)
        ->and($partial['amount_to_collect_now'])->toBe(3000.0);
});

test('prorates line tax and discount from persisted economic totals', function () {
    $this->order->update(['total' => 5500, 'tax' => 1000, 'discount' => 500]);
    [, $item] = amountServiceLine($this, 5, 5000, 1000, 500);

    $amounts = calculateAmounts($this, [$item->id => 3]);

    expect($amounts['delivered_value_current_stop'])->toBe(3300.0)
        ->and($amounts['amount_to_collect_now'])->toBe(3300.0);
});

test('prorates tax and discount independently', function (float $tax, float $discount, float $expected) {
    $this->order->update(['total' => 5000 + $tax - $discount]);
    [, $item] = amountServiceLine($this, 5, 5000, $tax, $discount);

    expect(calculateAmounts($this, [$item->id => 3])['delivered_value_current_stop'])
        ->toBe($expected);
})->with([
    'tax' => [1000, 0, 3600.0],
    'discount' => [0, 500, 2700.0],
]);

test('adds independently valued delivered quantities for multiple products', function () {
    [, $first] = amountServiceLine($this, 2, 2000);
    [, $second] = amountServiceLine($this, 3, 6000);
    $this->order->update(['total' => 8000, 'subtotal' => 8000]);

    $amounts = calculateAmounts($this, [$first->id => 1, $second->id => 2]);

    expect($amounts['delivered_value_current_stop'])->toBe(5000.0);
});

test('assigns multiple economic lines of the same product FIFO', function () {
    $product = Product::factory()->create(['store_id' => $this->store->id]);
    $this->order->update(['total' => 5400, 'subtotal' => 5400]);
    amountServiceLine($this, 3, 3000, product: $product, createdAt: '2026-01-01 10:00:00');
    OperationItem::factory()->create([
        'operation_id' => $this->order->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => 1200,
        'subtotal' => 2400,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'created_at' => '2026-01-02 10:00:00',
    ]);
    $stopItem = RouteStopItem::where('route_stop_id', $this->stop->id)->sole();
    $stopItem->update(['quantity_planned' => 5, 'quantity_loaded' => 5]);

    $amounts = calculateAmounts($this, [$stopItem->id => 4]);

    expect($amounts['delivered_value_current_stop'])->toBe(4200.0);
});

test('values extra-sale line from its persisted OperationItem price after original units FIFO', function () {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'price' => 9999]);
    $this->order->update(['total' => 5400, 'subtotal' => 5400]);
    amountServiceLine($this, 3, 3000, product: $product, createdAt: '2026-01-01 10:00:00');
    OperationItem::factory()->create([
        'operation_id' => $this->order->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'price' => 1200,
        'subtotal' => 2400,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'created_at' => '2026-01-02 10:00:00',
    ]);
    $stopItem = RouteStopItem::where('route_stop_id', $this->stop->id)->sole();
    $stopItem->update(['quantity_planned' => 5, 'quantity_loaded' => 5]);

    expect(calculateAmounts($this, [$stopItem->id => 4])['amount_to_collect_now'])->toBe(4200.0);
});

test('derives current value from cumulative values and preserves cent rounding', function () {
    $this->order->update(['total' => 10]);
    [, $currentItem, $product] = amountServiceLine($this, 3, 10);
    $previousStop = RouteStop::factory()->create([
        'route_id' => $this->route->id,
        'order_id' => $this->order->id,
        'sequence' => 0,
        'status' => 'completed',
    ]);
    RouteStopItem::factory()->create([
        'route_stop_id' => $previousStop->id,
        'product_id' => $product->id,
        'quantity_planned' => 1,
        'quantity_loaded' => 1,
        'quantity_delivered' => 1,
    ]);

    $amounts = calculateAmounts($this, [$currentItem->id => 2]);

    expect($amounts['delivered_value_current_stop'])->toBe(6.67)
        ->and($amounts['delivered_value_cumulative'])->toBe(10.0);
});

test('includes completed stops in a still dispatched route and permits prior delivery debt', function () {
    [, $currentItem, $product] = amountServiceLine($this, 10, 100000);
    $this->order->update(['total' => 100000, 'subtotal' => 100000]);
    $currentItem->update(['quantity_planned' => 3, 'quantity_loaded' => 3]);
    $previousStop = RouteStop::factory()->create([
        'route_id' => $this->route->id,
        'order_id' => $this->order->id,
        'sequence' => 0,
        'status' => 'completed',
    ]);
    RouteStopItem::factory()->create([
        'route_stop_id' => $previousStop->id,
        'product_id' => $product->id,
        'quantity_planned' => 4,
        'quantity_loaded' => 4,
        'quantity_delivered' => 4,
    ]);
    $paymentMethod = StorePaymentMethod::factory()->for($this->store)->create();
    OperationPayment::factory()->create([
        'operation_id' => $this->order->id,
        'store_payment_method_id' => $paymentMethod->id,
        'amount' => 10000,
    ]);

    $amounts = calculateAmounts($this, [$currentItem->id => 3]);

    expect($amounts['delivered_value_current_stop'])->toBe(30000.0)
        ->and($amounts['delivered_value_cumulative'])->toBe(70000.0)
        ->and($amounts['amount_to_collect_now'])->toBe(60000.0);
});

test('accumulates delivered value across routes and subtracts the prior verified collection', function () {
    [, $currentItem, $product] = amountServiceLine($this, 10, 100000);
    $this->order->update(['total' => 100000, 'subtotal' => 100000]);
    $currentItem->update(['quantity_planned' => 3, 'quantity_loaded' => 3]);
    $previousRoute = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->user->id,
        'status' => 'completed',
    ]);
    $previousStop = RouteStop::factory()->create([
        'route_id' => $previousRoute->id,
        'order_id' => $this->order->id,
        'status' => 'completed',
    ]);
    RouteStopItem::factory()->create([
        'route_stop_id' => $previousStop->id,
        'product_id' => $product->id,
        'quantity_planned' => 7,
        'quantity_loaded' => 7,
        'quantity_delivered' => 7,
    ]);
    $paymentMethod = StorePaymentMethod::factory()->for($this->store)->create();
    OperationPayment::factory()->create([
        'operation_id' => $this->order->id,
        'store_payment_method_id' => $paymentMethod->id,
        'amount' => 70000,
    ]);

    $amounts = calculateAmounts($this, [$currentItem->id => 3]);

    expect($amounts['delivered_value_cumulative'])->toBe(100000.0)
        ->and($amounts['amount_to_collect_now'])->toBe(30000.0);
});

test('declared collections reduce operational amount while verified and rejected are not double counted', function () {
    [, $item] = amountServiceLine($this, 5, 5000);
    $paymentMethod = StorePaymentMethod::factory()->for($this->store)->create();
    OperationPayment::factory()->create([
        'operation_id' => $this->order->id,
        'store_payment_method_id' => $paymentMethod->id,
        'amount' => 1000,
    ]);

    foreach ([['declared', 1500], ['verified', 1000], ['rejected', 900]] as [$status, $amount]) {
        RouteStopCollection::factory()->create([
            'store_id' => $this->store->id,
            'route_stop_id' => $this->stop->id,
            'commercial_operation_id' => $this->order->id,
            'store_payment_method_id' => $paymentMethod->id,
            'declared_by' => $this->user->id,
            'declared_at' => now(),
            'status' => $status,
            'amount' => $amount,
        ]);
    }

    $amounts = calculateAmounts($this, [$item->id => 5]);

    expect($amounts['verified_paid_amount'])->toBe(1000.0)
        ->and($amounts['pending_declared_amount'])->toBe(1500.0)
        ->and($amounts['amount_to_collect_now'])->toBe(2500.0);
});

test('clamps collectible value to commercial total and never returns a negative amount', function () {
    [, $item] = amountServiceLine($this, 5, 7500);
    $paymentMethod = StorePaymentMethod::factory()->for($this->store)->create();
    OperationPayment::factory()->create([
        'operation_id' => $this->order->id,
        'store_payment_method_id' => $paymentMethod->id,
        'amount' => 6000,
    ]);

    $amounts = calculateAmounts($this, [$item->id => 5]);

    expect($amounts['delivered_value_cumulative'])->toBe(7500.0)
        ->and($amounts['amount_to_collect_now'])->toBe(0.0);
});
