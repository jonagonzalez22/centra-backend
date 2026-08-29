<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryDiscrepancy;
use App\Models\DeliveryRoute;
use App\Models\DeliveryRouteEvent;
use App\Models\Feature;
use App\Models\Locality;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Province;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
use App\Models\Store;
use App\Models\StorePaymentMethod;
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
    Role::create(['name' => 'STORE_USER', 'guard_name' => 'web']);
    Role::create(['name' => 'SUPER_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'BACKOFFICE_USER', 'guard_name' => 'web']);

    Permission::firstOrCreate(['name' => 'orders.view', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'orders.edit', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();
    $this->plan = Plan::factory()->create();
    $this->store->plan_id = $this->plan->id;
    $this->store->save();

    $posFeature = Feature::firstOrCreate(['code' => 'pos', 'name' => 'Punto de Venta']);
    $this->plan->features()->syncWithoutDetaching([$posFeature->id => ['limit_value' => null]]);

    $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
    $this->province = Province::factory()->create();
    $this->locality = Locality::factory()->create(['province_id' => $this->province->id]);
    $this->address = CustomerAddress::factory()->create([
        'customer_id' => $this->customer->id,
        'locality_id' => $this->locality->id,
        'is_main' => true,
        'street' => 'Av. Corrientes',
        'number' => '1234',
        'observations' => 'Timbre 4B',
    ]);

    $this->product = Product::factory()->create([
        'store_id' => $this->store->id,
        'name' => 'Producto Test',
        'price' => 100.00,
    ]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo('orders.view');
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

function makeDetailUser(Store $store, string $role = 'STORE_ADMIN', array $permissions = []): User
{
    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole($role);

    foreach ($permissions as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        $user->givePermissionTo($perm);
    }

    return $user;
}

function createDetailOrder(Store $store, array $attributes = []): \App\Models\CommercialOperation
{
    $user = User::factory()->create(['store_id' => $store->id]);

    return \App\Models\CommercialOperation::factory()->create(array_merge([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'type' => 'order',
        'status' => 'open',
    ], $attributes));
}

function getOrderDetail(string $id, ?string $token = null): \Illuminate\Testing\TestResponse
{
    $token = $token ?? test()->token;

    return test()->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/store/orders/'.$id);
}

function makePaymentMethodForStoreDetail(Store $store): StorePaymentMethod
{
    $paymentMethod = PaymentMethod::factory()->create();

    return StorePaymentMethod::factory()->create([
        'store_id' => $store->id,
        'payment_method_id' => $paymentMethod->id,
    ]);
}

function createDetailRouteStop(
    Store $store,
    \App\Models\CommercialOperation $order,
    Product $product,
    User $driver,
    string $routeStatus = 'planned',
    string $stopStatus = 'pending',
    int $planned = 10,
    int $loaded = 0,
    int $delivered = 0,
    ?string $processedAt = null
): array {
    $vehicle = Vehicle::factory()->forStore($store)->create();
    $route = DeliveryRoute::create([
        'store_id' => $store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => now()->format('Y-m-d'),
        'status' => $routeStatus,
        'created_by' => $driver->id,
        'processed_at' => $processedAt,
        'processed_by' => $processedAt ? $driver->id : null,
    ]);
    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => $stopStatus,
        'completed_by' => $stopStatus === 'completed' ? $driver->id : null,
        'completed_at' => $stopStatus === 'completed' ? now() : null,
    ]);
    $item = RouteStopItem::create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
        'quantity_planned' => $planned,
        'quantity_loaded' => $loaded,
        'quantity_delivered' => $delivered,
    ]);

    DeliveryRouteEvent::create([
        'store_id' => $store->id,
        'route_id' => $route->id,
        'user_id' => $driver->id,
        'event_type' => 'stop_added',
        'metadata' => ['stop_id' => $stop->id, 'order_id' => $order->id],
    ]);

    if ($stopStatus === 'completed') {
        DeliveryRouteEvent::create([
            'store_id' => $store->id,
            'route_id' => $route->id,
            'user_id' => $driver->id,
            'event_type' => 'stop_completed',
            'metadata' => ['stop_id' => $stop->id],
        ]);
    }

    if ($routeStatus === 'completed') {
        DeliveryRouteEvent::create([
            'store_id' => $store->id,
            'route_id' => $route->id,
            'user_id' => $driver->id,
            'event_type' => 'route_reconciliation_completed',
            'from_status' => 'awaiting_reconciliation',
            'to_status' => 'completed',
        ]);
    }

    return [$route, $stop, $item];
}

describe('GET /api/v1/store/orders/{id} — Orders Detail', function () {
    test('unauthenticated request returns 401', function () {
        $order = createDetailOrder($this->store);
        $response = $this->getJson('/api/v1/store/orders/'.$order->id);
        $response->assertStatus(401);
    });

    test('user without orders.view permission returns 403', function () {
        $order = createDetailOrder($this->store);
        $userWithoutPerm = makeDetailUser($this->store, 'STORE_USER');
        $token = $userWithoutPerm->createToken('test-token')->plainTextToken;

        $response = getOrderDetail($order->id, $token);

        $response->assertStatus(403);
    });

    test('returns full order detail', function () {
        $order = createDetailOrder($this->store, [
            'customer_id' => $this->customer->id,
            'total' => 500.00,
            'subtotal' => 400.00,
            'tax' => 80.00,
            'discount' => 20.00,
            'requested_delivery_date' => '2026-08-15',
            'completed_at' => now()->subDay(),
        ]);

        $response = getOrderDetail($order->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.operation_number', $order->operation_number)
            ->assertJsonPath('data.type', 'order')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.requested_delivery_date', '2026-08-15');
        $data = $response->json('data');
        expect((float) $data['subtotal'])->toBe(400.00);
        expect((float) $data['tax'])->toBe(80.00);
        expect((float) $data['discount'])->toBe(20.00);
        expect((float) $data['total'])->toBe(500.00);
    });

    test('returns 404 for non-existent order', function () {
        $fakeId = (string) \Illuminate\Support\Str::uuid();

        $response = getOrderDetail($fakeId);

        $response->assertStatus(404);
    });

    test('returns 404 for type sale operation', function () {
        $sale = \App\Models\CommercialOperation::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'type' => 'sale',
            'status' => 'confirmed',
        ]);

        $response = getOrderDetail($sale->id);

        $response->assertStatus(404);
    });

    test('returns 404 for order from different store', function () {
        $otherStore = Store::factory()->create();
        $otherPlan = Plan::factory()->create();
        $otherStore->plan_id = $otherPlan->id;
        $otherStore->save();
        $posFeature = Feature::firstOrCreate(['code' => 'pos', 'name' => 'Punto de Venta']);
        $otherPlan->features()->syncWithoutDetaching([$posFeature->id => ['limit_value' => null]]);

        $otherOrder = createDetailOrder($otherStore);

        $response = getOrderDetail($otherOrder->id);

        $response->assertStatus(404);
    });

    test('includes items with product info', function () {
        $order = createDetailOrder($this->store);
        OperationItem::factory()->create([
            'operation_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 3,
            'price' => 100.00,
            'subtotal' => 300.00,
        ]);

        $response = getOrderDetail($order->id);

        $response->assertStatus(200);
        $items = $response->json('data.items');
        expect($items)->toHaveCount(1);
        expect($items[0]['product_id'])->toBe($this->product->id);
        expect($items[0]['product_name'])->toBe($this->product->name);
        expect($items[0]['quantity'])->toBe(3);
    });

    test('includes payments with payment method', function () {
        $order = createDetailOrder($this->store, ['total' => 300.00]);
        $spm = makePaymentMethodForStoreDetail($this->store);
        OperationPayment::factory()->create([
            'operation_id' => $order->id,
            'store_payment_method_id' => $spm->id,
            'amount' => 300.00,
        ]);

        $response = getOrderDetail($order->id);

        $response->assertStatus(200);
        $payments = $response->json('data.payments');
        expect($payments)->toHaveCount(1);
        expect((float) $payments[0]['amount'])->toBe(300.00);
        expect($payments[0]['store_payment_method']['name'])->not->toBeNull();
    });

    test('paid_amount and pending_amount are correct', function () {
        $order = createDetailOrder($this->store, ['total' => 1000.00]);
        $spm = makePaymentMethodForStoreDetail($this->store);
        OperationPayment::factory()->create([
            'operation_id' => $order->id,
            'store_payment_method_id' => $spm->id,
            'amount' => 600.00,
        ]);

        $response = getOrderDetail($order->id);

        $response->assertStatus(200);
        expect((float) $response->json('data.paid_amount'))->toBe(600.00);
        expect((float) $response->json('data.pending_amount'))->toBe(400.00);
    });

    test('includes events with user', function () {
        $order = createDetailOrder($this->store);
        $eventUser = User::factory()->create(['store_id' => $this->store->id]);

        \App\Models\CommercialOperationEvent::factory()->create([
            'store_id' => $this->store->id,
            'operation_id' => $order->id,
            'event_type' => 'reschedule',
            'previous_date' => '2026-07-20',
            'new_date' => '2026-07-25',
            'previous_status' => 'open',
            'new_status' => 'open',
            'reason_code' => 'customer_requested_reschedule',
            'reason_note' => 'Customer asked to change',
            'user_id' => $eventUser->id,
        ]);

        $response = getOrderDetail($order->id);

        $response->assertStatus(200);
        $events = $response->json('data.events');
        expect($events)->toHaveCount(1);
        expect($events[0]['event_type'])->toBe('reschedule');
        expect($events[0]['reason_code'])->toBe('customer_requested_reschedule');
        expect($events[0]['old_values']['status'])->toBe('open');
        expect($events[0]['old_values']['date'])->toBe('2026-07-20');
        expect($events[0]['new_values']['status'])->toBe('open');
        expect($events[0]['new_values']['date'])->toBe('2026-07-25');
        expect($events[0]['user'])->not->toBeNull();
        expect($events[0]['user']['id'])->toBe($eventUser->id);
        $historyEvent = collect($response->json('data.history'))->firstWhere('type', 'reschedule');
        expect($historyEvent)->not->toBeNull()
            ->and($historyEvent['title'])->toBe('Fecha de entrega reprogramada')
            ->and($historyEvent['details']['reason_code'])->toBe('customer_requested_reschedule');
    });

    test('history always includes the derived order creation', function () {
        $order = createDetailOrder($this->store);

        $response = getOrderDetail($order->id)->assertOk();

        $response->assertJsonPath('data.events', []);
        expect($response->json('data.history'))->toHaveCount(1)
            ->and($response->json('data.history.0.type'))->toBe('order_created')
            ->and($response->json('data.history.0.title'))->toBe('Pedido creado')
            ->and($response->json('data.history.0.status'))->toBe('confirmed');
    });

    test('history shows a route assignment once using the route stop as source', function () {
        $order = createDetailOrder($this->store);
        OperationItem::factory()->create([
            'operation_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
        ]);
        [$route] = createDetailRouteStop($this->store, $order, $this->product, $this->user);

        $history = collect(getOrderDetail($order->id)->assertOk()->json('data.history'));
        $assignments = $history->where('type', 'route_assigned');

        expect($assignments)->toHaveCount(1)
            ->and($assignments->first()['route']['id'])->toBe($route->id);
    });

    test('history shows a driver delivery as provisional before reconciliation', function () {
        $order = createDetailOrder($this->store, ['status' => 'open']);
        OperationItem::factory()->create([
            'operation_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 100,
        ]);
        createDetailRouteStop(
            $this->store,
            $order,
            $this->product,
            $this->user,
            'awaiting_reconciliation',
            'completed',
            70,
            70,
            70
        );

        $response = getOrderDetail($order->id)->assertOk();
        $delivery = collect($response->json('data.history'))->firstWhere('type', 'delivery_reported');

        expect($order->fresh()->status)->toBe('open')
            ->and($delivery['status'])->toBe('provisional')
            ->and($delivery['description'])->toBe('Pendiente de conciliación')
            ->and($delivery['details']['items'][0]['quantity_delivered'])->toBe(70);
    });

    test('history keeps partial and final reconciliations from multiple routes in order', function () {
        $order = createDetailOrder($this->store, ['status' => 'delivered']);
        OperationItem::factory()->create([
            'operation_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 100,
        ]);
        [$routeOne] = createDetailRouteStop(
            $this->store,
            $order,
            $this->product,
            $this->user,
            'completed',
            'completed',
            70,
            70,
            70,
            '2026-08-20 10:00:00'
        );
        [$routeTwo] = createDetailRouteStop(
            $this->store,
            $order,
            $this->product,
            $this->user,
            'completed',
            'completed',
            30,
            30,
            30,
            '2026-08-21 10:00:00'
        );

        $deliveries = collect(getOrderDetail($order->id)->assertOk()->json('data.history'))
            ->filter(fn (array $entry) => str_starts_with($entry['type'], 'delivery_reconciled'))
            ->values();

        expect($deliveries)->toHaveCount(2)
            ->and($deliveries[0]['type'])->toBe('delivery_reconciled_partial')
            ->and($deliveries[0]['route']['id'])->toBe($routeOne->id)
            ->and($deliveries[0]['description'])->toBe('Queda mercadería pendiente')
            ->and($deliveries[1]['type'])->toBe('delivery_reconciled_final')
            ->and($deliveries[1]['route']['id'])->toBe($routeTwo->id)
            ->and($deliveries[1]['description'])->toBe('Pedido entregado');
    });

    test('history exposes resolved discrepancies inside delivery details', function () {
        $order = createDetailOrder($this->store, ['status' => 'partially_delivered']);
        OperationItem::factory()->create([
            'operation_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => 10,
        ]);
        [, , $item] = createDetailRouteStop(
            $this->store,
            $order,
            $this->product,
            $this->user,
            'completed',
            'completed',
            10,
            10,
            8,
            '2026-08-20 10:00:00'
        );
        $discrepancy = DeliveryDiscrepancy::create([
            'route_stop_item_id' => $item->id,
            'product_id' => $this->product->id,
            'quantity_loaded' => 10,
            'quantity_delivered' => 8,
            'difference_quantity' => 2,
            'resolution_type' => 'pending_redelivery',
            'notes' => 'Reprogramar entrega',
            'resolved_by' => $this->user->id,
            'resolved_at' => now(),
        ]);

        $history = collect(getOrderDetail($order->id)->assertOk()->json('data.history'));
        $delivery = $history->firstWhere('type', 'delivery_reconciled_partial');
        $serialized = $delivery['details']['items'][0]['discrepancies'][0];

        expect($serialized['id'])->toBe($discrepancy->id)
            ->and($serialized['quantity'])->toBe(2)
            ->and($serialized['resolution_type'])->toBe('pending_redelivery')
            ->and($serialized['resolved_by']['id'])->toBe($this->user->id);
    });

    test('history excludes route data whose route belongs to another store', function () {
        $order = createDetailOrder($this->store);
        $otherStore = Store::factory()->create();
        $otherDriver = User::factory()->create(['store_id' => $otherStore->id]);
        createDetailRouteStop($otherStore, $order, $this->product, $otherDriver);

        $history = collect(getOrderDetail($order->id)->assertOk()->json('data.history'));

        expect($history->pluck('type')->all())->toBe(['order_created']);
    });

    test('includes delivery_address from customer main address', function () {
        $order = createDetailOrder($this->store, ['customer_id' => $this->customer->id]);

        $response = getOrderDetail($order->id);

        $response->assertStatus(200);
        $address = $response->json('data.delivery_address');
        expect($address)->not->toBeNull();
        expect($address['id'])->toBe($this->address->id);
        expect($address['street'])->toBe('Av. Corrientes');
        expect($address['number'])->toBe('1234');
        expect($address['locality'])->toBe($this->locality->name);
        expect($address['province'])->toBe($this->province->name);
        expect($address['notes'])->toBe('Timbre 4B');
        expect($address['full_address'])->toBe('Av. Corrientes 1234');
    });

    test('includes created_by from user relationship', function () {
        $creator = User::factory()->create(['store_id' => $this->store->id, 'name' => 'Creator User']);
        $order = createDetailOrder($this->store, ['user_id' => $creator->id]);

        $response = getOrderDetail($order->id);

        $response->assertStatus(200);
        expect($response->json('data.created_by'))->not->toBeNull();
        expect($response->json('data.created_by.id'))->toBe($creator->id);
        expect($response->json('data.created_by.name'))->toBe('Creator User');
    });

    test('delivery_time_from and delivery_time_to are null', function () {
        $order = createDetailOrder($this->store);

        $response = getOrderDetail($order->id);

        $response->assertStatus(200);
        expect($response->json('data.delivery_time_from'))->toBeNull();
        expect($response->json('data.delivery_time_to'))->toBeNull();
    });
});
