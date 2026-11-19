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
use App\Models\StoreSetting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_DRIVER', 'guard_name' => 'web']);

    foreach (['logistics.routes.view', 'logistics.routes.manage', 'logistics.routes.plan', 'logistics.routes.revert', 'logistics.routes.cancel'] as $perm) {
        Permission::create(['name' => $perm, 'guard_name' => 'web']);
    }

    $this->store = Store::factory()->create([
        'latitude' => -34.6037,
        'longitude' => -58.3816,
    ]);

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

// ── Helpers (closures to avoid redeclaration) ────────────────────────

function helperCreateDriver(Store $store): User
{
    $driver = User::factory()->create(['store_id' => $store->id]);
    $driver->assignRole('STORE_DRIVER');

    return $driver;
}

function helperCreateVehicle(Store $store): Vehicle
{
    return Vehicle::factory()->forStore($store)->create(['is_active' => true]);
}

function helperCreateCustomerWithAddress(Store $store, float $lat = -34.6037, float $lng = -58.3816): Customer
{
    $province = Province::factory()->create();
    $locality = Locality::factory()->for($province)->create();

    $customer = Customer::factory()->create(['store_id' => $store->id]);
    CustomerAddress::factory()->forCustomer($customer)->for($locality)->asMain()->create([
        'latitude' => $lat,
        'longitude' => $lng,
    ]);

    return $customer;
}

function helperCreateEligibleOrder(Store $store, Customer $customer, array $attrs = []): \App\Models\CommercialOperation
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

function mockGoogleApiSuccess(array $optimizedIndices = [0]): void
{
    $legs = [];
    $count = count($optimizedIndices) + 1;
    for ($i = 0; $i < $count; $i++) {
        $legs[] = [
            'duration' => (600 + $i * 120).'s',
            'polyline' => ['encodedPolyline' => 'abc'.$i.'def'],
        ];
    }

    Http::fake([
        'routes.googleapis.com/*' => Http::response([
            'routes' => [[
                'optimizedIntermediateWaypointIndex' => $optimizedIndices,
                'legs' => $legs,
                'polyline' => ['encodedPolyline' => 'global_polyline_abc123'],
            ]],
        ], 200),
    ]);
}

test('map geometry is requested on demand in persisted stop order and is not stored', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);
    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => 'planned',
    ]);
    $firstOrder = helperCreateEligibleOrder(
        $this->store,
        helperCreateCustomerWithAddress($this->store, -34.6033, -58.3815)
    );
    $secondOrder = helperCreateEligibleOrder(
        $this->store,
        helperCreateCustomerWithAddress($this->store, -34.6044, -58.3994)
    );
    RouteStop::create(['route_id' => $route->id, 'order_id' => $firstOrder->id, 'sequence' => 1, 'status' => 'pending']);
    RouteStop::create(['route_id' => $route->id, 'order_id' => $secondOrder->id, 'sequence' => 2, 'status' => 'pending']);

    Http::fake([
        'routes.googleapis.com/*' => Http::response([
            'routes' => [['polyline' => ['encodedPolyline' => 'temporary_google_geometry']]],
        ], 200),
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}/map-geometry");

    $response->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.encoded_polyline', 'temporary_google_geometry');

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
        $payload = $request->data();

        return $request->url() === 'https://routes.googleapis.com/directions/v2:computeRoutes'
            && ! array_key_exists('optimizeWaypointOrder', $payload)
            && $payload['origin'] === $payload['destination']
            && $payload['routingPreference'] === 'TRAFFIC_UNAWARE'
            && $payload['intermediates'] === [
                ['location' => ['latLng' => ['latitude' => -34.6033, 'longitude' => -58.3815]]],
                ['location' => ['latLng' => ['latitude' => -34.6044, 'longitude' => -58.3994]]],
            ];
    });

    $route->refresh();
    expect($route->encoded_polyline)->toBeNull();
});

test('map geometry accepts exactly 25 persisted stops', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);
    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => 'planned',
    ]);
    $customer = helperCreateCustomerWithAddress($this->store);

    for ($sequence = 1; $sequence <= 25; $sequence++) {
        $order = helperCreateEligibleOrder($this->store, $customer);
        RouteStop::create([
            'route_id' => $route->id,
            'order_id' => $order->id,
            'sequence' => $sequence,
            'status' => 'pending',
        ]);
    }

    Http::fake([
        'routes.googleapis.com/*' => Http::response([
            'routes' => [['polyline' => ['encodedPolyline' => 'geometry_for_25_stops']]],
        ], 200),
    ]);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}/map-geometry")
        ->assertOk()
        ->assertJsonPath('data.encoded_polyline', 'geometry_for_25_stops');

    Http::assertSent(fn (\Illuminate\Http\Client\Request $request) => count($request->data()['intermediates']) === 25);
});

test('map geometry rejects 26 stops before calling Google', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);
    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => 'planned',
    ]);
    $customer = helperCreateCustomerWithAddress($this->store);

    for ($sequence = 1; $sequence <= 26; $sequence++) {
        $order = helperCreateEligibleOrder($this->store, $customer);
        RouteStop::create([
            'route_id' => $route->id,
            'order_id' => $order->id,
            'sequence' => $sequence,
            'status' => 'pending',
        ]);
    }

    Http::fake();

    $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}/map-geometry")
        ->assertStatus(503)
        ->assertJsonPath(
            'message',
            'La ruta tiene más de 25 paradas. No se puede mostrar el recorrido vial todavía.'
        );

    Http::assertNothingSent();
});

test('map geometry is tenant scoped and does not call Google for another store route', function () {
    $otherStore = Store::factory()->create();
    $route = DeliveryRoute::factory()->create(['store_id' => $otherStore->id]);
    Http::fake();

    $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}/map-geometry")
        ->assertNotFound();

    Http::assertNothingSent();
});

test('map geometry returns a provider error without persisting a polyline', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);
    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => 'planned',
    ]);
    $order = helperCreateEligibleOrder($this->store, helperCreateCustomerWithAddress($this->store));
    RouteStop::create(['route_id' => $route->id, 'order_id' => $order->id, 'sequence' => 1, 'status' => 'pending']);
    Http::fake(['routes.googleapis.com/*' => Http::response([], 500)]);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}/map-geometry")
        ->assertStatus(503)
        ->assertJsonPath('status', 'error');

    expect($route->fresh()->encoded_polyline)->toBeNull();
});

// ── Model Tests ──────────────────────────────────────────────────────

test('store setting defaults to 15 minutes unload time', function () {
    $setting = StoreSetting::factory()->create(['store_id' => $this->store->id]);

    expect($setting->delivery_unload_time_minutes)->toBe(15);
});

test('store setting belongs to store', function () {
    $setting = StoreSetting::factory()->create(['store_id' => $this->store->id]);

    expect($setting->store)->not->toBeNull();
    expect($setting->store->id)->toBe($this->store->id);
});

test('store getUnloadTimeMinutes returns setting value when exists', function () {
    StoreSetting::factory()->create([
        'store_id' => $this->store->id,
        'delivery_unload_time_minutes' => 20,
    ]);

    expect($this->store->getUnloadTimeMinutes())->toBe(20);
});

test('store getUnloadTimeMinutes returns default 15 when no setting', function () {
    expect($this->store->getUnloadTimeMinutes())->toBe(15);
});

test('delivery route keeps operational optimization fields without making provider geometry fillable', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
        'planned_at' => now(),
        'departure_time' => '08:00',
        'unload_time_minutes_snapshot' => 15,
        'requires_recalculation' => true,
    ]);

    expect($route->departure_time)->toBe('08:00');
    expect($route->getFillable())->not->toContain('encoded_polyline');
    expect($route->unload_time_minutes_snapshot)->toBe(15);
    expect($route->requires_recalculation)->toBeTrue();
});

test('route stop has eta columns fillable and castable', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $customer = helperCreateCustomerWithAddress($this->store);
    $order = helperCreateEligibleOrder($this->store, $customer);

    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
        'estimated_arrival_at' => now()->addHours(1),
        'travel_duration_seconds' => 600,
    ]);

    expect($stop->estimated_arrival_at)->not->toBeNull();
    expect($stop->travel_duration_seconds)->toBe(600);
});

// ── RouteOptimizationService Tests ───────────────────────────────────

test('optimizeRoute builds correct payload and parses response', function () {
    mockGoogleApiSuccess([1, 0]);

    $service = new \App\Services\RouteOptimizationService;

    $origin = [-34.6037, -58.3816];
    $destination = [-34.6037, -58.3816];
    $intermediates = [
        [-34.6033, -58.3815],
        [-34.6044, -58.3994],
    ];

    $result = $service->optimizeRoute($origin, $destination, $intermediates);

    expect($result['optimizedOrder'])->toBe([1, 0]);
    expect($result['polyline'])->not->toBeNull();
    expect($result['durations'])->toHaveCount(3); // origin→stop1→stop2→destination
});

test('optimizeRoute sends RFC3339 departureTime when provided', function () {
    mockGoogleApiSuccess([0]);
    $departure = \Carbon\CarbonImmutable::parse('2026-11-19T08:30:00-03:00');

    app(\App\Services\RouteOptimizationService::class)->optimizeRoute(
        [-34.6037, -58.3816], [-34.6037, -58.3816], [[-34.6033, -58.3815]], true, $departure
    );

    Http::assertSent(fn ($request) => ($request['departureTime'] ?? null) === '2026-11-19T11:30:00+00:00');
});

test('optimizeRoute preserves order when optimizeOrder is false', function () {
    $legs = [];
    for ($i = 0; $i < 3; $i++) {
        $legs[] = [
            'duration' => (600 + $i * 120).'s',
            'polyline' => ['encodedPolyline' => 'leg'.$i],
        ];
    }

    Http::fake([
        'routes.googleapis.com/*' => Http::response([
            'routes' => [[
                'legs' => $legs,
                'polyline' => ['encodedPolyline' => 'global_polyline_def456'],
            ]],
        ], 200),
    ]);

    $service = new \App\Services\RouteOptimizationService;

    $origin = [-34.6037, -58.3816];
    $destination = [-34.6037, -58.3816];
    $intermediates = [
        [-34.6033, -58.3815],
        [-34.6044, -58.3994],
    ];

    $result = $service->optimizeRoute($origin, $destination, $intermediates, false);

    expect($result['optimizedOrder'])->toBe([0, 1]);
    expect($result['durations'])->toHaveCount(3);
});

test('optimizeRoute accepts the Google limit of 25 intermediate stops', function () {
    mockGoogleApiSuccess(range(0, 24));

    $service = new \App\Services\RouteOptimizationService;
    $intermediates = array_fill(0, 25, [-34.6033, -58.3815]);

    $result = $service->optimizeRoute(
        [-34.6037, -58.3816],
        [-34.6037, -58.3816],
        $intermediates,
    );

    expect($result['optimizedOrder'])->toBe(range(0, 24));
    expect($result['durations'])->toHaveCount(26);
});

test('optimizeRoute rejects more than 25 intermediate stops before calling Google', function () {
    $service = new \App\Services\RouteOptimizationService;
    $intermediates = array_fill(0, 26, [-34.6033, -58.3815]);

    expect(fn () => $service->optimizeRoute(
        [-34.6037, -58.3816],
        [-34.6037, -58.3816],
        $intermediates,
    ))->toThrow(\App\Exceptions\RouteOptimizationException::class, 'más de 25 paradas');
});

test('optimizeRoute rejects a Google error response', function () {
    Http::fake([
        'routes.googleapis.com/*' => Http::response([
            'error' => ['code' => 503, 'status' => 'UNAVAILABLE'],
        ], 503),
    ]);

    $service = new \App\Services\RouteOptimizationService;

    expect(fn () => $service->optimizeRoute(
        [-34.6037, -58.3816],
        [-34.6037, -58.3816],
        [[-34.6033, -58.3815]],
    ))->toThrow(\App\Exceptions\RouteOptimizationException::class, 'No se pudo optimizar');
});

test('optimizeRoute rejects an empty Google response', function () {
    Http::fake(['routes.googleapis.com/*' => Http::response([], 200)]);

    $service = new \App\Services\RouteOptimizationService;

    expect(fn () => $service->optimizeRoute(
        [-34.6037, -58.3816],
        [-34.6037, -58.3816],
        [[-34.6033, -58.3815]],
    ))->toThrow(\App\Exceptions\RouteOptimizationException::class, 'no devolvió un recorrido válido');
});

test('optimizeRoute rejects duplicate or incomplete optimized waypoint indices', function (array $indices) {
    mockGoogleApiSuccess($indices);

    $service = new \App\Services\RouteOptimizationService;

    expect(fn () => $service->optimizeRoute(
        [-34.6037, -58.3816],
        [-34.6037, -58.3816],
        [
            [-34.6033, -58.3815],
            [-34.6044, -58.3994],
        ],
    ))->toThrow(\App\Exceptions\RouteOptimizationException::class, 'no devolvió un recorrido válido');
})->with([
    'duplicate indices' => [[0, 0]],
    'incomplete indices' => [[0]],
]);

test('calculateETAs returns correct arrival times', function () {
    $service = new \App\Services\RouteOptimizationService;

    $departureTime = '08:00';
    $durationsSeconds = [600, 450, 300];
    $unloadTimeMinutes = 15;
    $stopOrder = [0, 1, 2];

    $etas = $service->calculateETAs($departureTime, $durationsSeconds, $unloadTimeMinutes, $stopOrder, '2026-07-28');

    // stop 0: 2026-07-28 08:00 + 600s = 08:10:00
    // stop 1: + 450s + 15min unload = 08:32:30
    // stop 2: + 300s + 15min = 08:52:30
    expect($etas)->toHaveCount(3);
    expect($etas[0])->toBe('2026-07-28 08:10:00');
    expect($etas[1])->toBe('2026-07-28 08:32:30');
    expect($etas[2])->toBe('2026-07-28 08:52:30');
});

test('calculateETAs returns empty array for empty stops', function () {
    $service = new \App\Services\RouteOptimizationService;

    $etas = $service->calculateETAs('08:00', [], 15, [], '2026-07-28');

    expect($etas)->toBe([]);
});

// ── Route Plan API Tests ─────────────────────────────────────────────

test('plan route with optimization succeeds', function () {
    mockGoogleApiSuccess([2, 0, 1]);

    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $customer1 = helperCreateCustomerWithAddress($this->store, -34.6033, -58.3815);
    $customer2 = helperCreateCustomerWithAddress($this->store, -34.6044, -58.3994);
    $customer3 = helperCreateCustomerWithAddress($this->store, -34.6156, -58.4333);

    $order1 = helperCreateEligibleOrder($this->store, $customer1);
    $order2 = helperCreateEligibleOrder($this->store, $customer2);
    $order3 = helperCreateEligibleOrder($this->store, $customer3);

    RouteStop::insert([
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'route_id' => $route->id, 'order_id' => $order1->id, 'sequence' => 1, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'route_id' => $route->id, 'order_id' => $order2->id, 'sequence' => 2, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'route_id' => $route->id, 'order_id' => $order3->id, 'sequence' => 3, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
    ]);

    StoreSetting::factory()->create([
        'store_id' => $this->store->id,
        'delivery_unload_time_minutes' => 20,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/plan", [
            'departure_time' => '08:00',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'planned')
        ->assertJsonPath('data.requires_recalculation', false)
        ->assertJsonPath('data.departure_time', '08:00')
        ->assertJsonPath('data.unload_time_minutes_snapshot', 20);

    $route->refresh();
    expect($route->status)->toBe('planned');
    expect($route->encoded_polyline)->toBeNull();
    expect($route->planned_at)->not->toBeNull();
    expect($route->unload_time_minutes_snapshot)->toBe(20);
    expect($route->requires_recalculation)->toBeFalse();

    // Verify stops were reordered (Google returned [2, 0, 1])
    $stops = $route->stops()->orderBy('sequence')->get();
    expect($stops[0]->order_id)->toBe($order3->id); // index 2 moved to first
    expect($stops[1]->order_id)->toBe($order1->id); // index 0 moved to second
    expect($stops[2]->order_id)->toBe($order2->id); // index 1 moved to third

    // Verify ETAs were populated
    foreach ($stops as $stop) {
        expect($stop->estimated_arrival_at)->not->toBeNull();
        expect($stop->travel_duration_seconds)->toBeGreaterThan(0);
    }

    // Verify event was created
    expect(DeliveryRouteEvent::where('event_type', 'planned')->count())->toBe(1);
});

test('plan fails when store has no coordinates', function () {
    $this->store->update(['latitude' => null, 'longitude' => null]);

    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $customer = helperCreateCustomerWithAddress($this->store);
    $order = helperCreateEligibleOrder($this->store, $customer);

    RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/plan", [
            'departure_time' => '08:00',
        ]);

    $response->assertStatus(422);
});

test('plan fails when stop has no geolocated address', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    // Customer without geolocated address
    $province = Province::factory()->create();
    $locality = Locality::factory()->for($province)->create();
    $customer = Customer::factory()->create(['store_id' => $this->store->id]);
    CustomerAddress::factory()->forCustomer($customer)->for($locality)->asMain()->create([
        'latitude' => null,
        'longitude' => null,
    ]);

    $order = helperCreateEligibleOrder($this->store, $customer);

    RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/plan", [
            'departure_time' => '08:00',
        ]);

    $response->assertStatus(422);
});

test('plan fails when route has no active stops', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

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

test('plan requires departure_time', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $customer = helperCreateCustomerWithAddress($this->store);
    $order = helperCreateEligibleOrder($this->store, $customer);

    RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/plan", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('departure_time');
});

// ── Revert Tests ─────────────────────────────────────────────────────

test('revert clears calculated fields', function () {
    mockGoogleApiSuccess([0]);

    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $customer = helperCreateCustomerWithAddress($this->store);
    $order = helperCreateEligibleOrder($this->store, $customer);

    RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    // Plan first
    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/plan", [
            'departure_time' => '08:00',
        ]);

    $route->refresh();
    expect($route->encoded_polyline)->toBeNull();

    // Now revert
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/revert", [
            'reason' => 'Need to adjust stops',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'draft');

    $route->refresh();
    expect($route->status)->toBe('draft');
    expect($route->encoded_polyline)->toBeNull();
    expect($route->planned_at)->toBeNull();
    expect($route->departure_time)->toBeNull();
    expect($route->unload_time_minutes_snapshot)->toBeNull();

    // Stop calculated fields should be cleared
    $stop = $route->stops()->first();
    expect($stop->estimated_arrival_at)->toBeNull();
    expect($stop->travel_duration_seconds)->toBeNull();

    // But stop composition preserved
    expect($route->stops()->count())->toBe(1);
});

test('revert requires reason', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'planned',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/revert", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('reason');
});

test('revert fails when route is not planned', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/revert", [
            'reason' => 'Test',
        ]);

    $response->assertStatus(422);
});

test('plan rejects more than 25 stops without changing the draft route', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);
    $customer = helperCreateCustomerWithAddress($this->store, -34.6033, -58.3815);
    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $stops = [];
    for ($sequence = 1; $sequence <= 26; $sequence++) {
        $order = helperCreateEligibleOrder($this->store, $customer);
        $stops[] = [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'route_id' => $route->id,
            'order_id' => $order->id,
            'sequence' => $sequence,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    RouteStop::insert($stops);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/plan", ['departure_time' => '08:00']);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'La ruta tiene más de 25 paradas. Reducí las paradas antes de optimizarla.');

    expect($route->fresh()->status)->toBe('draft');
    expect($route->stops()->count())->toBe(26);
});

// ── Reorder in Planned Tests ─────────────────────────────────────────

test('reorder stops in planned route sets requires_recalculation and clears ETAs', function () {
    mockGoogleApiSuccess([0, 1]);

    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $customer1 = helperCreateCustomerWithAddress($this->store, -34.6033, -58.3815);
    $customer2 = helperCreateCustomerWithAddress($this->store, -34.6044, -58.3994);

    $order1 = helperCreateEligibleOrder($this->store, $customer1);
    $order2 = helperCreateEligibleOrder($this->store, $customer2);

    RouteStop::insert([
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'route_id' => $route->id, 'order_id' => $order1->id, 'sequence' => 1, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'route_id' => $route->id, 'order_id' => $order2->id, 'sequence' => 2, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Plan first
    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/plan", [
            'departure_time' => '08:00',
        ]);

    $route->refresh();
    $stop1 = $route->stops()->where('sequence', 1)->first();

    // Now reorder in planned state
    $stop2 = $route->stops()->where('sequence', 2)->first();

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->putJson("/api/v1/store/routes/{$route->id}/stops/reorder", [
            'stop_ids' => [$stop2->id, $stop1->id],
        ]);

    $response->assertStatus(200);

    $route->refresh();
    expect($route->requires_recalculation)->toBeTrue();

    // ETAs should be cleared
    foreach ($route->stops as $stop) {
        expect($stop->estimated_arrival_at)->toBeNull();
        expect($stop->travel_duration_seconds)->toBeNull();
    }

    // Verify 'stops_reordered_planned' event was created
    expect(DeliveryRouteEvent::where('event_type', 'stops_reordered_planned')->count())->toBe(1);
});

// ── Recalculate Tests ────────────────────────────────────────────────

test('recalculate updates ETAs and clears requires_recalculation flag', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'planned',
        'departure_time' => '08:00',
        'unload_time_minutes_snapshot' => 20,
        'planned_at' => now(),
        'requires_recalculation' => true,
    ]);

    $customer1 = helperCreateCustomerWithAddress($this->store, -34.6033, -58.3815);
    $customer2 = helperCreateCustomerWithAddress($this->store, -34.6044, -58.3994);

    $order1 = helperCreateEligibleOrder($this->store, $customer1);
    $order2 = helperCreateEligibleOrder($this->store, $customer2);

    // Stops with previous ETAs that should be overwritten
    RouteStop::insert([
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'route_id' => $route->id, 'order_id' => $order1->id, 'sequence' => 1, 'status' => 'pending', 'estimated_arrival_at' => now(), 'travel_duration_seconds' => 500, 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'route_id' => $route->id, 'order_id' => $order2->id, 'sequence' => 2, 'status' => 'pending', 'estimated_arrival_at' => now(), 'travel_duration_seconds' => 500, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Mock with optimizeWaypointOrder=false (respect current order)
    $legs = [];
    for ($i = 0; $i < 3; $i++) {
        $legs[] = [
            'duration' => (600 + $i * 120).'s',
            'polyline' => ['encodedPolyline' => 'new_leg'.$i],
        ];
    }
    Http::fake([
        'routes.googleapis.com/*' => Http::response([
            'routes' => [[
                'legs' => $legs,
                'polyline' => ['encodedPolyline' => 'global_polyline_recalc'],
            ]],
        ], 200),
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/recalculate");

    $response->assertStatus(200)
        ->assertJsonPath('data.requires_recalculation', false);

    $route->refresh();
    expect($route->requires_recalculation)->toBeFalse();
    expect($route->encoded_polyline)->toBeNull();

    // Stops should have new ETAs
    $stops = $route->stops()->orderBy('sequence')->get();
    foreach ($stops as $stop) {
        expect($stop->estimated_arrival_at)->not->toBeNull();
        expect($stop->travel_duration_seconds)->toBeGreaterThan(0);
    }
});

test('recalculate fails when route does not need recalculation', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'planned',
        'requires_recalculation' => false,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/recalculate");

    $response->assertStatus(422);
});

// ── Optimize Tests ────────────────────────────────────────────────────

test('optimize re-runs automatic optimization on planned route', function () {
    mockGoogleApiSuccess([2, 0, 1]); // Google reorders: original[2], original[0], original[1]

    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $customer1 = helperCreateCustomerWithAddress($this->store, -34.6033, -58.3815);
    $customer2 = helperCreateCustomerWithAddress($this->store, -34.6044, -58.3994);
    $customer3 = helperCreateCustomerWithAddress($this->store, -34.6055, -58.4100);

    $order1 = helperCreateEligibleOrder($this->store, $customer1);
    $order2 = helperCreateEligibleOrder($this->store, $customer2);
    $order3 = helperCreateEligibleOrder($this->store, $customer3);

    $date = now()->addDay()->format('Y-m-d');

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'planned',
        'departure_time' => '08:00',
        'unload_time_minutes_snapshot' => 20,
        'planned_at' => now(),
        'requires_recalculation' => false,
    ]);

    RouteStop::insert([
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'route_id' => $route->id, 'order_id' => $order1->id, 'sequence' => 1, 'status' => 'pending', 'estimated_arrival_at' => now(), 'travel_duration_seconds' => 300, 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'route_id' => $route->id, 'order_id' => $order2->id, 'sequence' => 2, 'status' => 'pending', 'estimated_arrival_at' => now(), 'travel_duration_seconds' => 300, 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'route_id' => $route->id, 'order_id' => $order3->id, 'sequence' => 3, 'status' => 'pending', 'estimated_arrival_at' => now(), 'travel_duration_seconds' => 300, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/optimize");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'planned')
        ->assertJsonPath('data.requires_recalculation', false)
        ->assertJsonPath('data.departure_time', '08:00');

    $route->refresh();

    // Status must remain planned
    expect($route->status)->toBe('planned');

    // Provider geometry is not persisted.
    expect($route->encoded_polyline)->toBeNull();

    // requires_recalculation cleared
    expect($route->requires_recalculation)->toBeFalse();

    // departure_time and unload_time_minutes_snapshot preserved
    expect($route->departure_time)->toBe('08:00');
    expect($route->unload_time_minutes_snapshot)->toBe(20);

    // Stops were reordered by Google [2,0,1] → sequence should be 1,2,3 for stop indices 2,0,1
    $stops = $route->stops()->orderBy('sequence')->get();
    expect($stops)->toHaveCount(3);
    // Original index 2 → sequence 1, index 0 → sequence 2, index 1 → sequence 3
    expect($stops[0]->order_id)->toBe($order3->id);
    expect($stops[1]->order_id)->toBe($order1->id);
    expect($stops[2]->order_id)->toBe($order2->id);

    // ETAs recalculated
    foreach ($stops as $stop) {
        expect($stop->estimated_arrival_at)->not->toBeNull();
        expect($stop->travel_duration_seconds)->toBeGreaterThan(0);
    }

    // Event created
    $event = DeliveryRouteEvent::where('event_type', 'route_optimized')->first();
    expect($event)->not->toBeNull();
    expect($event->from_status)->toBe('planned');
    expect($event->to_status)->toBe('planned');
    expect($event->metadata['optimized'])->toBeTrue();
    expect($event->metadata['departure_time'])->toBe('08:00');
    expect($event->metadata['previous_order'])->toBeArray();
    expect($event->metadata['new_order'])->toBeArray();
});

test('optimize preserves the previous planned route when Google returns an invalid response', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);
    $customer1 = helperCreateCustomerWithAddress($this->store, -34.6033, -58.3815);
    $customer2 = helperCreateCustomerWithAddress($this->store, -34.6044, -58.3994);
    $order1 = helperCreateEligibleOrder($this->store, $customer1);
    $order2 = helperCreateEligibleOrder($this->store, $customer2);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'planned',
        'departure_time' => '08:00',
        'unload_time_minutes_snapshot' => 15,
        'planned_at' => now(),
        'requires_recalculation' => false,
    ]);

    $eta = now()->addHour();
    RouteStop::insert([
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'route_id' => $route->id, 'order_id' => $order1->id, 'sequence' => 1, 'status' => 'pending', 'estimated_arrival_at' => $eta, 'travel_duration_seconds' => 300, 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'route_id' => $route->id, 'order_id' => $order2->id, 'sequence' => 2, 'status' => 'pending', 'estimated_arrival_at' => $eta, 'travel_duration_seconds' => 600, 'created_at' => now(), 'updated_at' => now()],
    ]);

    Http::fake(['routes.googleapis.com/*' => Http::response([], 200)]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/optimize");

    $response->assertStatus(503)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Google no devolvió un recorrido válido. Conservamos el recorrido anterior; intentá nuevamente.');

    $route->refresh();
    expect($route->status)->toBe('planned');
    expect($route->encoded_polyline)->toBeNull();
    expect($route->requires_recalculation)->toBeFalse();

    $stops = $route->stops()->orderBy('sequence')->get();
    expect($stops->pluck('order_id')->all())->toBe([$order1->id, $order2->id]);
    expect($stops->pluck('travel_duration_seconds')->all())->toBe([300, 600]);
    expect(DeliveryRouteEvent::where('event_type', 'route_optimized')->count())->toBe(0);
});

test('optimize rejects route not in planned status', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/optimize");

    $response->assertStatus(422);
});

test('optimize rejects planned route with no active stops', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'planned',
        'departure_time' => '08:00',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/optimize");

    $response->assertStatus(422);
});

test('optimize rejects planned route without departure_time', function () {
    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'planned',
        'departure_time' => null,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/optimize");

    $response->assertStatus(422);
});

// ── Show Enriched Tests ──────────────────────────────────────────────

test('show route includes optimization data', function () {
    mockGoogleApiSuccess([0]);

    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $customer = helperCreateCustomerWithAddress($this->store, -34.6033, -58.3815);
    $order = helperCreateEligibleOrder($this->store, $customer);

    RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/plan", [
            'departure_time' => '08:00',
        ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.departure_time', '08:00')
        ->assertJsonPath('data.requires_recalculation', false);

    $data = $response->json('data');

    // Store coordinates present
    expect($data['store']['latitude'] ?? null)->not->toBeNull();
    expect($data['store']['longitude'] ?? null)->not->toBeNull();

    // Provider geometry is deliberately absent from route resources.
    expect($data)->not->toHaveKey('encoded_polyline');

    // Stop has ETA and coordinates
    $stop = $data['stops'][0] ?? null;
    expect($stop)->not->toBeNull();
    expect($stop['estimated_arrival_at'] ?? null)->not->toBeNull();
    expect($stop['travel_duration_seconds'] ?? null)->toBeGreaterThan(0);
});

// ── Unload Time Snapshot Test ────────────────────────────────────────

test('unload time snapshot is saved on plan and not affected by later setting change', function () {
    mockGoogleApiSuccess([0]);

    $vehicle = helperCreateVehicle($this->store);
    $driver = helperCreateDriver($this->store);

    StoreSetting::factory()->create([
        'store_id' => $this->store->id,
        'delivery_unload_time_minutes' => 10,
    ]);

    $route = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'draft',
    ]);

    $customer = helperCreateCustomerWithAddress($this->store);
    $order = helperCreateEligibleOrder($this->store, $customer);

    RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/plan", [
            'departure_time' => '08:00',
        ]);

    $route->refresh();
    expect($route->unload_time_minutes_snapshot)->toBe(10);

    // Change the setting
    $this->store->settings()->update(['delivery_unload_time_minutes' => 30]);

    // Snapshot on route should remain 10
    $route->refresh();
    expect($route->unload_time_minutes_snapshot)->toBe(10);
});
