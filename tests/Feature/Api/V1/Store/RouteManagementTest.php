<?php

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryRoute;
use App\Models\DeliveryRouteEvent;
use App\Models\Feature;
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

    // Create all logistics permissions
    foreach (['logistics.routes.view', 'logistics.routes.manage', 'logistics.routes.plan', 'logistics.routes.revert', 'logistics.routes.cancel', 'logistics.routes.dispatch'] as $perm) {
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
        'logistics.routes.dispatch',
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
 * Create a customer with a geolocated delivery address.
 */
function createCustomerWithAddress(Store $store): Customer
{
    $province = Province::factory()->create();
    $locality = Locality::factory()->for($province)->create();

    $customer = Customer::factory()->create(['store_id' => $store->id]);
    CustomerAddress::factory()->forCustomer($customer)->for($locality)->asMain()->ofType('delivery')->create([
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

/**
 * Create a route with historical and/or operational stops for endpoint assertions.
 *
 * @param  array<int, string>  $statuses
 */
function createRouteWithStops(Store $store, array $statuses): DeliveryRoute
{
    $route = DeliveryRoute::factory()->create([
        'store_id' => $store->id,
        'vehicle_id' => createVehicle($store)->id,
        'driver_id' => createDriver($store)->id,
    ]);

    foreach ($statuses as $index => $status) {
        RouteStop::factory()->create([
            'route_id' => $route->id,
            'status' => $status,
            // Cancelled stops are historical and deliberately leave the active sequence space.
            'sequence' => $status === 'cancelled' ? null : $index + 1,
        ]);
    }

    return $route;
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
            'route_name' => '  Godoy Cruz - Las Heras  ',
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.operational_date', $date)
        ->assertJsonPath('data.observations', 'Ruta de prueba')
        ->assertJsonPath('data.route_name', 'Godoy Cruz - Las Heras');

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

test('filters routes by number, operational date range, driver and status', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $otherDriver = createDriver($this->store);
    $matchingRoute = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => '2026-11-10',
        'status' => 'planned',
    ]);
    DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $otherDriver->id,
        'operational_date' => '2026-11-10',
        'status' => 'planned',
    ]);
    DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => '2026-11-11',
        'status' => 'draft',
    ]);

    $routeNumber = strtoupper(substr($matchingRoute->id, 0, 8));
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes?route_number=%23{$routeNumber}&from=2026-11-10&to=2026-11-10&driver_id={$driver->id}&status=planned");

    $response->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.items.0.id', $matchingRoute->id);
});

test('filters routes by a case insensitive partial route name and keeps stores isolated', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $matching = DeliveryRoute::factory()->create(['store_id' => $this->store->id, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'route_name' => 'Godoy Cruz - Las Heras']);
    $otherStore = Store::factory()->create();
    DeliveryRoute::factory()->create(['store_id' => $otherStore->id, 'vehicle_id' => createVehicle($otherStore)->id, 'driver_id' => createDriver($otherStore)->id, 'route_name' => 'Godoy Centro']);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes?route_name=gOdOy')
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $matching->id);
});

test('sorts operational date consistently before paginating', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $routes = collect(['2026-11-10', '2026-11-12', '2026-11-12'])->map(fn ($date) => DeliveryRoute::factory()->create(['store_id' => $this->store->id, 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'operational_date' => $date]));

    $asc = $this->withHeader('Authorization', "Bearer $this->token")->getJson('/api/v1/store/routes?sort_dir=asc&per_page=2');
    $desc = $this->withHeader('Authorization', "Bearer $this->token")->getJson('/api/v1/store/routes?sort_dir=desc&per_page=2');

    $asc->assertOk()->assertJsonPath('data.items.0.id', $routes->sortBy('operational_date')->first()->id);
    $desc->assertOk()->assertJsonPath('data.items.0.operational_date', '2026-11-12');
    expect($desc->json('data.items.0.id'))->not->toBe($desc->json('data.items.1.id'));
});

test('validates route list filters and keeps results isolated by store', function () {
    $otherStore = Store::factory()->create();
    $otherVehicle = createVehicle($otherStore);
    $otherDriver = createDriver($otherStore);
    $otherRoute = DeliveryRoute::factory()->create([
        'store_id' => $otherStore->id,
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $otherDriver->id,
        'operational_date' => '2026-11-10',
        'status' => 'planned',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes?route_number=%23'.substr($otherRoute->id, 0, 8)."&driver_id={$otherDriver->id}");

    $response->assertOk()->assertJsonPath('data.total', 0);

    $invalid = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes?status=unknown&from=2026-11-11&to=2026-11-10');

    $invalid->assertStatus(422)->assertJsonValidationErrors(['status', 'to']);
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

test('reports pending stops as operational in route index and show', function () {
    $route = createRouteWithStops($this->store, ['pending', 'pending']);

    $index = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes');
    $show = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}");

    $index->assertOk()
        ->assertJsonPath('data.items.0.id', $route->id)
        ->assertJsonPath('data.items.0.operational_stops_count', 2);
    $show->assertOk()
        ->assertJsonPath('data.id', $route->id)
        ->assertJsonPath('data.operational_stops_count', 2);
});

test('reports completed stops as operational in route index and show', function () {
    $route = createRouteWithStops($this->store, ['completed', 'completed']);

    $index = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes');
    $show = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}");

    $index->assertOk()
        ->assertJsonPath('data.items.0.operational_stops_count', 2);
    $show->assertOk()
        ->assertJsonPath('data.operational_stops_count', 2);
});

test('preserves cancelled stop history while counting only operational stops in index and show', function () {
    $route = createRouteWithStops($this->store, [
        'cancelled', 'cancelled', 'cancelled', 'completed', 'completed',
    ]);

    $index = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes');
    $show = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}");

    $index->assertOk()
        ->assertJsonCount(5, 'data.items.0.stops')
        ->assertJsonPath('data.items.0.operational_stops_count', 2);
    $show->assertOk()
        ->assertJsonCount(5, 'data.stops')
        ->assertJsonPath('data.operational_stops_count', 2);
});

test('keeps removed stop history out of the operational count after replanning', function () {
    $route = createRouteWithStops($this->store, ['cancelled', 'cancelled', 'pending', 'pending']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}");

    $response->assertOk()
        ->assertJsonCount(4, 'data.stops')
        ->assertJsonPath('data.operational_stops_count', 2);

    expect(RouteStop::where('route_id', $route->id)->where('status', 'cancelled')->count())->toBe(2);
});

test('keeps operational stop counts isolated by store in route index and show', function () {
    $storeARoute = createRouteWithStops($this->store, ['completed', 'cancelled']);
    $storeB = Store::factory()->create();
    $storeBRoute = createRouteWithStops($storeB, ['pending', 'pending', 'pending']);

    $index = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes');
    $showOwn = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$storeARoute->id}");
    $showOtherStore = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$storeBRoute->id}");

    $index->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.id', $storeARoute->id)
        ->assertJsonPath('data.items.0.operational_stops_count', 1);
    $showOwn->assertOk()
        ->assertJsonPath('data.operational_stops_count', 1);
    $showOtherStore->assertNotFound();
});

test('dispatch response preserves order customer and address in route stops', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $address = $customer->addresses()->with('locality')->where('is_main', true)->firstOrFail();
    $order = createEligibleOrder($this->store, $customer);
    $product = Product::factory()->create(['store_id' => $this->store->id]);

    OperationItem::factory()->create([
        'operation_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
    ]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'loaded',
        'loaded_at' => now(),
        'loaded_by' => $this->user->id,
    ]);
    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);
    RouteStopItem::create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
        'quantity_planned' => 1,
        'quantity_loaded' => 1,
        'quantity_delivered' => 0,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/dispatch");

    $response->assertOk()
        ->assertJsonPath('data.status', 'dispatched')
        ->assertJsonPath('data.stops.0.order.id', $order->id)
        ->assertJsonPath('data.stops.0.order.operation_number', $order->operation_number)
        ->assertJsonPath('data.stops.0.order.customer.name', $customer->display_name)
        ->assertJsonPath('data.stops.0.order.address.street', $address->street)
        ->assertJsonPath('data.stops.0.order.address.number', $address->number)
        ->assertJsonPath('data.stops.0.order.address.locality', $address->locality->name);

    expect($stop->fresh()->order_id)->toBe($order->id)
        ->and($stop->fresh()->order->customer_id)->toBe($customer->id);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'dispatched')
        ->assertJsonPath('data.stops.0.order.operation_number', $order->operation_number)
        ->assertJsonPath('data.stops.0.order.customer.name', $customer->display_name)
        ->assertJsonPath('data.stops.0.order.address.street', $address->street);
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
            'route_name' => '  Ruta actualizada  ',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.observations', 'Updated observations')
        ->assertJsonPath('data.route_name', 'Ruta actualizada');

    expect($route->fresh()->observations)->toBe('Updated observations');
    expect($route->fresh()->route_name)->toBe('Ruta actualizada');
});

test('validates the route name maximum length', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson('/api/v1/store/routes', ['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id, 'operational_date' => now()->addDay()->format('Y-m-d'), 'route_name' => str_repeat('a', 101)])
        ->assertStatus(422)->assertJsonValidationErrors('route_name');
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
    expect($stop->customer_address_id)->toBe($customer->addresses()->where('type', 'delivery')->value('id'));
    expect($stop->destination_latitude)->toEqual('-34.60370000');
    expect($stop->destination_longitude)->toEqual('-58.38160000');

    expect(DeliveryRouteEvent::where('event_type', 'stop_added')->count())->toBe(1);
});

test('uses a non-main delivery address and preserves it as a stop snapshot', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $deliveryAddress = $customer->addresses()->where('type', 'delivery')->firstOrFail();
    $deliveryAddress->update([
        'is_main' => false,
        'street' => 'Destino Entrega',
        'observations' => 'Casa de color marrón con blanco.',
    ]);
    CustomerAddress::factory()->forCustomer($customer)->asMain()->ofType('billing')->create([
        'latitude' => -34.70,
        'longitude' => -58.50,
        'street' => 'Principal Cobranza',
    ]);
    $order = createEligibleOrder($this->store, $customer);
    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);
    $order->update(['requested_delivery_date' => $route->operational_date]);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/stops", ['order_id' => $order->id])
        ->assertCreated();

    $stop = RouteStop::firstOrFail();
    expect($stop->customer_address_id)->toBe($deliveryAddress->id)
        ->and($stop->destination_street)->toBe('Destino Entrega')
        ->and($stop->destination_observations)->toBe('Casa de color marrón con blanco.')
        ->and((float) $stop->destination_latitude)->toBe(-34.6037)
        ->and((float) $stop->destination_longitude)->toBe(-58.3816);

    $deliveryAddress->update([
        'street' => 'Entrega modificada',
        'latitude' => -34.80,
        'longitude' => -58.80,
        'observations' => 'Referencia modificada',
    ]);
    $customer->addresses()->where('type', 'billing')->update(['is_main' => false]);

    $stop->refresh();
    expect($stop->destination_street)->toBe('Destino Entrega')
        ->and($stop->destination_observations)->toBe('Casa de color marrón con blanco.')
        ->and((float) $stop->destination_latitude)->toBe(-34.6037)
        ->and((float) $stop->destination_longitude)->toBe(-58.3816);
});

test('rejects assignment when the customer has no valid delivery address', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = Customer::factory()->create(['store_id' => $this->store->id]);
    CustomerAddress::factory()->forCustomer($customer)->asMain()->ofType('billing')->create([
        'latitude' => -34.6037,
        'longitude' => -58.3816,
    ]);
    $order = createEligibleOrder($this->store, $customer);
    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);
    $order->update(['requested_delivery_date' => $route->operational_date]);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/stops", ['order_id' => $order->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'El cliente debe tener un domicilio de Entrega con ubicación geolocalizada para asignar este pedido a una ruta.');
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

test('rejects duplicate order in same route', function () {
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

    // Try adding same order to SAME route — should be rejected
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/stops", [
            'order_id' => $order->id,
            'reason' => 'test',
        ]);

    $response->assertStatus(422);
});

test('allows same order in different active routes', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $order = createEligibleOrder($this->store, $customer);

    // Route 1
    $route1 = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $order->update(['requested_delivery_date' => $route1->operational_date->format('Y-m-d')]);

    RouteStop::create([
        'route_id' => $route1->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    // Route 2 — same order, different route
    $route2 = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDays(2)->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route2->id}/stops", [
            'order_id' => $order->id,
            'reason' => 'adelanto acordado',
        ]);

    $response->assertStatus(201);
    expect(RouteStop::where('order_id', $order->id)->where('status', '!=', 'cancelled')->count())->toBe(2);
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

test('allows canceling last active stop when route is draft', function () {
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

    $response->assertStatus(200);
    expect($stop->fresh()->status)->toBe('cancelled');
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

test('eligible orders excludes customers without a geolocated delivery address', function () {
    $eligibleCustomer = createCustomerWithAddress($this->store);
    $eligibleOrder = createEligibleOrder($this->store, $eligibleCustomer);

    $province = Province::factory()->create();
    $locality = Locality::factory()->for($province)->create();
    $invalidCustomer = Customer::factory()->create(['store_id' => $this->store->id]);
    CustomerAddress::factory()->forCustomer($invalidCustomer)->for($locality)->ofType('delivery')->create([
        'latitude' => null,
        'longitude' => null,
    ]);
    $invalidOrder = createEligibleOrder($this->store, $invalidCustomer);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes/eligible-orders');

    $response->assertStatus(200)
        ->assertJsonPath('data.total', 1);

    $orderIds = array_column($response->json('data.items'), 'id');
    expect($orderIds)->toContain($eligibleOrder->id)
        ->not->toContain($invalidOrder->id);
});

test('eligible orders includes orders already in active routes (split allowed)', function () {
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
    // Should return BOTH orders (splitting across routes is allowed)
    $items = $response->json('data.items');
    $orderIds = array_column($items, 'id');
    expect($orderIds)->toContain($order1->id);
    expect($orderIds)->toContain($order2->id);
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
