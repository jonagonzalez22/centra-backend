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
use App\Models\RouteLoadAdjustment;
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
    foreach ([
        'logistics.routes.view',
        'logistics.routes.manage',
        'logistics.routes.plan',
        'logistics.routes.revert',
        'logistics.routes.cancel',
        'logistics.routes.load',
        'logistics.routes.dispatch',
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
        'logistics.routes.plan',
        'logistics.routes.revert',
        'logistics.routes.cancel',
        'logistics.routes.load',
        'logistics.routes.dispatch',
    ]);
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

// ── Helpers ──────────────────────────────────────────────────────────
// NOTE: createDriver(), createVehicle(), createCustomerWithAddress(), and
// createEligibleOrder() are already defined in RouteManagementTest.php.
// They are available globally in Pest.

/**
 * Create a planned route with one stop and assigned items.
 * Returns [route, stop, product, item].
 */
function createPlannedRouteWithItems(Store $store, ?User $driver = null): array
{
    $vehicle = createVehicle($store);
    $driver = $driver ?? createDriver($store);
    $customer = createCustomerWithAddress($store);
    $product = Product::factory()->create(['store_id' => $store->id]);

    $date = now()->addDay()->format('Y-m-d');
    $store->update(['latitude' => -34.6037, 'longitude' => -58.3816]);

    $order = createEligibleOrder($store, $customer, ['requested_delivery_date' => $date]);
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
        'status' => 'draft',
    ]);

    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    // Manually assign items (bypassing the API for setup)
    $routeStopItem = RouteStopItem::create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
        'quantity_planned' => 5,
        'quantity_loaded' => 0,
        'quantity_delivered' => 0,
    ]);

    return [$route, $stop, $product, $routeStopItem];
}

// ── Item Assignment Tests ────────────────────────────────────────────

test('assigns items to a stop in draft route', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $product = Product::factory()->create(['store_id' => $this->store->id]);

    $date = now()->addDay()->format('Y-m-d');

    $order = createEligibleOrder($this->store, $customer, ['requested_delivery_date' => $date]);
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
        'status' => 'draft',
    ]);

    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/stops/{$stop->id}/items", [
            'items' => [
                ['product_id' => $product->id, 'quantity_planned' => 5],
            ],
        ]);

    $response->assertStatus(200);

    expect(RouteStopItem::count())->toBe(1);
    $item = RouteStopItem::first();
    expect($item->route_stop_id)->toBe($stop->id);
    expect($item->product_id)->toBe($product->id);
    expect($item->quantity_planned)->toBe(5);

    expect(DeliveryRouteEvent::where('event_type', 'items_assigned')->count())->toBe(1);
});

test('rejects item assignment if route is loaded', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $product = Product::factory()->create(['store_id' => $this->store->id]);

    $date = now()->addDay()->format('Y-m-d');

    $order = createEligibleOrder($this->store, $customer, ['requested_delivery_date' => $date]);
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
        'status' => 'loaded', // not draft or planned
    ]);

    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/stops/{$stop->id}/items", [
            'items' => [
                ['product_id' => $product->id, 'quantity_planned' => 5],
            ],
        ]);

    $response->assertStatus(422);
});

test('rejects item assignment if quantity exceeds order item quantity', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $product = Product::factory()->create(['store_id' => $this->store->id]);

    $date = now()->addDay()->format('Y-m-d');

    $order = createEligibleOrder($this->store, $customer, ['requested_delivery_date' => $date]);
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
        'status' => 'draft',
    ]);

    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/stops/{$stop->id}/items", [
            'items' => [
                ['product_id' => $product->id, 'quantity_planned' => 20], // exceeds 10
            ],
        ]);

    $response->assertStatus(422);
});

test('updates existing item quantity on reassignment', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);
    $customer = createCustomerWithAddress($this->store);
    $product = Product::factory()->create(['store_id' => $this->store->id]);

    $date = now()->addDay()->format('Y-m-d');

    $order = createEligibleOrder($this->store, $customer, ['requested_delivery_date' => $date]);
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
        'status' => 'draft',
    ]);

    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    // First assignment
    RouteStopItem::create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
        'quantity_planned' => 3,
        'quantity_loaded' => 0,
        'quantity_delivered' => 0,
    ]);

    // Update assignment
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/stops/{$stop->id}/items", [
            'items' => [
                ['product_id' => $product->id, 'quantity_planned' => 7],
            ],
        ]);

    $response->assertStatus(200);

    expect(RouteStopItem::count())->toBe(1);
    expect(RouteStopItem::first()->quantity_planned)->toBe(7);
});

// ── Load Sheet Tests ─────────────────────────────────────────────────

test('returns consolidated load sheet with correct quantities', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    $route->update(['status' => 'draft']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}/load-sheet");

    $response->assertStatus(200)
        ->assertJsonPath('data.route_id', $route->id)
        ->assertJsonPath('data.by_product.0.total_planned', 5)
        ->assertJsonPath('data.by_stop.0.items.0.quantity_planned', 5)
        ->assertJsonPath('data.total_items', 5);
});

test('returns 404 for load sheet of non-existent route', function () {
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson('/api/v1/store/routes/00000000-0000-0000-0000-000000000000/load-sheet');

    $response->assertStatus(404);
});

// ── Confirm Load Tests ───────────────────────────────────────────────

test('confirms load with matching quantities and transitions to loaded', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    $route->update(['status' => 'planned']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/confirm-load", [
            'items' => [
                ['route_stop_item_id' => $item->id, 'quantity_loaded' => 5],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'loaded');

    expect($route->fresh()->status)->toBe('loaded');
    expect($route->fresh()->loaded_at)->not->toBeNull();
    expect($route->fresh()->loaded_by)->toBe($this->user->id);
    expect($item->fresh()->quantity_loaded)->toBe(5);
    expect(DeliveryRouteEvent::where('event_type', 'route_loaded')->count())->toBe(1);
});

test('confirms load with differences and creates adjustment records', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    $route->update(['status' => 'planned']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/confirm-load", [
            'items' => [
                [
                    'route_stop_item_id' => $item->id,
                    'quantity_loaded' => 3,
                    'reason' => 'no_stock',
                ],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'loaded');

    expect($item->fresh()->quantity_loaded)->toBe(3);

    $adjustment = RouteLoadAdjustment::first();
    expect($adjustment)->not->toBeNull();
    expect($adjustment->route_stop_item_id)->toBe($item->id);
    expect($adjustment->old_quantity)->toBe(0);
    expect($adjustment->new_quantity)->toBe(3);
    expect($adjustment->reason)->toBe('no_stock');
    expect($adjustment->user_id)->toBe($this->user->id);
});

test('rejects confirm load if quantity_loaded exceeds quantity_planned', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    $route->update(['status' => 'planned']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/confirm-load", [
            'items' => [
                ['route_stop_item_id' => $item->id, 'quantity_loaded' => 10], // planned = 5
            ],
        ]);

    $response->assertStatus(422);
});

test('rejects confirm load if all items have zero quantity_loaded', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    $route->update(['status' => 'planned']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/confirm-load", [
            'items' => [
                ['route_stop_item_id' => $item->id, 'quantity_loaded' => 0],
            ],
        ]);

    $response->assertStatus(422);
});

test('rejects confirm load if route is not planned', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    // route is still draft

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/confirm-load", [
            'items' => [
                ['route_stop_item_id' => $item->id, 'quantity_loaded' => 5],
            ],
        ]);

    $response->assertStatus(422);
});

test('requires reason when quantity_loaded is less than quantity_planned', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    $route->update(['status' => 'planned']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/confirm-load", [
            'items' => [
                ['route_stop_item_id' => $item->id, 'quantity_loaded' => 2], // no reason
            ],
        ]);

    $response->assertStatus(422);
});

// ── Dispatch Tests ───────────────────────────────────────────────────

test('dispatches a loaded route', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    $route->update(['status' => 'loaded', 'loaded_at' => now(), 'loaded_by' => $this->user->id]);
    $item->update(['quantity_loaded' => 5]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/dispatch");

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'dispatched');

    expect($route->fresh()->status)->toBe('dispatched');
    expect($route->fresh()->dispatched_at)->not->toBeNull();
    expect($route->fresh()->dispatched_by)->toBe($this->user->id);
    expect(DeliveryRouteEvent::where('event_type', 'route_dispatched')->count())->toBe(1);
});

test('rejects dispatch from draft', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    // route is still draft

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/dispatch");

    $response->assertStatus(422);
});

test('rejects dispatch from planned (must confirm load first)', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    $route->update(['status' => 'planned']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/dispatch");

    $response->assertStatus(422);
});

// ── Cancel from Loaded Tests ─────────────────────────────────────────

test('cancels a loaded route and frees all stops', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    $route->update(['status' => 'loaded', 'loaded_at' => now(), 'loaded_by' => $this->user->id]);
    $item->update(['quantity_loaded' => 5]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/cancel", [
            'reason' => 'Clima adverso imprevisto',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'cancelled');

    expect($route->fresh()->status)->toBe('cancelled');
    expect($stop->fresh()->status)->toBe('cancelled');

    $event = DeliveryRouteEvent::where('event_type', 'cancelled')->first();
    expect($event->from_status)->toBe('loaded');
    expect($event->metadata['physical_return_required'])->toBeTrue();
});

test('rejects cancel from dispatched route', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    $route->update([
        'status' => 'dispatched',
        'loaded_at' => now(),
        'loaded_by' => $this->user->id,
        'dispatched_at' => now(),
        'dispatched_by' => $this->user->id,
    ]);
    $item->update(['quantity_loaded' => 5]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/cancel", [
            'reason' => 'Quiero cancelar',
        ]);

    $response->assertStatus(422);
});

// ── Multi-tenant Isolation Tests ─────────────────────────────────────

test('cannot assign items to route from another store', function () {
    $otherStore = Store::factory()->create();
    $otherPlan = Plan::factory()->create();
    $otherStore->update(['plan_id' => $otherPlan->id]);

    $otherCustomer = createCustomerWithAddress($otherStore);
    $product = Product::factory()->create(['store_id' => $this->store->id]);

    $date = now()->addDay()->format('Y-m-d');

    $otherOrder = createEligibleOrder($otherStore, $otherCustomer, ['requested_delivery_date' => $date]);

    $otherRoute = DeliveryRoute::create([
        'store_id' => $otherStore->id,
        'vehicle_id' => createVehicle($otherStore)->id,
        'driver_id' => createDriver($otherStore)->id,
        'operational_date' => $date,
        'status' => 'draft',
    ]);

    $otherStop = RouteStop::create([
        'route_id' => $otherRoute->id,
        'order_id' => $otherOrder->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$otherRoute->id}/stops/{$otherStop->id}/items", [
            'items' => [
                ['product_id' => $product->id, 'quantity_planned' => 1],
            ],
        ]);

    $response->assertStatus(404);
});

// ── Model tests ──────────────────────────────────────────────────────

test('route_stop_item belongs to route_stop and product', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);

    expect($item->routeStop)->not->toBeNull();
    expect($item->routeStop->id)->toBe($stop->id);
    expect($item->product)->not->toBeNull();
    expect($item->product->id)->toBe($product->id);
});

test('route_stop has items relationship', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);

    $stopWithItems = RouteStop::with('items')->find($stop->id);
    expect($stopWithItems->items)->toHaveCount(1);
    expect($stopWithItems->items->first()->id)->toBe($item->id);
});

test('route_load_adjustment is immutable (no updated_at)', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);

    $adjustment = RouteLoadAdjustment::create([
        'route_stop_item_id' => $item->id,
        'user_id' => $this->user->id,
        'old_quantity' => 0,
        'new_quantity' => 3,
        'reason' => 'no_stock',
    ]);

    expect($adjustment->getUpdatedAtColumn())->toBeNull();
});

test('delivery_route has loaded_by and dispatched_by relationships', function () {
    [$route, $stop, $product, $item] = createPlannedRouteWithItems($this->store);
    $route->update([
        'status' => 'dispatched',
        'loaded_at' => now(),
        'loaded_by' => $this->user->id,
        'dispatched_at' => now(),
        'dispatched_by' => $this->user->id,
    ]);

    $routeFresh = DeliveryRoute::with(['loadedBy', 'dispatchedBy'])->find($route->id);
    expect($routeFresh->loadedBy->id)->toBe($this->user->id);
    expect($routeFresh->dispatchedBy->id)->toBe($this->user->id);
});

test('scope_active includes loaded and dispatched statuses', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);

    DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'loaded',
    ]);
    DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'dispatched',
    ]);
    DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'cancelled',
    ]);

    $active = DeliveryRoute::active()->get();
    expect($active)->toHaveCount(2);
    $statuses = $active->pluck('status')->toArray();
    expect($statuses)->toContain('loaded');
    expect($statuses)->toContain('dispatched');
    expect($statuses)->not->toContain('cancelled');
});

test('is_active returns true for loaded and dispatched', function () {
    $vehicle = createVehicle($this->store);
    $driver = createDriver($this->store);

    $loaded = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'loaded',
    ]);
    $cancelled = DeliveryRoute::create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->addDay()->format('Y-m-d'),
        'status' => 'cancelled',
    ]);

    expect($loaded->isActive())->toBeTrue();
    expect($cancelled->isActive())->toBeFalse();
});
