<?php

use App\Models\Customer;
use App\Models\DeliveryDiscrepancy;
use App\Models\DeliveryRejectionReason;
use App\Models\DeliveryRoute;
use App\Models\Feature;
use App\Models\InventoryMovement;
use App\Models\Plan;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
use App\Models\Store;
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

    $this->store = Store::factory()->create();
    $plan = Plan::factory()->create();
    $deliveriesFeature = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $plan->features()->attach($deliveriesFeature->id);
    $this->store->update(['plan_id' => $plan->id]);

    $this->admin = User::factory()->create(['store_id' => $this->store->id]);
    $this->admin->assignRole('STORE_ADMIN');
    $this->admin->givePermissionTo('logistics.routes.reconcile');
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
    expect($product->stock)->toBe(10)
        ->and($product->stock_reserved)->toBe(0)
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
    expect($product->stock)->toBe(8)
        ->and($product->stock_reserved)->toBe(0)
        ->and(InventoryMovement::where('product_id', $product->id)->count())->toBe(0);
})->with(['returned', 'rejected_by_customer']);

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
        ->and($product->stock_reserved)->toBe(0)
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
        ->and($product->stock_reserved)->toBe(5);
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
