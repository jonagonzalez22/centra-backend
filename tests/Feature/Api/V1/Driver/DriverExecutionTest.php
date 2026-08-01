<?php

use App\Models\DeliveryRejectionReason;
use App\Models\DeliveryRoute;
use App\Models\DeliveryRouteEvent;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_DRIVER', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();

    $plan = Plan::factory()->create();
    $dFeature = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $plan->features()->attach($dFeature->id);
    $this->store->update(['plan_id' => $plan->id]);

    $this->vehicle = Vehicle::factory()->create(['store_id' => $this->store->id]);

    // Create driver user
    $this->driver = User::factory()->create(['store_id' => $this->store->id]);
    $this->driver->assignRole('STORE_DRIVER');
    $this->driverToken = $this->driver->createToken('driver-test')->plainTextToken;

    // Create non-driver user
    $this->nonDriver = User::factory()->create(['store_id' => $this->store->id]);
    $this->nonDriver->assignRole('STORE_ADMIN');
    $this->nonDriverToken = $this->nonDriver->createToken('admin-test')->plainTextToken;

    // Seed rejection reasons
    $this->seed(\Database\Seeders\DeliveryRejectionReasonSeeder::class);
});

// ─── Helper: create a dispatched route with stops and items ────────────────

function createDispatchedRoute(User $driver, Store $store, Vehicle $vehicle): array
{
    $route = DeliveryRoute::factory()->create([
        'store_id' => $store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => 'dispatched',
        'dispatched_at' => now(),
    ]);

    $product1 = Product::factory()->create(['store_id' => $store->id]);
    $product2 = Product::factory()->create(['store_id' => $store->id]);

    $stop1 = RouteStop::factory()->create([
        'route_id' => $route->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $stop2 = RouteStop::factory()->create([
        'route_id' => $route->id,
        'sequence' => 2,
        'status' => 'pending',
    ]);

    $stopItem1 = RouteStopItem::factory()->create([
        'route_stop_id' => $stop1->id,
        'product_id' => $product1->id,
        'quantity_planned' => 10,
        'quantity_loaded' => 10,
        'quantity_delivered' => 0,
    ]);

    $stopItem2 = RouteStopItem::factory()->create([
        'route_stop_id' => $stop1->id,
        'product_id' => $product2->id,
        'quantity_planned' => 5,
        'quantity_loaded' => 5,
        'quantity_delivered' => 0,
    ]);

    $stopItem3 = RouteStopItem::factory()->create([
        'route_stop_id' => $stop2->id,
        'product_id' => $product1->id,
        'quantity_planned' => 3,
        'quantity_loaded' => 3,
        'quantity_delivered' => 0,
    ]);

    return [
        'route' => $route,
        'stops' => [$stop1, $stop2],
        'items' => [$stopItem1, $stopItem2, $stopItem3],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// TESTS: GET /api/v1/driver/active-route
// ═══════════════════════════════════════════════════════════════════════════

test('active route returns dispatched route for assigned driver', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson('/api/v1/driver/active-route');

    $response->assertStatus(200)
        ->assertJsonPath('data.route.id', $data['route']->id)
        ->assertJsonPath('data.route.status', 'dispatched')
        ->assertJsonPath('data.route.stops.0.id', $data['stops'][0]->id)
        ->assertJsonPath('data.route.stops.1.id', $data['stops'][1]->id);
});

test('active route returns 404 when driver has no dispatched route', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson('/api/v1/driver/active-route');

    $response->assertStatus(404);
});

test('active route ignores driver route with non-dispatched status', function () {
    DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => 'loaded',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson('/api/v1/driver/active-route');

    $response->assertStatus(404);
});

test('active route returns 403 for non-driver user', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->nonDriverToken}")
        ->getJson('/api/v1/driver/active-route');

    $response->assertStatus(403);
});

test('active route loads vehicle, stops, items, and customer relations', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson('/api/v1/driver/active-route');

    $response->assertStatus(200)
        ->assertJsonPath('data.route.vehicle.id', $this->vehicle->id)
        ->assertJsonPath('data.route.stops.0.items.0.id', $data['items'][0]->id);
});

// ═══════════════════════════════════════════════════════════════════════════
// TESTS: POST /api/v1/driver/stops/{stop}/arrive
// ═══════════════════════════════════════════════════════════════════════════

test('arrive records gps coordinates on stop', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/arrive", [
            'gps_lat' => -34.6037,
            'gps_lon' => -58.3816,
        ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('route_stops', [
        'id' => $stop->id,
        'gps_lat' => -34.6037000,
        'gps_lon' => -58.3816000,
    ]);
});

test('arrive works without gps coordinates', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/arrive", []);

    $response->assertStatus(200);
});

test('arrive returns 403 for non-driver', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->nonDriverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/arrive", [
            'gps_lat' => -34.6037,
            'gps_lon' => -58.3816,
        ]);

    $response->assertStatus(403);
});

test('arrive returns 404 when stop belongs to different driver route', function () {
    createDispatchedRoute($this->driver, $this->store, $this->vehicle);

    // Create another driver and route
    $otherDriver = User::factory()->create(['store_id' => $this->store->id]);
    $otherDriver->assignRole('STORE_DRIVER');
    $otherData = createDispatchedRoute($otherDriver, $this->store, $this->vehicle);
    $otherStop = $otherData['stops'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$otherStop->id}/arrive", []);

    $response->assertStatus(404);
});

// ═══════════════════════════════════════════════════════════════════════════
// TESTS: POST /api/v1/driver/stops/{stop}/complete — full delivery
// ═══════════════════════════════════════════════════════════════════════════

test('complete stop delivers full quantities and sets completed status', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $item1 = $data['items'][0];
    $item2 = $data['items'][1];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'gps_lat' => -34.6037,
            'gps_lon' => -58.3816,
            'items' => [
                ['route_stop_item_id' => $item1->id, 'quantity_delivered' => 10],
                ['route_stop_item_id' => $item2->id, 'quantity_delivered' => 5],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'completed');

    $this->assertDatabaseHas('route_stops', [
        'id' => $stop->id,
        'status' => 'completed',
        'completed_by' => $this->driver->id,
        'gps_lat' => -34.6037000,
        'gps_lon' => -58.3816000,
    ]);
    $this->assertNotNull(RouteStop::find($stop->id)->completed_at);

    $this->assertDatabaseHas('route_stop_items', [
        'id' => $item1->id,
        'quantity_delivered' => 10,
    ]);
    $this->assertDatabaseHas('route_stop_items', [
        'id' => $item2->id,
        'quantity_delivered' => 5,
    ]);

    // Event created
    $this->assertDatabaseHas('delivery_route_events', [
        'route_id' => $data['route']->id,
        'event_type' => 'stop_completed',
        'user_id' => $this->driver->id,
    ]);
});

// ═══════════════════════════════════════════════════════════════════════════
// TESTS: POST /api/v1/driver/stops/{stop}/complete — partial delivery
// ═══════════════════════════════════════════════════════════════════════════

test('complete stop with partial delivery and rejection reasons', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $item1 = $data['items'][0];
    $item2 = $data['items'][1];

    $reason = DeliveryRejectionReason::where('code', 'customer_absent')->first();

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'gps_lat' => -34.6037,
            'gps_lon' => -58.3816,
            'items' => [
                ['route_stop_item_id' => $item1->id, 'quantity_delivered' => 10],
                ['route_stop_item_id' => $item2->id, 'quantity_delivered' => 3, 'rejection_reason_id' => $reason->id],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'completed');

    $this->assertDatabaseHas('route_stop_items', [
        'id' => $item2->id,
        'quantity_delivered' => 3,
    ]);
});

test('complete stop rejects partial delivery without rejection_reason_id', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $item1 = $data['items'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $item1->id, 'quantity_delivered' => 3],
            ],
        ]);

    // Should fail: partial delivery without reason
    $response->assertStatus(422);
});

// ═══════════════════════════════════════════════════════════════════════════
// TESTS: POST /api/v1/driver/stops/{stop}/complete — failed delivery
// ═══════════════════════════════════════════════════════════════════════════

test('complete stop with zero delivered is failed and requires rejection_reason_id', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $item1 = $data['items'][0];
    $item2 = $data['items'][1];

    $reason = DeliveryRejectionReason::where('code', 'customer_absent')->first();

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'failed',
            'rejection_reason_id' => $reason->id,
            'gps_lat' => -34.6037,
            'gps_lon' => -58.3816,
            'items' => [
                ['route_stop_item_id' => $item1->id, 'quantity_delivered' => 0],
                ['route_stop_item_id' => $item2->id, 'quantity_delivered' => 0],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'failed');

    $this->assertDatabaseHas('route_stops', [
        'id' => $stop->id,
        'status' => 'failed',
    ]);

    // Event created
    $this->assertDatabaseHas('delivery_route_events', [
        'route_id' => $data['route']->id,
        'event_type' => 'stop_failed',
    ]);
});

test('complete stop failed without rejection_reason_id returns validation error', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $item1 = $data['items'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'failed',
            'items' => [
                ['route_stop_item_id' => $item1->id, 'quantity_delivered' => 0],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('errors.rejection_reason_id.0', fn ($v) => str_contains($v, 'obligatorio'));
});

// ═══════════════════════════════════════════════════════════════════════════
// TESTS: Validation / edge cases
// ═══════════════════════════════════════════════════════════════════════════

test('complete stop rejects quantity_delivered exceeding quantity_loaded', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $item1 = $data['items'][0]; // quantity_loaded = 10

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $item1->id, 'quantity_delivered' => 11],
            ],
        ]);

    $response->assertStatus(422);
});

test('complete stop rejects already completed stop', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $item1 = $data['items'][0];
    $item2 = $data['items'][1];

    // Complete the stop first
    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $item1->id, 'quantity_delivered' => 10],
                ['route_stop_item_id' => $item2->id, 'quantity_delivered' => 5],
            ],
        ]);

    // Try to complete again
    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $item1->id, 'quantity_delivered' => 10],
                ['route_stop_item_id' => $item2->id, 'quantity_delivered' => 5],
            ],
        ]);

    $response->assertStatus(422);
});

test('complete stop returns 404 when driver not assigned to route', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $item1 = $data['items'][0];

    // Another driver tries to complete
    $otherDriver = User::factory()->create(['store_id' => $this->store->id]);
    $otherDriver->assignRole('STORE_DRIVER');
    $otherToken = $otherDriver->createToken('other-test')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$otherToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $item1->id, 'quantity_delivered' => 10],
            ],
        ]);

    $response->assertStatus(404);
});

test('complete stop returns 404 when route is not dispatched', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $item1 = $data['items'][0];

    // Change route to loaded
    $data['route']->update(['status' => 'loaded']);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $item1->id, 'quantity_delivered' => 10],
            ],
        ]);

    $response->assertStatus(404);
});

// ═══════════════════════════════════════════════════════════════════════════
// TESTS: Auto-transition to awaiting_reconciliation
// ═══════════════════════════════════════════════════════════════════════════

test('completing last stop transitions route to awaiting_reconciliation', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop1 = $data['stops'][0];
    $stop2 = $data['stops'][1];

    $itemsStop1 = RouteStopItem::where('route_stop_id', $stop1->id)->get();
    $itemsStop2 = RouteStopItem::where('route_stop_id', $stop2->id)->get();

    // Complete first stop
    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop1->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $itemsStop1[0]->id, 'quantity_delivered' => 10],
                ['route_stop_item_id' => $itemsStop1[1]->id, 'quantity_delivered' => 5],
            ],
        ])->assertStatus(200);

    // Route should still be dispatched
    $this->assertEquals('dispatched', $data['route']->fresh()->status);

    // Complete second (last) stop
    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop2->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $itemsStop2[0]->id, 'quantity_delivered' => 3],
            ],
        ])->assertStatus(200);

    // Route should be awaiting_reconciliation
    $this->assertEquals('awaiting_reconciliation', $data['route']->fresh()->status);

    $this->assertDatabaseHas('delivery_route_events', [
        'route_id' => $data['route']->id,
        'event_type' => 'route_awaiting_reconciliation',
    ]);
});

test('transition awaits all stops when mix of completed and failed', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop1 = $data['stops'][0];
    $stop2 = $data['stops'][1];
    $reason = DeliveryRejectionReason::where('code', 'customer_absent')->first();

    $itemsStop1 = RouteStopItem::where('route_stop_id', $stop1->id)->get();
    $itemsStop2 = RouteStopItem::where('route_stop_id', $stop2->id)->get();

    // Complete first stop normally
    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop1->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $itemsStop1[0]->id, 'quantity_delivered' => 10],
                ['route_stop_item_id' => $itemsStop1[1]->id, 'quantity_delivered' => 5],
            ],
        ])->assertStatus(200);

    // Fail second stop
    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop2->id}/complete", [
            'status' => 'failed',
            'rejection_reason_id' => $reason->id,
            'items' => [
                ['route_stop_item_id' => $itemsStop2[0]->id, 'quantity_delivered' => 0],
            ],
        ])->assertStatus(200);

    $this->assertEquals('awaiting_reconciliation', $data['route']->fresh()->status);
});

// ═══════════════════════════════════════════════════════════════════════════
// TESTS: Rejection reasons
// ═══════════════════════════════════════════════════════════════════════════

test('rejection_reason_id must reference an existing reason', function () {
    $data = createDispatchedRoute($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $item1 = $data['items'][0];
    $item2 = $data['items'][1];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'failed',
            'rejection_reason_id' => '00000000-0000-0000-0000-000000000000',
            'items' => [
                ['route_stop_item_id' => $item1->id, 'quantity_delivered' => 0],
                ['route_stop_item_id' => $item2->id, 'quantity_delivered' => 0],
            ],
        ]);

    $response->assertStatus(422);
});
