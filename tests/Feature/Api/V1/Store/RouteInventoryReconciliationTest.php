<?php

use App\Models\Customer;
use App\Models\DeliveryDiscrepancy;
use App\Models\DeliveryRejectionReason;
use App\Models\DeliveryRoute;
use App\Models\Feature;
use App\Models\InventoryMovement;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopCollection;
use App\Models\RouteStopItem;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CommercialOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_DRIVER', 'guard_name' => 'web']);
    Permission::create(['name' => 'logistics.routes.reconcile', 'guard_name' => 'web']);
    Permission::create(['name' => 'logistics.routes.manage', 'guard_name' => 'web']);
    Permission::create(['name' => 'orders.view', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();
    $plan = Plan::factory()->create();
    $deliveriesFeature = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $posFeature = Feature::create(['code' => 'pos', 'name' => 'POS']);
    $plan->features()->attach([$deliveriesFeature->id, $posFeature->id]);
    $this->store->update(['plan_id' => $plan->id]);

    $this->admin = User::factory()->create(['store_id' => $this->store->id]);
    $this->admin->assignRole('STORE_ADMIN');
    $this->admin->givePermissionTo('logistics.routes.reconcile');
    $this->admin->givePermissionTo('logistics.routes.manage');
    $this->admin->givePermissionTo('orders.view');
    $this->adminToken = $this->admin->createToken('reconciliation-test')->plainTextToken;

    $this->driver = User::factory()->create(['store_id' => $this->store->id]);
    $this->driver->assignRole('STORE_DRIVER');
    $this->driverToken = $this->driver->createToken('driver-test')->plainTextToken;
    $this->vehicle = Vehicle::factory()->forStore($this->store)->create();
    $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
    $this->rejectionReason = DeliveryRejectionReason::create([
        'store_id' => $this->store->id,
        'code' => 'customer_rejected',
        'label' => 'Cliente no recibe',
        'is_active' => true,
    ]);
});

function routeInventoryCreateOrder(object $test, array $items)
{
    return app(CommercialOperationService::class)->create([
        'type' => 'order',
        'customer_id' => $test->customer->id,
        'requested_delivery_date' => now()->addDay()->format('Y-m-d'),
        'items' => array_map(fn (array $item) => [
            'product_id' => $item['product']->id,
            'quantity' => $item['quantity'],
            'price' => (float) $item['product']->price,
        ], $items),
    ], $test->store->id, $test->admin->id);
}

function routeInventoryCreateRoute(object $test, string $status = 'awaiting_reconciliation'): DeliveryRoute
{
    return DeliveryRoute::create([
        'store_id' => $test->store->id,
        'vehicle_id' => $test->vehicle->id,
        'driver_id' => $test->driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => $status,
    ]);
}

function routeInventoryAddStop(
    DeliveryRoute $route,
    $order,
    Product $product,
    int $loaded,
    int $delivered,
    string $status
): array {
    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => RouteStop::where('route_id', $route->id)->count() + 1,
        'status' => $status,
    ]);

    $item = RouteStopItem::create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
        'quantity_planned' => $loaded,
        'quantity_loaded' => $loaded,
        'quantity_delivered' => $delivered,
    ]);

    return [$stop, $item];
}

function routeInventoryResolve(object $test, DeliveryRoute $route, RouteStopItem $item, string $resolution, int $quantity): void
{
    app('auth')->forgetGuards();
    $test->flushHeaders()
        ->withHeader('Authorization', "Bearer {$test->adminToken}")
        ->postJson("/api/v1/store/routes/{$route->id}/discrepancies", [
            'route_stop_item_id' => $item->id,
            'resolution_type' => $resolution,
            'quantity_to_resolve' => $quantity,
        ])
        ->assertOk();
}

function routeInventoryFinalize(object $test, DeliveryRoute $route): void
{
    app('auth')->forgetGuards();
    $test->flushHeaders()
        ->withHeader('Authorization', "Bearer {$test->adminToken}")
        ->postJson("/api/v1/store/routes/{$route->id}/finalize-reconciliation")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');
}

function routeInventoryPaymentMethod(object $test): StorePaymentMethod
{
    $paymentMethod = PaymentMethod::factory()->create([
        'code' => 'economic-check-'.fake()->unique()->numerify('####'),
        'is_active' => true,
    ]);

    return StorePaymentMethod::factory()
        ->forStore($test->store)
        ->forPaymentMethod($paymentMethod)
        ->create(['is_enabled' => true]);
}

test('fully delivered order decreases stock and releases its reservation', function () {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 10, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 5]]);
    $route = routeInventoryCreateRoute($this);
    routeInventoryAddStop($route, $order, $product, 5, 5, 'completed');

    routeInventoryFinalize($this, $route);

    $product->refresh();
    expect($product->stock)->toBe(5)
        ->and($product->stock_reserved)->toBe(0);
});

test('fully failed and returned order does not create stock', function () {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 10, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 5]]);
    $route = routeInventoryCreateRoute($this);
    [, $item] = routeInventoryAddStop($route, $order, $product, 5, 0, 'failed');

    routeInventoryResolve($this, $route, $item, 'returned', 5);
    routeInventoryFinalize($this, $route);

    $product->refresh();
    $order->refresh();
    expect($product->stock)->toBe(10)
        ->and($product->stock_reserved)->toBe(0)
        ->and($order->items()->sum('quantity'))->toBe(0)
        ->and($order->status)->toBe('closed')
        ->and(InventoryMovement::where('product_id', $product->id)->count())->toBe(0);
});

test('returned and rejected quantities only release reservations', function (string $resolution) {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 10, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 5]]);
    $route = routeInventoryCreateRoute($this);
    [, $item] = routeInventoryAddStop($route, $order, $product, 5, 2, 'completed');

    routeInventoryResolve($this, $route, $item, $resolution, 3);
    routeInventoryFinalize($this, $route);

    $product->refresh();
    $order->refresh();
    expect($product->stock)->toBe(8)
        ->and($product->stock_reserved)->toBe(0)
        ->and($order->items()->sum('quantity'))->toBe(2)
        ->and($order->status)->toBe('delivered')
        ->and(InventoryMovement::where('product_id', $product->id)->count())->toBe(0);
})->with(['returned', 'rejected_by_customer']);

test('returned and rejected recalculate totals without modifying existing payments', function (
    string $resolution,
    float $paidAmount,
    float $expectedPending
) {
    $product = Product::factory()->forStore($this->store)->create([
        'price' => 10,
        'stock' => 20,
        'stock_reserved' => 0,
    ]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 10]]);
    $payment = null;

    if ($paidAmount > 0) {
        $payment = OperationPayment::create([
            'operation_id' => $order->id,
            'store_payment_method_id' => routeInventoryPaymentMethod($this)->id,
            'amount' => $paidAmount,
            'reference' => 'DEPOSIT',
        ]);
    }

    $route = routeInventoryCreateRoute($this);
    [, $item] = routeInventoryAddStop($route, $order, $product, 10, 5, 'completed');
    routeInventoryResolve($this, $route, $item, $resolution, 5);
    routeInventoryFinalize($this, $route);

    $order->refresh();
    $operationItem = $order->items()->sole();

    expect($operationItem->quantity)->toBe(5)
        ->and((float) $operationItem->subtotal)->toBe(50.0)
        ->and((float) $order->subtotal)->toBe(50.0)
        ->and((float) $order->total)->toBe(50.0)
        ->and($order->total_amount)->toBe(50.0)
        ->and($order->paid_amount)->toBe($paidAmount)
        ->and($order->pending_balance)->toBe($expectedPending)
        ->and(OperationPayment::where('operation_id', $order->id)->count())->toBe($paidAmount > 0 ? 1 : 0);

    if ($payment) {
        expect(OperationPayment::find($payment->id))->not->toBeNull()
            ->and((float) OperationPayment::find($payment->id)->amount)->toBe($paidAmount);
    }

    $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson("/api/v1/store/orders/{$order->id}")
        ->assertOk();

    expect((float) $response->json('data.subtotal'))->toBe(50.0)
        ->and((float) $response->json('data.total'))->toBe(50.0)
        ->and((float) $response->json('data.paid_amount'))->toBe($paidAmount)
        ->and((float) $response->json('data.pending_amount'))->toBe($expectedPending);
})->with([
    'returned without payments' => ['returned', 0.0, 50.0],
    'rejected without payments' => ['rejected_by_customer', 0.0, 50.0],
    'returned with partial deposit' => ['returned', 20.0, 30.0],
    'rejected with partial deposit' => ['rejected_by_customer', 20.0, 30.0],
    'returned paid to new total' => ['returned', 50.0, 0.0],
    'rejected paid to new total' => ['rejected_by_customer', 50.0, 0.0],
    'returned becomes overpaid' => ['returned', 80.0, -30.0],
    'rejected becomes overpaid' => ['rejected_by_customer', 80.0, -30.0],
]);

test('declared collection must be verified before total reduction and can leave a negative balance', function () {
    $product = Product::factory()->forStore($this->store)->create([
        'price' => 10,
        'stock' => 20,
        'stock_reserved' => 0,
    ]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 10]]);
    $route = routeInventoryCreateRoute($this);
    [$stop, $item] = routeInventoryAddStop($route, $order, $product, 10, 5, 'completed');
    routeInventoryResolve($this, $route, $item, 'returned', 5);

    $collection = RouteStopCollection::create([
        'store_id' => $this->store->id,
        'route_stop_id' => $stop->id,
        'commercial_operation_id' => $order->id,
        'store_payment_method_id' => routeInventoryPaymentMethod($this)->id,
        'amount' => 80,
        'reference' => 'DECLARED-BEFORE-RETURN',
        'declared_by' => $this->driver->id,
        'declared_at' => now(),
        'status' => 'declared',
    ]);

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->postJson("/api/v1/store/routes/{$route->id}/finalize-reconciliation")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Hay cobranzas pendientes de verificar o rechazar.');

    expect((float) $order->fresh()->total)->toBe(100.0)
        ->and($order->fresh()->items()->sum('quantity'))->toBe(10)
        ->and(OperationPayment::where('operation_id', $order->id)->count())->toBe(0);

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/verify")
        ->assertOk();

    expect($collection->fresh()->status)->toBe('verified')
        ->and(OperationPayment::where('operation_id', $order->id)->count())->toBe(1)
        ->and((float) OperationPayment::where('operation_id', $order->id)->sole()->amount)->toBe(80.0);

    routeInventoryFinalize($this, $route);

    expect((float) $order->fresh()->total)->toBe(50.0)
        ->and($order->fresh()->paid_amount)->toBe(80.0)
        ->and($order->fresh()->pending_balance)->toBe(-30.0)
        ->and(OperationPayment::where('operation_id', $order->id)->count())->toBe(1);
});

test('missing and damaged quantities decrease stock exactly once', function (string $resolution) {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 10, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 5]]);
    $route = routeInventoryCreateRoute($this);
    [, $item] = routeInventoryAddStop($route, $order, $product, 5, 0, 'failed');

    routeInventoryResolve($this, $route, $item, $resolution, 5);
    routeInventoryFinalize($this, $route);

    $product->refresh();
    $movement = InventoryMovement::where('product_id', $product->id)->sole();
    expect($product->stock)->toBe(5)
        ->and($product->stock_reserved)->toBe(5)
        ->and($movement->type)->toBe('output')
        ->and($movement->quantity)->toBe(-5);
})->with(['missing', 'damaged']);

test('pending redelivery preserves stock and reservation', function () {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 10, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 5]]);
    $route = routeInventoryCreateRoute($this);
    [, $item] = routeInventoryAddStop($route, $order, $product, 5, 0, 'failed');

    routeInventoryResolve($this, $route, $item, 'pending_redelivery', 5);
    routeInventoryFinalize($this, $route);

    $product->refresh();
    expect($product->stock)->toBe(10)
        ->and($product->stock_reserved)->toBe(5)
        ->and($order->fresh()->items()->sum('quantity'))->toBe(5)
        ->and($order->fresh()->status)->toBe('open');
});

test('delivery accumulated across two routes marks a single-product order delivered', function () {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 200, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 100]]);

    $routeOne = routeInventoryCreateRoute($this);
    routeInventoryAddStop($routeOne, $order, $product, 70, 70, 'completed');
    routeInventoryFinalize($this, $routeOne);

    expect($order->fresh()->status)->toBe('partially_delivered')
        ->and($product->fresh()->stock_reserved)->toBe(30);

    $partialHistory = collect($this->flushHeaders()
        ->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson("/api/v1/store/orders/{$order->id}")
        ->assertOk()
        ->json('data.history'));
    expect($partialHistory->firstWhere('type', 'delivery_reconciled_partial')['route']['id'])
        ->toBe($routeOne->id);

    $routeTwo = routeInventoryCreateRoute($this);
    routeInventoryAddStop($routeTwo, $order, $product, 30, 30, 'completed');
    routeInventoryFinalize($this, $routeTwo);

    expect($order->fresh()->status)->toBe('delivered')
        ->and($product->fresh()->stock)->toBe(100)
        ->and($product->fresh()->stock_reserved)->toBe(0);

    $finalHistory = collect($this->flushHeaders()
        ->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->getJson("/api/v1/store/orders/{$order->id}")
        ->assertOk()
        ->json('data.history'));
    $reconciledDeliveries = $finalHistory
        ->filter(fn (array $entry) => str_starts_with($entry['type'], 'delivery_reconciled'))
        ->values();
    expect($reconciledDeliveries)->toHaveCount(2)
        ->and($reconciledDeliveries[0]['route']['id'])->toBe($routeOne->id)
        ->and($reconciledDeliveries[1]['route']['id'])->toBe($routeTwo->id)
        ->and($reconciledDeliveries[1]['type'])->toBe('delivery_reconciled_final');
});

test('delivery status is calculated per product across routes', function () {
    $productA = Product::factory()->forStore($this->store)->create(['stock' => 50, 'stock_reserved' => 0]);
    $productB = Product::factory()->forStore($this->store)->create(['stock' => 50, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [
        ['product' => $productA, 'quantity' => 10],
        ['product' => $productB, 'quantity' => 10],
    ]);

    $routeOne = routeInventoryCreateRoute($this);
    $stopOne = RouteStop::create([
        'route_id' => $routeOne->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'completed',
    ]);
    foreach ([[$productA, 10], [$productB, 5]] as [$product, $quantity]) {
        RouteStopItem::create([
            'route_stop_id' => $stopOne->id,
            'product_id' => $product->id,
            'quantity_planned' => $quantity,
            'quantity_loaded' => $quantity,
            'quantity_delivered' => $quantity,
        ]);
    }
    routeInventoryFinalize($this, $routeOne);
    expect($order->fresh()->status)->toBe('partially_delivered');

    $routeTwo = routeInventoryCreateRoute($this);
    routeInventoryAddStop($routeTwo, $order, $productB, 5, 5, 'completed');
    routeInventoryFinalize($this, $routeTwo);

    expect($order->fresh()->status)->toBe('delivered')
        ->and($productA->fresh()->stock_reserved)->toBe(0)
        ->and($productB->fresh()->stock_reserved)->toBe(0);
});

test('delivery status aggregates multiple operation items of the same product', function () {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 30, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 4]]);
    OperationItem::create([
        'operation_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 6,
        'price' => $product->price,
        'subtotal' => 6 * (float) $product->price,
        'tax_amount' => 0,
        'discount_amount' => 0,
    ]);
    $product->increment('stock_reserved', 6);

    $routeOne = routeInventoryCreateRoute($this);
    routeInventoryAddStop($routeOne, $order, $product, 4, 4, 'completed');
    routeInventoryFinalize($this, $routeOne);
    expect($order->fresh()->status)->toBe('partially_delivered');

    $routeTwo = routeInventoryCreateRoute($this);
    routeInventoryAddStop($routeTwo, $order, $product, 6, 6, 'completed');
    routeInventoryFinalize($this, $routeTwo);
    expect($order->fresh()->status)->toBe('delivered');
});

test('finalizing one route preserves reservation planned for another route', function () {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 20, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 10]]);
    $completedRoute = routeInventoryCreateRoute($this);
    routeInventoryAddStop($completedRoute, $order, $product, 4, 4, 'completed');

    $pendingRoute = routeInventoryCreateRoute($this, 'planned');
    routeInventoryAddStop($pendingRoute, $order, $product, 6, 0, 'pending');

    routeInventoryFinalize($this, $completedRoute);

    $product->refresh();
    expect($product->stock)->toBe(16)
        ->and($product->stock_reserved)->toBe(6);
});

test('assignItems ignores planning history from completed routes', function () {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 150, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 100]]);

    $completedRoute = routeInventoryCreateRoute($this, 'completed');
    routeInventoryAddStop($completedRoute, $order, $product, 70, 70, 'completed');

    $newRoute = routeInventoryCreateRoute($this, 'draft');
    $newStop = RouteStop::create([
        'route_id' => $newRoute->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->postJson("/api/v1/store/routes/{$newRoute->id}/stops/{$newStop->id}/items", [
            'items' => [['product_id' => $product->id, 'quantity_planned' => 20]],
        ])
        ->assertOk();

    expect($newStop->items()->where('product_id', $product->id)->value('quantity_planned'))->toBe(20);
});

test('assignItems prevents exceeding the balance after completed and active routes', function () {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 150, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 100]]);

    $completedRoute = routeInventoryCreateRoute($this, 'completed');
    routeInventoryAddStop($completedRoute, $order, $product, 70, 70, 'completed');

    $plannedRoute = routeInventoryCreateRoute($this, 'planned');
    routeInventoryAddStop($plannedRoute, $order, $product, 20, 0, 'pending');

    $newRoute = routeInventoryCreateRoute($this, 'draft');
    $newStop = RouteStop::create([
        'route_id' => $newRoute->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->postJson("/api/v1/store/routes/{$newRoute->id}/stops/{$newStop->id}/items", [
            'items' => [['product_id' => $product->id, 'quantity_planned' => 15]],
        ])
        ->assertStatus(422);

    expect($newStop->items()->exists())->toBeFalse();
});

test('failed stop does not cancel an order with previous deliveries', function () {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 150, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 100]]);

    $completedRoute = routeInventoryCreateRoute($this, 'completed');
    routeInventoryAddStop($completedRoute, $order, $product, 70, 70, 'completed');
    $product->update(['stock_reserved' => 30]);

    $failedRoute = routeInventoryCreateRoute($this);
    [, $failedItem] = routeInventoryAddStop($failedRoute, $order, $product, 30, 0, 'failed');
    routeInventoryResolve($this, $failedRoute, $failedItem, 'pending_redelivery', 30);
    routeInventoryFinalize($this, $failedRoute);

    expect($order->fresh()->status)->toBe('partially_delivered')
        ->and($product->fresh()->stock_reserved)->toBe(30);
});

test('first failed delivery attempt does not cancel the commercial order', function () {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 150, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 100]]);
    $route = routeInventoryCreateRoute($this);
    [, $item] = routeInventoryAddStop($route, $order, $product, 100, 0, 'failed');

    routeInventoryResolve($this, $route, $item, 'pending_redelivery', 100);
    routeInventoryFinalize($this, $route);

    expect($order->fresh()->status)->toBe('open')
        ->and($product->fresh()->stock_reserved)->toBe(100);
});

test('pending redelivery and missing remain reserved while rejected ends the obligation', function () {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 100, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 30]]);
    $route = routeInventoryCreateRoute($this);

    foreach (['pending_redelivery', 'missing', 'rejected_by_customer'] as $resolution) {
        [, $item] = routeInventoryAddStop($route, $order, $product, 10, 0, 'failed');
        routeInventoryResolve($this, $route, $item, $resolution, 10);
    }

    routeInventoryFinalize($this, $route);

    expect($product->fresh()->stock)->toBe(90)
        ->and($product->fresh()->stock_reserved)->toBe(20)
        ->and($order->fresh()->items()->sum('quantity'))->toBe(20)
        ->and($order->fresh()->status)->toBe('open')
        ->and(InventoryMovement::where('product_id', $product->id)->count())->toBe(1);
});

test('partial discrepancy resolution is rejected safely', function () {
    $product = Product::factory()->forStore($this->store)->create(['stock' => 10, 'stock_reserved' => 0]);
    $order = routeInventoryCreateOrder($this, [['product' => $product, 'quantity' => 5]]);
    $route = routeInventoryCreateRoute($this);
    [, $item] = routeInventoryAddStop($route, $order, $product, 5, 0, 'failed');

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->postJson("/api/v1/store/routes/{$route->id}/discrepancies", [
            'route_stop_item_id' => $item->id,
            'resolution_type' => 'returned',
            'quantity_to_resolve' => 4,
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'La cantidad a resolver debe coincidir con la diferencia pendiente.');

    expect(DeliveryDiscrepancy::where('route_stop_item_id', $item->id)->exists())->toBeFalse();
});

test('A B C D route with failed source and extra sale reconciles exact inventory', function () {
    $productA = Product::factory()->forStore($this->store)->create(['name' => 'A', 'stock' => 10, 'stock_reserved' => 0]);
    $productB = Product::factory()->forStore($this->store)->create(['name' => 'B', 'stock' => 15, 'stock_reserved' => 0]);
    $productC = Product::factory()->forStore($this->store)->create(['name' => 'C', 'stock' => 100, 'stock_reserved' => 0]);
    $productD = Product::factory()->forStore($this->store)->create(['name' => 'D', 'stock' => 20, 'stock_reserved' => 0]);

    $orderP1 = routeInventoryCreateOrder($this, [
        ['product' => $productA, 'quantity' => 1],
        ['product' => $productB, 'quantity' => 1],
    ]);
    $orderP2 = routeInventoryCreateOrder($this, [
        ['product' => $productC, 'quantity' => 1],
        ['product' => $productD, 'quantity' => 3],
    ]);

    $route = routeInventoryCreateRoute($this, 'dispatched');
    $stopP1 = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $orderP1->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);
    $itemA = RouteStopItem::create([
        'route_stop_id' => $stopP1->id,
        'product_id' => $productA->id,
        'quantity_planned' => 1,
        'quantity_loaded' => 1,
        'quantity_delivered' => 0,
    ]);
    $itemB = RouteStopItem::create([
        'route_stop_id' => $stopP1->id,
        'product_id' => $productB->id,
        'quantity_planned' => 1,
        'quantity_loaded' => 1,
        'quantity_delivered' => 0,
    ]);

    $stopP2 = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $orderP2->id,
        'sequence' => 2,
        'status' => 'pending',
    ]);
    $itemC = RouteStopItem::create([
        'route_stop_id' => $stopP2->id,
        'product_id' => $productC->id,
        'quantity_planned' => 1,
        'quantity_loaded' => 1,
        'quantity_delivered' => 0,
    ]);
    $itemDSource = RouteStopItem::create([
        'route_stop_id' => $stopP2->id,
        'product_id' => $productD->id,
        'quantity_planned' => 3,
        'quantity_loaded' => 3,
        'quantity_delivered' => 0,
    ]);

    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stopP2->id}/complete", [
            'status' => 'failed',
            'rejection_reason_id' => $this->rejectionReason->id,
            'items' => [
                ['route_stop_item_id' => $itemC->id, 'quantity_delivered' => 0],
                ['route_stop_item_id' => $itemDSource->id, 'quantity_delivered' => 0],
            ],
        ])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stopP1->id}/extra-sales", [
            'items' => [['product_id' => $productD->id, 'quantity' => 1]],
        ])
        ->assertOk();

    $extraD = RouteStopItem::where('route_stop_id', $stopP1->id)
        ->where('product_id', $productD->id)
        ->where('is_extra', true)
        ->sole();

    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stopP1->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $itemA->id, 'quantity_delivered' => 1],
                ['route_stop_item_id' => $itemB->id, 'quantity_delivered' => 1],
                ['route_stop_item_id' => $extraD->id, 'quantity_delivered' => 1],
            ],
        ])
        ->assertOk();

    routeInventoryResolve($this, $route, $itemC, 'returned', 1);
    routeInventoryResolve($this, $route, $itemDSource, 'returned', 2);
    routeInventoryFinalize($this, $route);

    expect([$productA->fresh()->stock, $productA->fresh()->stock_reserved])->toBe([9, 0])
        ->and([$productB->fresh()->stock, $productB->fresh()->stock_reserved])->toBe([14, 0])
        ->and([$productC->fresh()->stock, $productC->fresh()->stock_reserved])->toBe([100, 0])
        ->and([$productD->fresh()->stock, $productD->fresh()->stock_reserved])->toBe([19, 0]);
});
