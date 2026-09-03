<?php

use App\Models\CommercialOperation;
use App\Models\DeliveryRejectionReason;
use App\Models\DeliveryRoute;
use App\Models\Feature;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\RouteStop;
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
    Role::create(['name' => 'STORE_USER', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();

    $plan = Plan::factory()->create();
    $dFeature = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $plan->features()->attach($dFeature->id);
    $this->store->update(['plan_id' => $plan->id]);

    $this->vehicle = Vehicle::factory()->create(['store_id' => $this->store->id]);

    $this->driver = User::factory()->create(['store_id' => $this->store->id]);
    $this->driver->assignRole('STORE_DRIVER');
    $this->driverToken = $this->driver->createToken('driver-test')->plainTextToken;

    $this->nonDriver = User::factory()->create(['store_id' => $this->store->id]);
    $this->nonDriver->assignRole('STORE_ADMIN');
    $this->nonDriverToken = $this->nonDriver->createToken('admin-test')->plainTextToken;

    $this->storeUser = User::factory()->create(['store_id' => $this->store->id]);
    $this->storeUser->assignRole('STORE_USER');
    $this->storeUserToken = $this->storeUser->createToken('user-test')->plainTextToken;

    $this->seed(\Database\Seeders\DeliveryRejectionReasonSeeder::class);
});

// ─── Helper: create a dispatched route with stops, items, and order financials ──

function createDispatchedRouteWithFinancials(User $driver, Store $store, Vehicle $vehicle): array
{
    $product1 = Product::factory()->create(['store_id' => $store->id]);
    $product2 = Product::factory()->create(['store_id' => $store->id]);

    // Create CommercialOperation with items
    $order = CommercialOperation::factory()->create([
        'store_id' => $store->id,
        'type' => 'order',
        'status' => 'confirmed',
        'total' => 1500.00,
        'subtotal' => 1260.50,
        'tax' => 239.50,
    ]);

    $item1 = OperationItem::factory()->create([
        'operation_id' => $order->id,
        'product_id' => $product1->id,
        'quantity' => 10,
        'price' => 100.00,
        'subtotal' => 1000.00,
    ]);

    $item2 = OperationItem::factory()->create([
        'operation_id' => $order->id,
        'product_id' => $product2->id,
        'quantity' => 5,
        'price' => 100.00,
        'subtotal' => 500.00,
    ]);

    // Create payments: 800 paid, 700 pending
    $pm1 = PaymentMethod::factory()->create();
    $spm1 = StorePaymentMethod::factory()->create([
        'store_id' => $store->id,
        'payment_method_id' => $pm1->id,
        'requires_reference' => false,
    ]);

    $pay1 = OperationPayment::factory()->create([
        'operation_id' => $order->id,
        'store_payment_method_id' => $spm1->id,
        'amount' => 800.00,
    ]);

    $route = DeliveryRoute::factory()->create([
        'store_id' => $store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => 'dispatched',
        'dispatched_at' => now(),
    ]);

    $stop1 = RouteStop::factory()->create([
        'route_id' => $route->id,
        'order_id' => $order->id,
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

    return [
        'route' => $route,
        'order' => $order,
        'stops' => [$stop1, $stop2],
        'stopItems' => [$stopItem1, $stopItem2],
        'product1' => $product1,
        'product2' => $product2,
        'spm1' => $spm1,
        'pay1' => $pay1,
    ];
}

// ─── Helper: simple dispatched route (original) ──

function createDispatchedRouteSimple(User $driver, Store $store, Vehicle $vehicle): array
{
    $route = DeliveryRoute::factory()->create([
        'store_id' => $store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'status' => 'dispatched',
        'dispatched_at' => now(),
    ]);

    $product1 = Product::factory()->create(['store_id' => $store->id]);

    $stop1 = RouteStop::factory()->create([
        'route_id' => $route->id,
        'sequence' => 1,
        'status' => 'pending',
    ]);

    $stopItem1 = RouteStopItem::factory()->create([
        'route_stop_id' => $stop1->id,
        'product_id' => $product1->id,
        'quantity_planned' => 10,
        'quantity_loaded' => 10,
        'quantity_delivered' => 0,
    ]);

    return [
        'route' => $route,
        'stops' => [$stop1],
        'items' => [$stopItem1],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// PHASE 5: Rejection Reasons endpoint
// ═══════════════════════════════════════════════════════════════════════════

test('rejection reasons returns active global reasons for store user', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->storeUserToken}")
        ->getJson('/api/v1/store/logistics/rejection-reasons');

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonCount(DeliveryRejectionReason::whereNull('store_id')->where('is_active', true)->count(), 'data');
});

test('rejection reasons returns global plus store-specific reasons', function () {
    $globalCount = DeliveryRejectionReason::whereNull('store_id')->where('is_active', true)->count();

    DeliveryRejectionReason::create([
        'store_id' => $this->store->id,
        'code' => 'tienda_cerrada',
        'label' => 'Tienda cerrada por feriado',
        'is_active' => true,
    ]);

    DeliveryRejectionReason::create([
        'store_id' => $this->store->id,
        'code' => 'zona_peligrosa',
        'label' => 'Zona peligrosa',
        'is_active' => false, // inactive — should NOT appear
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->storeUserToken}")
        ->getJson('/api/v1/store/logistics/rejection-reasons');

    $response->assertStatus(200)
        ->assertJsonCount($globalCount + 1, 'data'); // global + 1 active store-specific
});

test('rejection reasons returns 401 for unauthenticated', function () {
    $response = $this->getJson('/api/v1/store/logistics/rejection-reasons');
    $response->assertStatus(401);
});

// ═══════════════════════════════════════════════════════════════════════════
// PHASE 6: active-route returns financial data and payment methods
// ═══════════════════════════════════════════════════════════════════════════

test('active route includes available_payment_methods', function () {
    $data = createDispatchedRouteSimple($this->driver, $this->store, $this->vehicle);

    $pm = PaymentMethod::factory()->create();
    $spm = StorePaymentMethod::factory()->create([
        'store_id' => $this->store->id,
        'payment_method_id' => $pm->id,
        'is_enabled' => true,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson('/api/v1/driver/active-route');

    $response->assertStatus(200)
        ->assertJsonPath('data.available_payment_methods.0.id', $spm->id)
        ->assertJsonPath('data.available_payment_methods.0.payment_method.id', $pm->id);
});

test('active route includes order financial data in stops', function () {
    $data = createDispatchedRouteWithFinancials($this->driver, $this->store, $this->vehicle);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->getJson('/api/v1/driver/active-route');

    $response->assertStatus(200);

    // Check stop 1 has financial data in order (use float-compatible assertions)
    $stopData = $response->json('data.route.stops.0.order');
    expect((float) $stopData['total_amount'])->toEqualWithDelta(1500.00, 0.01);
    expect((float) $stopData['paid_amount'])->toEqualWithDelta(800.00, 0.01);
    expect((float) $stopData['pending_balance'])->toEqualWithDelta(700.00, 0.01);
});

// ═══════════════════════════════════════════════════════════════════════════
// PHASE 3a: arriveStop sets status to 'arrived'
// ═══════════════════════════════════════════════════════════════════════════

test('arrive sets stop status to arrived', function () {
    $data = createDispatchedRouteSimple($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/arrive", [
            'gps_lat' => -34.6037,
            'gps_lon' => -58.3816,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'arrived');

    $this->assertDatabaseHas('route_stops', [
        'id' => $stop->id,
        'status' => 'arrived',
        'gps_lat' => -34.6037000,
        'gps_lon' => -58.3816000,
    ]);
});

test('arrive works without gps coordinates', function () {
    $data = createDispatchedRouteSimple($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/arrive", []);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'arrived');

    $this->assertDatabaseHas('route_stops', [
        'id' => $stop->id,
        'status' => 'arrived',
    ]);
});

// ═══════════════════════════════════════════════════════════════════════════
// PHASE 3c: completeStop with payment collections
// ═══════════════════════════════════════════════════════════════════════════

test('complete stop with payments creates route_stop_collections', function () {
    $data = createDispatchedRouteWithFinancials($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $stopItems = $data['stopItems'];

    $pm2 = PaymentMethod::factory()->create();
    $spm2 = StorePaymentMethod::factory()->create([
        'store_id' => $this->store->id,
        'payment_method_id' => $pm2->id,
        'requires_reference' => false,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $stopItems[0]->id, 'quantity_delivered' => 10],
                ['route_stop_item_id' => $stopItems[1]->id, 'quantity_delivered' => 5],
            ],
            'payments' => [
                ['store_payment_method_id' => $data['spm1']->id, 'amount' => 400.00, 'reference' => '12345'],
                ['store_payment_method_id' => $spm2->id, 'amount' => 300.00],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'completed');

    // Verify collections were created
    $this->assertDatabaseHas('route_stop_collections', [
        'route_stop_id' => $stop->id,
        'store_payment_method_id' => $data['spm1']->id,
        'amount' => 400.00,
        'reference' => '12345',
        'status' => 'declared',
        'declared_by' => $this->driver->id,
    ]);

    $this->assertDatabaseHas('route_stop_collections', [
        'route_stop_id' => $stop->id,
        'store_payment_method_id' => $spm2->id,
        'amount' => 300.00,
        'status' => 'declared',
        'declared_by' => $this->driver->id,
    ]);

    // Should have exactly 2 collections
    $this->assertEquals(2, \App\Models\RouteStopCollection::where('route_stop_id', $stop->id)->count());
});

test('complete stop rejects payments when pending balance is zero', function () {
    $data = createDispatchedRouteWithFinancials($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $stopItems = $data['stopItems'];

    // Pay remaining balance to make it zero
    OperationPayment::factory()->create([
        'operation_id' => $data['order']->id,
        'store_payment_method_id' => $data['spm1']->id,
        'amount' => 700.00,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $stopItems[0]->id, 'quantity_delivered' => 10],
                ['route_stop_item_id' => $stopItems[1]->id, 'quantity_delivered' => 5],
            ],
            'payments' => [
                ['store_payment_method_id' => $data['spm1']->id, 'amount' => 100.00],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'El pedido no tiene saldo habilitado para cobrar en esta entrega.');
});

test('complete stop rejects payments when total exceeds pending balance', function () {
    $data = createDispatchedRouteWithFinancials($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $stopItems = $data['stopItems'];

    // Pending balance is 700 (1500 total - 800 paid)
    // Try to collect 800 (exceeds 700)
    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $stopItems[0]->id, 'quantity_delivered' => 10],
                ['route_stop_item_id' => $stopItems[1]->id, 'quantity_delivered' => 5],
            ],
            'payments' => [
                ['store_payment_method_id' => $data['spm1']->id, 'amount' => 800.00],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'El total declarado supera el monto habilitado para cobrar en esta entrega.');
});

test('collection preview values proposed quantities without persisting them', function () {
    $data = createDispatchedRouteWithFinancials($this->driver, $this->store, $this->vehicle);
    $data['order']->payments()->delete();
    $data['order']->items()->update(['tax_amount' => 0, 'discount_amount' => 0]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$data['stops'][0]->id}/collection-preview", [
            'items' => [
                ['route_stop_item_id' => $data['stopItems'][0]->id, 'quantity_delivered' => 3],
                ['route_stop_item_id' => $data['stopItems'][1]->id, 'quantity_delivered' => 0],
            ],
        ])
        ->assertOk();

    expect((float) $response->json('data.delivered_value_current_stop'))->toBe(300.0)
        ->and((float) $response->json('data.delivered_value_cumulative'))->toBe(300.0)
        ->and((float) $response->json('data.amount_to_collect_now'))->toBe(300.0)
        ->and($data['stopItems'][0]->fresh()->quantity_delivered)->toBe(0);
});

test('collection preview rejects duplicated foreign and excessive stop items', function () {
    $data = createDispatchedRouteWithFinancials($this->driver, $this->store, $this->vehicle);
    $otherItem = RouteStopItem::factory()->loaded(1)->create();

    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$data['stops'][0]->id}/collection-preview", [
            'items' => [
                ['route_stop_item_id' => $data['stopItems'][0]->id, 'quantity_delivered' => 1],
                ['route_stop_item_id' => $data['stopItems'][0]->id, 'quantity_delivered' => 1],
            ],
        ])
        ->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$data['stops'][0]->id}/collection-preview", [
            'items' => [['route_stop_item_id' => $otherItem->id, 'quantity_delivered' => 1]],
        ])
        ->assertStatus(422);

    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$data['stops'][0]->id}/collection-preview", [
            'items' => [[
                'route_stop_item_id' => $data['stopItems'][0]->id,
                'quantity_delivered' => 11,
            ]],
        ])
        ->assertStatus(422);
});

test('collection preview enforces driver and store isolation', function () {
    $data = createDispatchedRouteWithFinancials($this->driver, $this->store, $this->vehicle);

    $this->withHeader('Authorization', "Bearer {$this->nonDriverToken}")
        ->postJson("/api/v1/driver/stops/{$data['stops'][0]->id}/collection-preview", [
            'items' => [[
                'route_stop_item_id' => $data['stopItems'][0]->id,
                'quantity_delivered' => 1,
            ]],
        ])
        ->assertStatus(403);
});

test('complete stop cannot collect more than the value delivered in a partial delivery', function () {
    $data = createDispatchedRouteWithFinancials($this->driver, $this->store, $this->vehicle);
    $data['order']->payments()->delete();
    $data['order']->items()->update(['tax_amount' => 0, 'discount_amount' => 0]);
    $reason = DeliveryRejectionReason::where('code', 'rejected_by_customer')->firstOrFail();

    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$data['stops'][0]->id}/complete", [
            'status' => 'completed',
            'items' => [
                [
                    'route_stop_item_id' => $data['stopItems'][0]->id,
                    'quantity_delivered' => 3,
                    'rejection_reason_id' => $reason->id,
                ],
                [
                    'route_stop_item_id' => $data['stopItems'][1]->id,
                    'quantity_delivered' => 0,
                ],
            ],
            'payments' => [['store_payment_method_id' => $data['spm1']->id, 'amount' => 301]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('message', 'El total declarado supera el monto habilitado para cobrar en esta entrega.');
});

test('complete stop validates payment method belongs to store', function () {
    $data = createDispatchedRouteWithFinancials($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $stopItems = $data['stopItems'];

    // Create a payment method belonging to ANOTHER store
    $otherStore = Store::factory()->create();
    $pmOther = PaymentMethod::factory()->create();
    $spmOther = StorePaymentMethod::factory()->create([
        'store_id' => $otherStore->id,
        'payment_method_id' => $pmOther->id,
        'requires_reference' => false,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $stopItems[0]->id, 'quantity_delivered' => 10],
                ['route_stop_item_id' => $stopItems[1]->id, 'quantity_delivered' => 5],
            ],
            'payments' => [
                ['store_payment_method_id' => $spmOther->id, 'amount' => 100.00],
            ],
        ]);

    $response->assertStatus(422);
});

test('complete stop validates reference required when method requires it', function () {
    $data = createDispatchedRouteWithFinancials($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $stopItems = $data['stopItems'];

    // Make the payment method require reference
    $data['spm1']->update(['requires_reference' => true]);

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $stopItems[0]->id, 'quantity_delivered' => 10],
                ['route_stop_item_id' => $stopItems[1]->id, 'quantity_delivered' => 5],
            ],
            'payments' => [
                ['store_payment_method_id' => $data['spm1']->id, 'amount' => 100.00],
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'El método de pago requiere una referencia.');
});

// ═══════════════════════════════════════════════════════════════════════════
// PHASE 3c: completeStop with evidence
// ═══════════════════════════════════════════════════════════════════════════

test('complete stop with evidence saves signature_uri and evidence_uris', function () {
    $data = createDispatchedRouteSimple($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $stopItem = $data['items'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $stopItem->id, 'quantity_delivered' => 10],
            ],
            'signature_uri' => 'https://storage.example.com/signatures/sig123.png',
            'evidence_uris' => [
                'https://storage.example.com/photos/photo1.jpg',
                'https://storage.example.com/photos/photo2.jpg',
            ],
        ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('route_stops', [
        'id' => $stop->id,
        'signature_uri' => 'https://storage.example.com/signatures/sig123.png',
    ]);

    $stopFresh = RouteStop::find($stop->id);
    $this->assertIsArray($stopFresh->evidence_uris);
    $this->assertCount(2, $stopFresh->evidence_uris);
    $this->assertEquals('https://storage.example.com/photos/photo1.jpg', $stopFresh->evidence_uris[0]);
});

test('complete stop without payments works as before (backward compatibility)', function () {
    $data = createDispatchedRouteSimple($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $stopItem = $data['items'][0];

    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $stopItem->id, 'quantity_delivered' => 10],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'completed');
});

test('complete stop can process from arrived status', function () {
    $data = createDispatchedRouteSimple($this->driver, $this->store, $this->vehicle);
    $stop = $data['stops'][0];
    $stopItem = $data['items'][0];

    // First arrive
    $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/arrive", [])
        ->assertStatus(200);

    // Then complete
    $response = $this->withHeader('Authorization', "Bearer {$this->driverToken}")
        ->postJson("/api/v1/driver/stops/{$stop->id}/complete", [
            'status' => 'completed',
            'items' => [
                ['route_stop_item_id' => $stopItem->id, 'quantity_delivered' => 10],
            ],
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.status', 'completed');
});
