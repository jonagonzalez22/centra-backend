<?php

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\DeliveryRoute;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopCollection;
use App\Models\RouteStopItem;
use App\Models\Store;
use App\Models\StorePaymentMethod;
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

    $this->store = Store::factory()->create(['timezone' => 'America/Argentina/Buenos_Aires']);

    // Ensure app timezone matches store timezone for consistent Carbon::parse() behavior in CI
    config(['app.timezone' => $this->store->timezone]);

    $plan = Plan::factory()->create();
    $dFeature = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $plan->features()->attach($dFeature->id);
    $this->store->update(['plan_id' => $plan->id]);

    $this->vehicle = Vehicle::factory()->create(['store_id' => $this->store->id]);

    // Driver user
    $this->driver = User::factory()->create(['store_id' => $this->store->id]);
    $this->driver->assignRole('STORE_DRIVER');
    $this->driverToken = $this->driver->createToken('driver-test')->plainTextToken;

    // Non-driver user (for 403 tests)
    $this->otherDriver = User::factory()->create(['store_id' => $this->store->id]);
    $this->otherDriver->assignRole('STORE_DRIVER');
    $this->otherDriverToken = $this->otherDriver->createToken('other-driver-test')->plainTextToken;
});

// ─── Helper: build a dispatched route with stops, items, and collections ──────

function createDriverTestData(User $driver, Store $store, Vehicle $vehicle): array
{
    $route = DeliveryRoute::factory()->create([
        'store_id' => $store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => 'dispatched',
        'dispatched_at' => now(),
    ]);

    $customer = Customer::factory()->for($store)->create([
        'display_name' => 'Juan Pérez',
    ]);

    CustomerAddress::factory()->forCustomer($customer)->asMain()->create([
        'street' => 'Av. Corrientes',
        'number' => '1234',
    ]);

    CustomerContact::factory()->forCustomer($customer)->create([
        'phone' => '+5491155551234',
    ]);

    $product1 = Product::factory()->for($store)->create(['name' => 'Producto A', 'sku' => 'SKU-001']);
    $product2 = Product::factory()->for($store)->create(['name' => 'Producto B', 'sku' => 'SKU-002']);

    $order = \App\Models\CommercialOperation::factory()
        ->forStore($store)
        ->forCustomer($customer)
        ->order()
        ->create(['status' => 'confirmed']);

    $stop1 = RouteStop::factory()->create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
        'estimated_arrival_at' => '2026-08-11 09:30:00',
        'logistics_notes' => 'Timbre en piso 3',
    ]);

    $stop2 = RouteStop::factory()->create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 2,
        'status' => 'pending',
        'estimated_arrival_at' => '2026-08-11 10:30:00',
    ]);

    $item1 = RouteStopItem::factory()->create([
        'route_stop_id' => $stop1->id,
        'product_id' => $product1->id,
        'quantity_planned' => 10,
        'quantity_loaded' => 10,
        'quantity_delivered' => 0,
    ]);

    $item2 = RouteStopItem::factory()->create([
        'route_stop_id' => $stop1->id,
        'product_id' => $product2->id,
        'quantity_planned' => 5,
        'quantity_loaded' => 5,
        'quantity_delivered' => 0,
    ]);

    $paymentMethod = StorePaymentMethod::factory()->for($store)->create();

    RouteStopCollection::factory()->create([
        'route_stop_id' => $stop1->id,
        'store_id' => $store->id,
        'commercial_operation_id' => $order->id,
        'store_payment_method_id' => $paymentMethod->id,
        'amount' => 1500.00,
        'status' => 'declared',
        'declared_at' => now(),
        'declared_by' => $driver->id,
    ]);

    return [
        'route' => $route,
        'stops' => [$stop1, $stop2],
        'items' => [$item1, $item2],
        'customer' => $customer,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// TESTS: GET /api/v1/driver/routes/{route_id}/stops
// ═══════════════════════════════════════════════════════════════════════════════

test('driver routes stops returns 200 with correct structure for assigned driver', function () {
    $data = createDriverTestData($this->driver, $this->store, $this->vehicle);
    $route = $data['route'];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/routes/{$route->id}/stops");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $data['stops'][0]->id)
        ->assertJsonPath('data.0.sequence', 1)
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.address', 'Av. Corrientes 1234')
        ->assertJsonPath('data.0.contact_name', 'Juan Pérez')
        ->assertJsonPath('data.0.contact_phone', '+5491155551234')
        ->assertJsonStructure([
            'status',
            'data' => [
                '*' => [
                    'id',
                    'sequence',
                    'status',
                    'address',
                    'contact_name',
                    'contact_phone',
                    'notification_window_start',
                    'notification_window_end',
                    'items_count',
                    'total_planned_items',
                ],
            ],
            'errors',
        ]);
});

test('driver routes stops returns 404 for unassigned route', function () {
    // Create another driver with their own route
    $otherData = createDriverTestData($this->otherDriver, $this->store, $this->vehicle);

    // Driver tries to access other driver's route
    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/routes/{$otherData['route']->id}/stops");

    $response->assertStatus(404);
});

test('driver routes stops returns 404 for non-existent route', function () {
    $fakeId = '00000000-0000-0000-0000-000000000000';

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/routes/{$fakeId}/stops");

    $response->assertStatus(404);
});

test('driver routes stops includes notification window times', function () {
    $data = createDriverTestData($this->driver, $this->store, $this->vehicle);
    $route = $data['route'];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/routes/{$route->id}/stops");

    $response->assertStatus(200);

    // First stop: sequence=1, ETA=09:30 → morning exception applies
    // start = 09:30 (no -30 because sequence 1 and hour in [7-9])
    // end = 10:30
    $response->assertJsonPath('data.0.notification_window_start', '09:30');
    $response->assertJsonPath('data.0.notification_window_end', '10:30');

    // Second stop: sequence=2, ETA=10:30 → default ±30
    // start = 10:30 - 30 = 10:00
    // end = 10:30 + 30 = 11:00
    $response->assertJsonPath('data.1.notification_window_start', '10:00');
    $response->assertJsonPath('data.1.notification_window_end', '11:00');
});

// ═══════════════════════════════════════════════════════════════════════════════
// TESTS: GET /api/v1/driver/stops/{stop_id}
// ═══════════════════════════════════════════════════════════════════════════════

test('driver stops show returns 200 with full detail including items and collections', function () {
    $data = createDriverTestData($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/stops/{$stop->id}");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.id', $stop->id)
        ->assertJsonPath('data.route_id', $data['route']->id)
        ->assertJsonPath('data.sequence', 1)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.address', 'Av. Corrientes 1234')
        ->assertJsonPath('data.contact_name', 'Juan Pérez')
        ->assertJsonPath('data.contact_phone', '+5491155551234')
        ->assertJsonPath('data.timezone', 'America/Argentina/Buenos_Aires')
        ->assertJsonStructure([
            'status',
            'data' => [
                'id',
                'route_id',
                'sequence',
                'status',
                'address',
                'contact_name',
                'contact_phone',
                'timezone',
                'eta',
                'notification_window_start',
                'notification_window_end',
                'notes',
                'items' => [
                    '*' => [
                        'id',
                        'product_id',
                        'product_name',
                        'sku',
                        'quantity_planned',
                        'quantity_loaded',
                        'quantity_delivered',
                        'original_route_stop_id',
                        'is_extra',
                        'notes',
                    ],
                ],
                'collections' => [
                    '*' => [
                        'id',
                        'status',
                        'amount',
                        'method',
                        'declared_at',
                    ],
                ],
            ],
            'errors',
        ]);
});

test('driver stops show includes is_extra as false in items', function () {
    $data = createDriverTestData($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/stops/{$stop->id}");

    $response->assertStatus(200);

    // All items should have is_extra = false (hardcoded in Resource)
    $items = $response->json('data.items');
    foreach ($items as $item) {
        expect($item['is_extra'])->toBe(false);
    }
});

test('driver stops show returns 404 for stop not assigned to driver', function () {
    $data = createDriverTestData($this->otherDriver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];

    // Try to access with the first driver
    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/stops/{$stop->id}");

    $response->assertStatus(404);
});

test('driver stops show returns 404 for non-existent stop', function () {
    $fakeId = '00000000-0000-0000-0000-000000000000';

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/stops/{$fakeId}");

    $response->assertStatus(404);
});

test('driver stops show includes product name and sku in items', function () {
    $data = createDriverTestData($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/stops/{$stop->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.items.0.product_name', 'Producto A')
        ->assertJsonPath('data.items.0.sku', 'SKU-001')
        ->assertJsonPath('data.items.1.product_name', 'Producto B')
        ->assertJsonPath('data.items.1.sku', 'SKU-002');
});

test('driver stops show includes notes from logistics_notes', function () {
    $data = createDriverTestData($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/stops/{$stop->id}");

    $response->assertStatus(200)
        ->assertJsonPath('data.notes', 'Timbre en piso 3');
});

// ═══════════════════════════════════════════════════════════════════════════════
// TESTS: lectura-only verification (no side effects)
// ═══════════════════════════════════════════════════════════════════════════════

test('driver routes stops does not modify any records', function () {
    $data = createDriverTestData($this->driver, $this->store, $this->vehicle);
    $route = $data['route'];
    $originalStatus = $data['stops'][0]->status;

    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/routes/{$route->id}/stops");

    $this->assertDatabaseHas('route_stops', [
        'id' => $data['stops'][0]->id,
        'status' => $originalStatus,
    ]);
});

test('driver stops show does not modify any records', function () {
    $data = createDriverTestData($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $originalStatus = $stop->status;

    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/stops/{$stop->id}");

    $this->assertDatabaseHas('route_stops', [
        'id' => $stop->id,
        'status' => $originalStatus,
    ]);
});
