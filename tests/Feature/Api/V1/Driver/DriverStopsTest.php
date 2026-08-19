<?php

use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerContact;
use App\Models\DeliveryRoute;
use App\Models\Feature;
use App\Models\OperationPayment;
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

    // Ensure Carbon::parse() interprets naive datetime strings in the store's timezone
    // rather than the system/PHP default timezone (UTC in CI).
    date_default_timezone_set($this->store->timezone);
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

// ═══════════════════════════════════════════════════════════════════════════════
// TESTS: order economic info (total, paid_amount, pending_amount)
// ═══════════════════════════════════════════════════════════════════════════════

test('driver stops show includes correct order economic info with no payments', function () {
    $customer = Customer::factory()->for($this->store)->create(['display_name' => 'Test Customer']);

    $order = CommercialOperation::factory()
        ->forStore($this->store)
        ->forCustomer($customer)
        ->order()
        ->create(['total' => 1800.00]);

    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => 'dispatched',
    ]);

    $stop = RouteStop::factory()->create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/stops/{$stop->id}");

    $response->assertStatus(200);
    expect($response->json('data.order.total'))->toEqual(1800.00);
    expect($response->json('data.order.paid_amount'))->toEqual(0.0);
    expect($response->json('data.order.pending_amount'))->toEqual(1800.00);
});

test('driver stops show includes correct order economic info with partial payment', function () {
    $customer = Customer::factory()->for($this->store)->create(['display_name' => 'Test Customer']);

    $order = CommercialOperation::factory()
        ->forStore($this->store)
        ->forCustomer($customer)
        ->order()
        ->create(['total' => 1800.00]);

    OperationPayment::factory()->forOperation($order)->create(['amount' => 300.00]);

    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => 'dispatched',
    ]);

    $stop = RouteStop::factory()->create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/stops/{$stop->id}");

    $response->assertStatus(200);
    expect($response->json('data.order.total'))->toEqual(1800.00);
    expect($response->json('data.order.paid_amount'))->toEqual(300.00);
    expect($response->json('data.order.pending_amount'))->toEqual(1500.00);
});

test('driver stops show includes correct order economic info with full payment', function () {
    $customer = Customer::factory()->for($this->store)->create(['display_name' => 'Test Customer']);

    $order = CommercialOperation::factory()
        ->forStore($this->store)
        ->forCustomer($customer)
        ->order()
        ->create(['total' => 1800.00]);

    OperationPayment::factory()->forOperation($order)->create(['amount' => 1800.00]);

    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => 'dispatched',
    ]);

    $stop = RouteStop::factory()->create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/stops/{$stop->id}");

    $response->assertStatus(200);
    expect($response->json('data.order.total'))->toEqual(1800.00);
    expect($response->json('data.order.paid_amount'))->toEqual(1800.00);
    expect($response->json('data.order.pending_amount'))->toEqual(0.00);
});

test('driver stops show pending_amount is never negative even when paid exceeds total', function () {
    $customer = Customer::factory()->for($this->store)->create(['display_name' => 'Test Customer']);

    $order = CommercialOperation::factory()
        ->forStore($this->store)
        ->forCustomer($customer)
        ->order()
        ->create(['total' => 1800.00]);

    // Paid more than total (overpayment scenario)
    OperationPayment::factory()->forOperation($order)->create(['amount' => 2000.00]);

    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => 'dispatched',
    ]);

    $stop = RouteStop::factory()->create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/stops/{$stop->id}");

    $response->assertStatus(200);
    expect($response->json('data.order.total'))->toEqual(1800.00);
    expect($response->json('data.order.paid_amount'))->toEqual(2000.00);
    expect($response->json('data.order.pending_amount'))->toEqual(0.00); // Should be 0, not negative
});

test('driver stops show declared collection does not affect pending_amount', function () {
    $customer = Customer::factory()->for($this->store)->create(['display_name' => 'Test Customer']);

    $order = CommercialOperation::factory()
        ->forStore($this->store)
        ->forCustomer($customer)
        ->order()
        ->create(['total' => 1800.00]);

    // No OperationPayment yet - only a declared RouteStopCollection
    $paymentMethod = StorePaymentMethod::factory()->for($this->store)->create();

    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => 'dispatched',
    ]);

    $stop = RouteStop::factory()->create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    // Driver declared a collection but it hasn't been verified by backoffice
    RouteStopCollection::factory()->create([
        'route_stop_id' => $stop->id,
        'store_id' => $this->store->id,
        'commercial_operation_id' => $order->id,
        'store_payment_method_id' => $paymentMethod->id,
        'amount' => 1500.00,
        'status' => 'declared',
        'declared_by' => $this->driver->id,
        'declared_at' => now(),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/stops/{$stop->id}");

    $response->assertStatus(200);
    expect($response->json('data.order.total'))->toEqual(1800.00);
    expect($response->json('data.order.paid_amount'))->toEqual(0.00); // No OperationPayment yet
    expect($response->json('data.order.pending_amount'))->toEqual(1800.00); // Still full amount
});

test('driver routes stops includes order economic info in each stop', function () {
    $customer1 = Customer::factory()->for($this->store)->create(['display_name' => 'Customer 1']);
    $customer2 = Customer::factory()->for($this->store)->create(['display_name' => 'Customer 2']);

    $order1 = CommercialOperation::factory()
        ->forStore($this->store)
        ->forCustomer($customer1)
        ->order()
        ->create(['total' => 1000.00]);

    $order2 = CommercialOperation::factory()
        ->forStore($this->store)
        ->forCustomer($customer2)
        ->order()
        ->create(['total' => 2000.00]);

    OperationPayment::factory()->forOperation($order1)->create(['amount' => 400.00]);

    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => 'dispatched',
    ]);

    $stop1 = RouteStop::factory()->create([
        'route_id' => $route->id,
        'order_id' => $order1->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $stop2 = RouteStop::factory()->create([
        'route_id' => $route->id,
        'order_id' => $order2->id,
        'sequence' => 2,
        'status' => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson("/api/v1/driver/routes/{$route->id}/stops");

    $response->assertStatus(200);
    expect($response->json('data.0.order.total'))->toEqual(1000.00);
    expect($response->json('data.0.order.paid_amount'))->toEqual(400.00);
    expect($response->json('data.0.order.pending_amount'))->toEqual(600.00);
    expect($response->json('data.1.order.total'))->toEqual(2000.00);
    expect($response->json('data.1.order.paid_amount'))->toEqual(0.00);
    expect($response->json('data.1.order.pending_amount'))->toEqual(2000.00);
});
