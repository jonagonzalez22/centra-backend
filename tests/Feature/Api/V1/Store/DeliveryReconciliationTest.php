<?php

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryRoute;
use App\Models\DeliveryRouteEvent;
use App\Models\Feature;
use App\Models\InventoryMovement;
use App\Models\Locality;
use App\Models\OperationItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Province;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_DRIVER', 'guard_name' => 'web']);

    foreach ([
        'logistics.routes.view',
        'logistics.routes.manage',
    ] as $perm) {
        Permission::create(['name' => $perm, 'guard_name' => 'web']);
    }

    $this->store = Store::factory()->create();

    $plan = Plan::factory()->create();
    $deliveriesFeature = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $plan->features()->attach($deliveriesFeature->id);
    $this->store->update(['plan_id' => $plan->id]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo([
        'logistics.routes.view',
        'logistics.routes.manage',
    ]);
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

// ── Helpers ──────────────────────────────────────────────────────────

function recCreateVehicle(Store $store): Vehicle
{
    return Vehicle::factory()->forStore($store)->create(['is_active' => true]);
}

function recCreateDriver(Store $store): User
{
    $driver = User::factory()->create(['store_id' => $store->id]);
    $driver->assignRole('STORE_DRIVER');

    return $driver;
}

function recCreateCustomerWithAddress(Store $store): array
{
    $province = Province::factory()->create();
    $locality = Locality::factory()->for($province)->create();

    $customer = Customer::factory()->create(['store_id' => $store->id]);
    CustomerAddress::factory()->forCustomer($customer)->for($locality)->asMain()->create([
        'latitude' => -34.6037,
        'longitude' => -58.3816,
    ]);

    return [$customer, $locality];
}

function recCreateEligibleOrder(Store $store, Customer $customer, array $attrs = []): \App\Models\CommercialOperation
{
    return \App\Models\CommercialOperation::factory()
        ->forStore($store)
        ->for($store->users()->first() ?? User::factory()->create(['store_id' => $store->id]), 'user')
        ->forCustomer($customer)
        ->order()
        ->create(array_merge([
            'status' => 'confirmed',
        ], $attrs));
}

/**
 * Create a route in awaiting_reconciliation with completed stops and delivered quantities.
 */
function recCreateRouteReadyForReconciliation(Store $store, ?User $driver = null): array
{
    $vehicle = recCreateVehicle($store);
    $driver = $driver ?? recCreateDriver($store);
    [$customer, $locality] = recCreateCustomerWithAddress($store);
    $product = Product::factory()->create([
        'store_id' => $store->id,
        'stock' => 100,
        'stock_reserved' => 50,
    ]);

    $date = now()->addDay()->format('Y-m-d');

    $order = recCreateEligibleOrder($store, $customer, ['requested_delivery_date' => $date]);
    OperationItem::factory()->create([
        'operation_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 10,
    ]);

    $route = DeliveryRoute::create([
        'store_id' => $store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'awaiting_reconciliation',
    ]);

    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'completed',
    ]);

    $routeStopItem = RouteStopItem::create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
        'quantity_planned' => 10,
        'quantity_loaded' => 10,
        'quantity_delivered' => 10,
    ]);

    return [$route, $stop, $product, $routeStopItem, $order];
}

// ── Tests ────────────────────────────────────────────────────────────

test('full delivery: all stops completed with full quantities', function () {
    [$route, $stop, $product, $routeStopItem, $order] = recCreateRouteReadyForReconciliation($this->store);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/process-deliveries");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'completed');

    // Route completed
    $route->refresh();
    expect($route->status)->toBe('completed');
    expect($route->processed_at)->not->toBeNull();
    expect($route->processed_by)->toBe($this->user->id);

    // Inventory movement created (output)
    $movement = InventoryMovement::first();
    expect($movement)->not->toBeNull();
    expect($movement->type)->toBe('output');
    expect($movement->quantity)->toBe(-10);
    expect($movement->product_id)->toBe($product->id);

    // Stock decreased
    $product->refresh();
    expect($product->stock)->toBe(90);   // 100 - 10
    expect($product->stock_reserved)->toBe(40); // 50 - 10

    // Order status updated
    $order->refresh();
    expect($order->status)->toBe('delivered');

    // Event created
    expect(DeliveryRouteEvent::where('event_type', 'route_processed')->count())->toBe(1);
});

test('partial delivery: some items partially delivered', function () {
    [$route, $stop, $product, $routeStopItem, $order] = recCreateRouteReadyForReconciliation($this->store);

    // Override: only 3 out of 10 delivered on the route stop item
    $routeStopItem->update(['quantity_delivered' => 3]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/process-deliveries");

    $response->assertStatus(200);

    $order->refresh();
    // Only 3 out of 10 delivered → partially_delivered
    expect($order->status)->toBe('partially_delivered');

    // Stock partially decreased
    $product->refresh();
    expect($product->stock)->toBe(97);   // 100 - 3
    expect($product->stock_reserved)->toBe(47); // 50 - 3
});

test('process deliveries accumulates quantities delivered by previous routes', function () {
    [$route, $stop, $product, $routeStopItem, $order] = recCreateRouteReadyForReconciliation($this->store);
    $order->items()->first()->update(['quantity' => 100]);
    $routeStopItem->update([
        'quantity_planned' => 30,
        'quantity_loaded' => 30,
        'quantity_delivered' => 30,
    ]);
    $product->update(['stock' => 130, 'stock_reserved' => 30]);

    $previousRoute = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => recCreateVehicle($this->store)->id,
        'driver_id' => recCreateDriver($this->store)->id,
        'operational_date' => now()->format('Y-m-d'),
        'status' => 'completed',
    ]);
    $previousStop = RouteStop::create([
        'route_id' => $previousRoute->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'completed',
    ]);
    RouteStopItem::create([
        'route_stop_id' => $previousStop->id,
        'product_id' => $product->id,
        'quantity_planned' => 70,
        'quantity_loaded' => 70,
        'quantity_delivered' => 70,
    ]);
    $order->update(['status' => 'partially_delivered']);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/process-deliveries")
        ->assertOk();

    expect($order->fresh()->status)->toBe('delivered')
        ->and($product->fresh()->stock)->toBe(100)
        ->and($product->fresh()->stock_reserved)->toBe(0);
});

test('failed stop: no stock changes and order status unchanged', function () {
    $vehicle = recCreateVehicle($this->store);
    $driver = recCreateDriver($this->store);
    [$customer, $locality] = recCreateCustomerWithAddress($this->store);
    $product = Product::factory()->create([
        'store_id' => $this->store->id,
        'stock' => 100,
        'stock_reserved' => 50,
    ]);

    $date = now()->addDay()->format('Y-m-d');

    $order = recCreateEligibleOrder($this->store, $customer, ['requested_delivery_date' => $date]);
    OperationItem::factory()->create([
        'operation_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 10,
    ]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'awaiting_reconciliation',
    ]);

    // Stop with status 'failed' — delivered qty is 0
    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'failed',
    ]);

    RouteStopItem::create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
        'quantity_planned' => 5,
        'quantity_loaded' => 5,
        'quantity_delivered' => 0,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/process-deliveries");

    $response->assertStatus(200);

    $product->refresh();
    expect($product->stock)->toBe(100);
    expect($product->stock_reserved)->toBe(50);

    $order->refresh();
    expect($order->status)->toBe('confirmed');
});

test('not awaiting_reconciliation: route in dispatched status returns 422', function () {
    $vehicle = recCreateVehicle($this->store);
    $driver = recCreateDriver($this->store);
    [$customer, $locality] = recCreateCustomerWithAddress($this->store);
    $product = Product::factory()->create(['store_id' => $this->store->id]);

    $date = now()->addDay()->format('Y-m-d');

    $order = recCreateEligibleOrder($this->store, $customer, ['requested_delivery_date' => $date]);
    OperationItem::factory()->create([
        'operation_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 10,
    ]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'dispatched',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/process-deliveries");

    $response->assertStatus(422);
});

test('pending stops exist: returns 422 with error message', function () {
    $vehicle = recCreateVehicle($this->store);
    $driver = recCreateDriver($this->store);
    [$customer, $locality] = recCreateCustomerWithAddress($this->store);
    $product = Product::factory()->create(['store_id' => $this->store->id]);

    $date = now()->addDay()->format('Y-m-d');

    $order = recCreateEligibleOrder($this->store, $customer, ['requested_delivery_date' => $date]);
    OperationItem::factory()->create([
        'operation_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 10,
    ]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'awaiting_reconciliation',
    ]);

    // One completed stop
    RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'completed',
    ]);

    // One pending stop
    $order2 = recCreateEligibleOrder($this->store, $customer, ['requested_delivery_date' => $date]);
    RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order2->id,
        'sequence' => 2,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/process-deliveries");

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Hay paradas pendientes de completar.');
});

test('idempotency: second call returns 409', function () {
    [$route, $stop, $product, $routeStopItem, $order] = recCreateRouteReadyForReconciliation($this->store);

    // First call
    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/process-deliveries")
        ->assertStatus(200);

    // Second call — route already processed
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/process-deliveries");

    $response->assertStatus(409);
});

test('stock would go negative: transaction fails', function () {
    $vehicle = recCreateVehicle($this->store);
    $driver = recCreateDriver($this->store);
    [$customer, $locality] = recCreateCustomerWithAddress($this->store);

    // Product with very low stock — delivery of 5 would bring it negative
    $product = Product::factory()->create([
        'store_id' => $this->store->id,
        'stock' => 2,
        'stock_reserved' => 2,
    ]);

    $date = now()->addDay()->format('Y-m-d');

    $order = recCreateEligibleOrder($this->store, $customer, ['requested_delivery_date' => $date]);
    OperationItem::factory()->create([
        'operation_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 10,
    ]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'awaiting_reconciliation',
    ]);

    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'completed',
    ]);

    RouteStopItem::create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
        'quantity_planned' => 5,
        'quantity_loaded' => 5,
        'quantity_delivered' => 5,
    ]);

    // Transaction should fail: stock 2 - output 5 → -3 → throws
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/process-deliveries");

    // The service should throw inside a transaction, caught by exception handler
    $response->assertStatus(422)
        ->assertJsonPath('message', 'El stock resultante no puede ser negativo.');

    // Verify nothing was persisted
    $route->refresh();
    expect($route->status)->toBe('awaiting_reconciliation');
    expect($route->processed_at)->toBeNull();
});

test('multi-tenant: cannot process route from another store', function () {
    [$route, $stop, $product, $routeStopItem, $order] = recCreateRouteReadyForReconciliation($this->store);

    // Another store and user
    $otherStore = Store::factory()->create();
    $otherPlan = Plan::factory()->create();
    $deliveriesFeature = Feature::where('code', 'deliveries')->first();
    $otherPlan->features()->attach($deliveriesFeature->id);
    $otherStore->update(['plan_id' => $otherPlan->id]);

    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    $otherUser->assignRole('STORE_ADMIN');
    $otherUser->givePermissionTo(['logistics.routes.view', 'logistics.routes.manage']);
    $otherToken = $otherUser->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $otherToken")
        ->postJson("/api/v1/store/routes/{$route->id}/process-deliveries");

    $response->assertStatus(404);
});

test('route not found returns 404', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/routes/non-existent-id/process-deliveries');

    $response->assertStatus(404);
});

test('full delivery with multiple stops processes all correctly', function () {
    $vehicle = recCreateVehicle($this->store);
    $driver = recCreateDriver($this->store);
    [$customer1, $locality] = recCreateCustomerWithAddress($this->store);
    [$customer2, $locality2] = recCreateCustomerWithAddress($this->store);

    $product1 = Product::factory()->create([
        'store_id' => $this->store->id,
        'stock' => 100,
        'stock_reserved' => 60,
    ]);
    $product2 = Product::factory()->create([
        'store_id' => $this->store->id,
        'stock' => 50,
        'stock_reserved' => 20,
    ]);

    $date = now()->addDay()->format('Y-m-d');

    $order1 = recCreateEligibleOrder($this->store, $customer1, ['requested_delivery_date' => $date]);
    OperationItem::factory()->create([
        'operation_id' => $order1->id,
        'product_id' => $product1->id,
        'quantity' => 10,
    ]);

    $order2 = recCreateEligibleOrder($this->store, $customer2, ['requested_delivery_date' => $date]);
    OperationItem::factory()->create([
        'operation_id' => $order2->id,
        'product_id' => $product2->id,
        'quantity' => 8,
    ]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'awaiting_reconciliation',
    ]);

    $stop1 = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order1->id,
        'sequence' => 1,
        'status' => 'completed',
    ]);

    $stop2 = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order2->id,
        'sequence' => 2,
        'status' => 'completed',
    ]);

    RouteStopItem::create([
        'route_stop_id' => $stop1->id,
        'product_id' => $product1->id,
        'quantity_planned' => 10,
        'quantity_loaded' => 10,
        'quantity_delivered' => 10,
    ]);

    RouteStopItem::create([
        'route_stop_id' => $stop2->id,
        'product_id' => $product2->id,
        'quantity_planned' => 8,
        'quantity_loaded' => 8,
        'quantity_delivered' => 8,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/process-deliveries");

    $response->assertStatus(200);

    $product1->refresh();
    $product2->refresh();
    expect($product1->stock)->toBe(90);   // 100 - 10
    expect($product1->stock_reserved)->toBe(50); // 60 - 10
    expect($product2->stock)->toBe(42);   // 50 - 8
    expect($product2->stock_reserved)->toBe(12); // 20 - 8

    $order1->refresh();
    $order2->refresh();
    expect($order1->status)->toBe('delivered');
    expect($order2->status)->toBe('delivered');

    expect(InventoryMovement::count())->toBe(2);
});
