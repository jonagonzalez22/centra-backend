<?php

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryRoute;
use App\Models\DeliveryRouteEvent;
use App\Models\Feature;
use App\Models\Locality;
use App\Models\Plan;
use App\Models\Province;
use App\Models\RouteStop;
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

    // Create all logistics permissions
    foreach (['logistics.routes.view', 'logistics.routes.manage', 'logistics.routes.plan', 'logistics.routes.revert', 'logistics.routes.cancel'] as $perm) {
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
        'logistics.routes.plan',
        'logistics.routes.revert',
        'logistics.routes.cancel',
    ]);
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

// ── Helpers ──────────────────────────────────────────────────────────

/**
 * Create a driver user in the test store.
 */
function createDriver(Store $store): User
{
    $driver = User::factory()->create(['store_id' => $store->id]);
    $driver->assignRole('STORE_DRIVER');
    return $driver;
}

/**
 * Create a vehicle in the test store.
 */
function createVehicle(Store $store): Vehicle
{
    return Vehicle::factory()->forStore($store)->create(['is_active' => true]);
}

/**
 * Create a customer with a geolocated main address.
 */
function createCustomerWithAddress(Store $store): Customer
{
    $province = Province::factory()->create();
    $locality = Locality::factory()->for($province)->create();

    $customer = Customer::factory()->create(['store_id' => $store->id]);
    CustomerAddress::factory()->forCustomer($customer)->for($locality)->asMain()->create([
        'latitude' => -34.6037,
        'longitude' => -58.3816,
    ]);

    return $customer;
}

/**
 * Create an eligible order (type=order, not cancelled, geolocated customer).
 */
function createEligibleOrder(Store $store, Customer $customer, array $attrs = []): \App\Models\CommercialOperation
{
    return \App\Models\CommercialOperation::factory()
        ->forStore($store)
        ->for($store->users()->first() ?? User::factory()->create(['store_id' => $store->id]), 'user')
        ->forCustomer($customer)
        ->order()
        ->create(array_merge([
            'status' => 'confirmed',
            'requested_delivery_date' => now()->addDays(1)->format('Y-m-d'),
        ], $attrs));
}

// ── CRUD Tests ───────────────────────────────────────────────────────

test('creates a new delivery route', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $date = now()->addDay()->format('Y-m-d');

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/routes', [
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'operational_date' => $date,
            'observations' => 'Ruta de prueba',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.operational_date', $date)
        ->assertJsonPath('data.observations', 'Ruta de prueba');

    expect(DeliveryRoute::count())->toBe(1);
    expect(DeliveryRoute::first()->store_id)->toBe($this->store->id);
    expect(DeliveryRouteEvent::count())->toBe(1);
    expect(DeliveryRouteEvent::first()->event_type)->toBe('created');
});

test('rejects route creation with missing required fields', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/routes', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['vehicle_id', 'driver_id', 'operational_date']);
});

test('rejects route with past operational date', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/routes', [
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'operational_date' => now()->subDay()->format('Y-m-d'),
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('operational_date');
});

test('rejects double booking same vehicle same date', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $date = now()->addDay()->format('Y-m-d');

    // First route
    DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'draft',
    ]);

    // Second route — same vehicle, same date
    $driver2 = createDriver($this->store);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/routes', [
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver2->id,
            'operational_date' => $date,
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'El vehículo ya tiene una ruta activa en esta fecha.');
});

test('lists routes scoped to store', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);

    DeliveryRoute::factory()->count(3)->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]);

    // Create route in another store
    $otherStore = Store::factory()->create();
    $otherVehicle = createVehicle($otherStore);
    $otherDriver = createDriver($otherStore);
    DeliveryRoute::factory()->create([
        'store_id' => $otherStore->id,
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $otherDriver->id,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes');

    $response->assertStatus(200)
        ->assertJsonCount(3, 'data.items')
        ->assertJsonPath('data.total', 3);
});

test('filters routes by status', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);

    DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => 'draft',
    ]);
    DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => 'planned',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes?status=draft');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items');
});

test('shows a single route with stops and events', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.id', $route->id)
        ->assertJsonPath('data.status', 'draft');
});

test('returns 404 for route from another store', function () {
    $otherStore = Store::factory()->create();
    $otherVehicle = createVehicle($otherStore);
    $otherDriver = createDriver($otherStore);

    $otherRoute = DeliveryRoute::create([
        'store_id' => $otherStore->id,
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $otherDriver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$otherRoute->id}");

    $response->assertStatus(404);
});

test('updates a draft route', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/routes/{$route->id}", [
            'observations' => 'Updated observations',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.observations', 'Updated observations');

    expect($route->fresh()->observations)->toBe('Updated observations');
});

// ── Stop Management Tests ────────────────────────────────────────────

test('adds a stop to a draft route', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $order = createEligibleOrder($this->store, $customer, [
        'requested_delivery_date' => now()->addDays(2)->format('Y-m-d'),
    ]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDays(2)->format('Y-m-d'),
        'status' => 'draft',
    ]);

    // Need to use the same date so it's not exceptional
    $order->update(['requested_delivery_date' => $route->operational_date->format('Y-m-d')]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/stops", [
            'order_id' => $order->id,
        ]);

    $response->assertStatus(201);

    expect(RouteStop::count())->toBe(1);
    $stop = RouteStop::first();
    expect($stop->route_id)->toBe($route->id);
    expect($stop->order_id)->toBe($order->id);
    expect($stop->sequence)->toBe(1);
    expect($stop->status)->toBe('pending');

    expect(DeliveryRouteEvent::where('event_type', 'stop_added')->count())->toBe(1);
});

test('adds stop with exceptional assignment when dates differ', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $order = createEligibleOrder($this->store, $customer, [
        'requested_delivery_date' => now()->addDays(1)->format('Y-m-d'),
    ]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDays(2)->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/stops", [
            'order_id' => $order->id,
            'reason' => 'El cliente pidió adelantar la entrega',
        ]);

    $response->assertStatus(201);

    $event = DeliveryRouteEvent::where('event_type', 'stop_added')->first();
    expect($event->metadata['exceptional'])->toBeTrue();
    expect($event->metadata['reason'])->toBe('El cliente pidió adelantar la entrega');
});

test('rejects stop with exceptional assignment without reason', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $order = createEligibleOrder($this->store, $customer, [
        'requested_delivery_date' => now()->addDays(1)->format('Y-m-d'),
    ]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDays(2)->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/stops", [
            'order_id' => $order->id,
        ]);

    $response->assertStatus(422);
});

test('rejects duplicate order in active route', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $order = createEligibleOrder($this->store, $customer);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $order->update(['requested_delivery_date' => $route->operational_date->format('Y-m-d')]);

    // First stop
    RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    // Try adding same order again
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/stops", [
            'order_id' => $order->id,
            'reason' => 'test',
        ]);

    $response->assertStatus(422);
});

test('rejects ineligible order (wrong store)', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);

    $otherStore = Store::factory()->create();
    $otherCustomer = createCustomerWithAddress($otherStore);
    $otherOrder = createEligibleOrder($otherStore, $otherCustomer);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    // The order belongs to another store, so the controller returns 404
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/stops", [
            'order_id' => $otherOrder->id,
            'reason' => 'test',
        ]);

    $response->assertStatus(404);
});

test('cancels a stop from a draft route', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $order1 = createEligibleOrder($this->store, $customer);
    $order2 = createEligibleOrder($this->store, $customer);

    $date = now()->addDay()->format('Y-m-d');
    $order1->update(['requested_delivery_date' => $date]);
    $order2->update(['requested_delivery_date' => $date]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'draft',
    ]);

    $stop1 = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order1->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $stop2 = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order2->id,
        'sequence' => 2,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/routes/{$route->id}/stops/{$stop1->id}", [
            'reason' => 'Cliente canceló',
        ]);

    $response->assertStatus(200);

    expect($stop1->fresh()->status)->toBe('cancelled');
    expect(DeliveryRouteEvent::where('event_type', 'stop_removed')->count())->toBe(1);

    // Check sequences renormalized
    expect($stop2->fresh()->sequence)->toBe(1);
});

test('rejects canceling last active stop', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $order = createEligibleOrder($this->store, $customer);

    $date = now()->addDay()->format('Y-m-d');
    $order->update(['requested_delivery_date' => $date]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'draft',
    ]);

    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->deleteJson("/api/v1/store/routes/{$route->id}/stops/{$stop->id}", [
            'reason' => 'Cancelar último',
        ]);

    $response->assertStatus(422);
});

test('reorders stops atomically', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $orderA = createEligibleOrder($this->store, $customer);
    $orderB = createEligibleOrder($this->store, $customer);
    $orderC = createEligibleOrder($this->store, $customer);

    $date = now()->addDay()->format('Y-m-d');
    $orderA->update(['requested_delivery_date' => $date]);
    $orderB->update(['requested_delivery_date' => $date]);
    $orderC->update(['requested_delivery_date' => $date]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'draft',
    ]);

    $stopA = RouteStop::create(['route_id' => $route->id, 'order_id' => $orderA->id, 'sequence' => 1, 'status' => 'pending']);
    $stopB = RouteStop::create(['route_id' => $route->id, 'order_id' => $orderB->id, 'sequence' => 2, 'status' => 'pending']);
    $stopC = RouteStop::create(['route_id' => $route->id, 'order_id' => $orderC->id, 'sequence' => 3, 'status' => 'pending']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/routes/{$route->id}/stops/reorder", [
            'stop_ids' => [$stopC->id, $stopA->id, $stopB->id],
        ]);

    $response->assertStatus(200);

    expect($stopA->fresh()->sequence)->toBe(2);
    expect($stopB->fresh()->sequence)->toBe(3);
    expect($stopC->fresh()->sequence)->toBe(1);

    expect(DeliveryRouteEvent::where('event_type', 'stops_reordered')->count())->toBe(1);
});

test('rejects reorder with missing stops', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $orderA = createEligibleOrder($this->store, $customer);
    $orderB = createEligibleOrder($this->store, $customer);

    $date = now()->addDay()->format('Y-m-d');
    $orderA->update(['requested_delivery_date' => $date]);
    $orderB->update(['requested_delivery_date' => $date]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'draft',
    ]);

    $stopA = RouteStop::create(['route_id' => $route->id, 'order_id' => $orderA->id, 'sequence' => 1, 'status' => 'pending']);
    RouteStop::create(['route_id' => $route->id, 'order_id' => $orderB->id, 'sequence' => 2, 'status' => 'pending']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/routes/{$route->id}/stops/reorder", [
            'stop_ids' => [$stopA->id],
        ]);

    $response->assertStatus(422);
});

// ── Status Transition Tests ─────────────────────────────────────────
test('plans a draft route with stops', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);

    // Store needs coordinates for plan to work with Google API
    $this->store->update(['latitude' => -34.6037, 'longitude' => -58.3816]);

    $customer = createCustomerWithAddress($this->store);
    $order = createEligibleOrder($this->store, $customer);

    $date = now()->addDay()->format('Y-m-d');
    $order->update(['requested_delivery_date' => $date]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'draft',
    ]);

    RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    // Mock Google API response for single stop
    \Illuminate\Support\Facades\Http::fake([
        'routes.googleapis.com/*' => \Illuminate\Support\Facades\Http::response([
            'routes' => [[
                'optimizedIntermediateWaypointIndex' => [0],
                'legs' => [
                    ['duration' => '600s', 'polyline' => ['encodedPolyline' => 'abc123']],
                    ['duration' => '300s', 'polyline' => ['encodedPolyline' => 'def456']],
                ],
            ]],
        ], 200),
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/plan", [
            'departure_time' => '08:00',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'planned');

    expect($route->fresh()->status)->toBe('planned');
    expect(DeliveryRouteEvent::where('event_type', 'planned')->count())->toBe(1);
});

test('rejects plan with no stops', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);

    $this->store->update(['latitude' => -34.6037, 'longitude' => -58.3816]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/plan", [
            'departure_time' => '08:00',
        ]);

    $response->assertStatus(422);
});

test('reverts a planned route to draft', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $order = createEligibleOrder($this->store, $customer);

    $date = now()->addDay()->format('Y-m-d');
    $order->update(['requested_delivery_date' => $date]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'planned',
    ]);

    RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/revert", [
            'reason' => 'Necesito ajustar los stops',
            'observation' => 'Revisar orden de entregas',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'draft');

    expect($route->fresh()->status)->toBe('draft');
    expect(DeliveryRouteEvent::where('event_type', 'reverted')->count())->toBe(1);
    expect(DeliveryRouteEvent::where('event_type', 'reverted')->first()->from_status)->toBe('planned');
    expect(DeliveryRouteEvent::where('event_type', 'reverted')->first()->to_status)->toBe('draft');
});

test('rejects revert without reason', function () {
    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => createVehicle($this->store)->id,
        'driver_id' => createDriver($this->store)->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'planned',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/revert", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

test('rejects revert of non-planned route', function () {
    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => createVehicle($this->store)->id,
        'driver_id' => createDriver($this->store)->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/revert", [
            'reason' => 'testing',
        ]);

    $response->assertStatus(422);
});

test('cancels a draft route and frees all stops', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $order1 = createEligibleOrder($this->store, $customer);
    $order2 = createEligibleOrder($this->store, $customer);

    $date = now()->addDay()->format('Y-m-d');
    $order1->update(['requested_delivery_date' => $date]);
    $order2->update(['requested_delivery_date' => $date]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'draft',
    ]);

    $stop1 = RouteStop::create(['route_id' => $route->id, 'order_id' => $order1->id, 'sequence' => 1, 'status' => 'pending']);
    $stop2 = RouteStop::create(['route_id' => $route->id, 'order_id' => $order2->id, 'sequence' => 2, 'status' => 'pending']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/cancel", [
            'reason' => 'Ruta no necesaria',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled');

    expect($route->fresh()->status)->toBe('cancelled');
    expect($stop1->fresh()->status)->toBe('cancelled');
    expect($stop2->fresh()->status)->toBe('cancelled');
    expect(DeliveryRouteEvent::where('event_type', 'cancelled')->count())->toBe(1);
});

test('cancels a planned route', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $order = createEligibleOrder($this->store, $customer);

    $date = now()->addDay()->format('Y-m-d');
    $order->update(['requested_delivery_date' => $date]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'planned',
    ]);

    RouteStop::create(['route_id' => $route->id, 'order_id' => $order->id, 'sequence' => 1, 'status' => 'pending']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/cancel", [
            'reason' => 'Emergencia',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled');
});

test('rejects cancel without reason', function () {
    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => createVehicle($this->store)->id,
        'driver_id' => createDriver($this->store)->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/cancel", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

// ── Eligible Orders Tests ────────────────────────────────────────────

test('returns eligible orders', function () {
    $customer1 = createCustomerWithAddress($this->store);
    $customer2 = createCustomerWithAddress($this->store);
    $order1 = createEligibleOrder($this->store, $customer1);
    $order2 = createEligibleOrder($this->store, $customer2);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes/eligible-orders');

    $response->assertStatus(200)
        ->assertJsonPath('data.total', 2);
});

test('eligible orders excludes orders already in active routes', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $order1 = createEligibleOrder($this->store, $customer);
    $order2 = createEligibleOrder($this->store, $customer);

    $date = now()->addDay()->format('Y-m-d');
    $order1->update(['requested_delivery_date' => $date]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'draft',
    ]);

    // Order 1 is already in a route
    RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order1->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes/eligible-orders');

    $response->assertStatus(200);
    // Should only return order2 (order1 is already assigned)
    $items = $response->json('data.items');
    $orderIds = array_column($items, 'id');
    expect($orderIds)->not->toContain($order1->id);
});

test('eligible orders filters by requested_delivery_date', function () {
    $customer = createCustomerWithAddress($this->store);

    $order1 = createEligibleOrder($this->store, $customer, [
        'requested_delivery_date' => '2026-08-01',
    ]);
    $order2 = createEligibleOrder($this->store, $customer, [
        'requested_delivery_date' => '2026-08-15',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes/eligible-orders?requested_delivery_date=2026-08-01');

    $response->assertStatus(200)
        ->assertJsonCount(1, 'data.items');
});

// ── Authorization Tests ─────────────────────────────────────────────

test('returns 403 when user lacks permission', function () {
    $userNoPerm = User::factory()->create(['store_id' => $this->store->id]);
    $userNoPerm->assignRole('STORE_ADMIN');
    // No logistics permissions assigned
    $tokenNoPerm = $userNoPerm->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $tokenNoPerm")
        ->postJson('/api/v1/store/routes', [
            'vehicle_id' => createVehicle($this->store)->id,
            'driver_id' => createDriver($this->store)->id,
            'operational_date' => now()->addDay()->format('Y-m-d'),
        ]);

    $response->assertStatus(403);
});

test('returns 403 when deliveries feature is disabled', function () {
    // Remove deliveries feature from store's plan
    $this->store->plan->features()->detach(
        Feature::where('code', 'deliveries')->first()->id
    );

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes');

    $response->assertStatus(403);
});

test('events are immutable — no updated_at column', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);

    // Use API endpoint to create route, which also creates a 'created' event
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/routes', [
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'operational_date' => now()->addDay()->format('Y-m-d'),
        ]);

    $routeId = $response->json('data.id');
    $event = DeliveryRouteEvent::where('route_id', $routeId)->first();
    expect($event)->not->toBeNull();
    expect($event->getUpdatedAtColumn())->toBeNull();
});
