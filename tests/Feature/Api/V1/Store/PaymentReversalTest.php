<?php

use App\Models\CashSession;
use App\Models\CommercialOperation;
use App\Models\CommercialOperationEvent;
use App\Models\Customer;
use App\Models\DeliveryRoute;
use App\Models\Feature;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopCollection;
use App\Models\RouteStopItem;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\CashBusinessDateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_USER', 'guard_name' => 'web']);
    foreach (['payments.view', 'payments.reverse', 'logistics.routes.reconcile'] as $permission) {
        Permission::create(['name' => $permission, 'guard_name' => 'web']);
    }

    $this->store = Store::factory()->create();
    $this->plan = Plan::factory()->create();
    $this->store->update(['plan_id' => $this->plan->id]);
    $this->plan->features()->attach(Feature::firstOrCreate(['code' => 'deliveries'], ['name' => 'Entregas'])->id);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['payments.view', 'payments.reverse', 'logistics.routes.reconcile']);
    $this->token = $this->user->createToken('payment-reversal')->plainTextToken;
    $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
    $this->session = CashSession::create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'status' => 'open',
        'business_date' => app(CashBusinessDateService::class)->currentForStore($this->store),
        'opening_amount' => 1000,
        'expected_amount' => 1500,
        'opened_at' => now(),
    ]);
    $this->cashMethod = reversalMethod($this->store, 'cash');
    $this->transferMethod = reversalMethod($this->store, 'transfer');
});

function reversalMethod(Store $store, string $code): StorePaymentMethod
{
    return StorePaymentMethod::factory()->create([
        'store_id' => $store->id,
        'payment_method_id' => PaymentMethod::factory()->create(['code' => $code, 'is_active' => true])->id,
    ]);
}

function reversalOrder(object $test, array $attributes = []): CommercialOperation
{
    return CommercialOperation::factory()->create(array_merge([
        'store_id' => $test->store->id,
        'user_id' => $test->user->id,
        'customer_id' => $test->customer->id,
        'type' => 'order',
        'status' => 'delivered',
        'subtotal' => 1000,
        'total' => 1000,
    ], $attributes));
}

function reversalPayment(object $test, CommercialOperation $operation, array $attributes = []): OperationPayment
{
    return OperationPayment::factory()->create(array_merge([
        'operation_id' => $operation->id,
        'store_payment_method_id' => $test->cashMethod->id,
        'cash_session_id' => $test->session->id,
        'registered_by' => $test->user->id,
        'status' => 'active',
        'amount' => 300,
        'payment_details' => ['origin' => 'manual_collection'],
    ], $attributes));
}

function reversePayment(object $test, OperationPayment $payment, array $data = [], ?string $token = null)
{
    return test()->withToken($token ?? $test->token)
        ->postJson(
            "/api/v1/store/received-payments/{$payment->id}/reverse",
            array_key_exists('reason_code', $data) ? $data : array_merge(['reason_code' => 'payment_error'], $data)
        );
}

test('reverses a manual cash collection on a delivered order and records the audit event', function () {
    $order = reversalOrder($this);
    $payment = reversalPayment($this, $order);

    $this->withToken($this->token)->getJson('/api/v1/store/received-payments')
        ->assertOk()
        ->assertJsonPath('data.items.0.reversal.allowed', true)
        ->assertJsonPath('data.items.0.reversal.reason', null);

    reversePayment($this, $payment)->assertOk()
        ->assertJsonPath('data.id', $payment->id)
        ->assertJsonPath('data.status', 'reversed')
        ->assertJsonPath('data.reversed_by.id', $this->user->id);

    expect($payment->fresh()->status)->toBe('reversed')
        ->and($payment->fresh()->reversed_at)->not->toBeNull()
        ->and((float) $this->session->fresh()->expected_amount)->toBe(1200.0)
        ->and($order->fresh()->status)->toBe('delivered')
        ->and($order->fresh()->paid_amount)->toBe(0.0)
        ->and($order->fresh()->pending_balance)->toBe(1000.0);
    $event = CommercialOperationEvent::sole();
    expect($event->event_type)->toBe('payment_reversed')
        ->and($event->reason_code)->toBe('payment_error')
        ->and($event->metadata['payment_id'])->toBe($payment->id)
        ->and($event->metadata['origin'])->toBe('manual_collection');
});

test('exposes eligible order deposits for reversal', function () {
    $payment = reversalPayment($this, reversalOrder($this), [
        'payment_details' => ['origin' => 'order_deposit'],
    ]);

    $this->withToken($this->token)->getJson('/api/v1/store/received-payments')
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $payment->id)
        ->assertJsonPath('data.items.0.reversal.allowed', true)
        ->assertJsonPath('data.items.0.reversal.reason', null);
});

test('exposes semantic reversal block reasons', function (string $scenario, string $reason) {
    $order = reversalOrder($this);
    $attributes = [];

    if ($scenario === 'pos_sale') {
        $order->update(['type' => 'sale']);
        $attributes['payment_details'] = ['origin' => 'pos_sale'];
    }

    if ($scenario === 'already_reversed') {
        $attributes['status'] = 'reversed';
    }

    if ($scenario === 'cash_session_closed') {
        $this->session->update(['status' => 'closed']);
    }

    if ($scenario === 'cash_session_not_operational') {
        $this->session->update(['status' => 'pending_reconciliation']);
    }

    if ($scenario === 'operation_cancelled') {
        $order->update(['status' => 'cancelled']);
    }

    if ($scenario === 'operation_closed') {
        $order->update(['status' => 'closed']);
    }

    if ($scenario === 'route_collection_linked') {
        $attributes['payment_details'] = ['origin' => 'manual_collection', 'route_stop_collection_id' => 'legacy-collection'];
    }

    $payment = reversalPayment($this, $order, $attributes);

    $this->withToken($this->token)->getJson('/api/v1/store/received-payments')
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $payment->id)
        ->assertJsonPath('data.items.0.reversal.allowed', false)
        ->assertJsonPath('data.items.0.reversal.reason', $reason);
})->with([
    'pos sale' => ['pos_sale', 'pos_sale'],
    'already reversed' => ['already_reversed', 'already_reversed'],
    'closed cash session' => ['cash_session_closed', 'cash_session_closed'],
    'cash session pending reconciliation' => ['cash_session_not_operational', 'cash_session_not_operational'],
    'cancelled operation' => ['operation_cancelled', 'operation_cancelled'],
    'closed operation' => ['operation_closed', 'operation_closed'],
    'legacy route link' => ['route_collection_linked', 'route_collection_linked'],
]);

test('exposes direct route collection payments as blocked', function () {
    $order = reversalOrder($this);
    $payment = reversalPayment($this, $order);
    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => Vehicle::factory()->forStore($this->store),
        'driver_id' => $this->user->id,
    ]);
    $stop = RouteStop::factory()->create(['route_id' => $route->id, 'order_id' => $order->id]);
    RouteStopCollection::factory()->create([
        'store_id' => $this->store->id,
        'route_stop_id' => $stop->id,
        'commercial_operation_id' => $order->id,
        'store_payment_method_id' => $this->cashMethod->id,
        'operation_payment_id' => $payment->id,
        'status' => 'verified',
        'declared_by' => $this->user->id,
        'declared_at' => now(),
        'verified_by' => $this->user->id,
        'verified_at' => now(),
    ]);

    $this->withToken($this->token)->getJson('/api/v1/store/received-payments')
        ->assertOk()
        ->assertJsonPath('data.items.0.reversal.allowed', false)
        ->assertJsonPath('data.items.0.reversal.reason', 'route_collection');
});

test('reverses an order deposit without changing expected cash for transfer', function () {
    $order = reversalOrder($this, ['status' => 'partially_delivered']);
    $payment = reversalPayment($this, $order, [
        'store_payment_method_id' => $this->transferMethod->id,
        'amount' => 250,
        'reference' => 'TRX-001',
        'payment_details' => ['origin' => 'order_deposit'],
    ]);

    reversePayment($this, $payment, ['reason_code' => 'wrong_payment_method'])->assertOk();

    expect($payment->fresh()->status)->toBe('reversed')
        ->and((float) $this->session->fresh()->expected_amount)->toBe(1500.0)
        ->and($order->fresh()->status)->toBe('partially_delivered')
        ->and($order->fresh()->pending_balance)->toBe(1000.0);
});

test('rejects a second reversal without a second cash adjustment or event', function () {
    $payment = reversalPayment($this, reversalOrder($this));

    reversePayment($this, $payment)->assertOk();
    reversePayment($this, $payment)->assertUnprocessable();

    expect((float) $this->session->fresh()->expected_amount)->toBe(1200.0)
        ->and(CommercialOperationEvent::where('event_type', 'payment_reversed')->count())->toBe(1);
});

test('validates reversal reasons', function () {
    $payment = reversalPayment($this, reversalOrder($this));

    $this->withToken($this->token)
        ->postJson("/api/v1/store/received-payments/{$payment->id}/reverse", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason_code');
    reversePayment($this, $payment, ['reason_code' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('reason_code');
    reversePayment($this, $payment, ['reason_code' => 'other'])->assertUnprocessable()->assertJsonValidationErrors('reason_note');
    reversePayment($this, $payment, ['reason_code' => 'other', 'reason_note' => 'Detalle requerido'])->assertOk();
});

test('rejects pos sale payments without changing cash or audit history', function () {
    $sale = reversalOrder($this, ['type' => 'sale', 'status' => 'confirmed']);
    $payment = reversalPayment($this, $sale, ['payment_details' => ['origin' => 'pos_sale']]);

    reversePayment($this, $payment)->assertUnprocessable();

    expect($payment->fresh()->status)->toBe('active')
        ->and((float) $this->session->fresh()->expected_amount)->toBe(1500.0)
        ->and(CommercialOperationEvent::count())->toBe(0);
});

test('rejects route payments linked directly or through legacy metadata', function () {
    $order = reversalOrder($this);
    $direct = reversalPayment($this, $order, ['payment_details' => ['origin' => 'route_collection']]);
    $vehicle = Vehicle::factory()->create(['store_id' => $this->store->id]);
    $route = DeliveryRoute::factory()->create(['store_id' => $this->store->id, 'vehicle_id' => $vehicle->id, 'driver_id' => $this->user->id]);
    $stop = RouteStop::factory()->create(['route_id' => $route->id, 'order_id' => $order->id]);
    $collection = RouteStopCollection::factory()->create([
        'store_id' => $this->store->id,
        'route_stop_id' => $stop->id,
        'commercial_operation_id' => $order->id,
        'store_payment_method_id' => $this->cashMethod->id,
        'declared_by' => $this->user->id,
        'declared_at' => now(),
        'status' => 'verified',
        'verified_by' => $this->user->id,
        'verified_at' => now(),
        'operation_payment_id' => $direct->id,
    ]);
    $legacy = reversalPayment($this, $order, [
        'payment_details' => ['origin' => 'manual_collection', 'route_stop_collection_id' => 'legacy-collection'],
    ]);

    reversePayment($this, $direct)->assertUnprocessable();
    reversePayment($this, $legacy)->assertUnprocessable();

    expect($direct->fresh()->status)->toBe('active')
        ->and($legacy->fresh()->status)->toBe('active')
        ->and($collection->fresh()->status)->toBe('verified')
        ->and(CommercialOperationEvent::count())->toBe(0);
});

test('rejects payments from closed and pending reconciliation cash sessions', function (string $status) {
    $payment = reversalPayment($this, reversalOrder($this));
    $this->session->update(['status' => $status]);

    reversePayment($this, $payment)->assertUnprocessable();

    expect($payment->fresh()->status)->toBe('active')
        ->and((float) $this->session->fresh()->expected_amount)->toBe(1500.0)
        ->and(CommercialOperationEvent::count())->toBe(0);
})->with(['closed', 'pending_reconciliation']);

test('rejects reversals for cancelled and closed orders', function (string $status) {
    $payment = reversalPayment($this, reversalOrder($this, ['status' => $status]));

    reversePayment($this, $payment)->assertUnprocessable();

    expect($payment->fresh()->status)->toBe('active')
        ->and((float) $this->session->fresh()->expected_amount)->toBe(1500.0)
        ->and(CommercialOperationEvent::count())->toBe(0);
})->with(['cancelled', 'closed']);

test('keeps store isolation for payment reversal', function () {
    $otherStore = Store::factory()->create();
    $otherOrder = CommercialOperation::factory()->create([
        'store_id' => $otherStore->id,
        'user_id' => User::factory()->create(['store_id' => $otherStore->id])->id,
        'type' => 'order',
        'status' => 'open',
    ]);
    $otherPayment = OperationPayment::factory()->create([
        'operation_id' => $otherOrder->id,
        'store_payment_method_id' => StorePaymentMethod::factory()->create([
            'store_id' => $otherStore->id,
            'payment_method_id' => $this->cashMethod->payment_method_id,
        ])->id,
        'status' => 'active',
    ]);

    reversePayment($this, $otherPayment)->assertNotFound();

});

test('requires payments reverse permission', function () {
    $payment = reversalPayment($this, reversalOrder($this));
    $withoutPermission = User::factory()->create(['store_id' => $this->store->id]);
    $withoutPermission->assignRole('STORE_ADMIN');
    $withoutPermissionToken = $withoutPermission->createToken('without-reverse')->plainTextToken;

    reversePayment($this, $payment, [], $withoutPermissionToken)->assertForbidden();
});

test('keeps reversed payments visible in received payments', function () {
    $payment = reversalPayment($this, reversalOrder($this));
    reversePayment($this, $payment)->assertOk();

    $this->withToken($this->token)->getJson('/api/v1/store/received-payments?status=reversed')
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $payment->id)
        ->assertJsonPath('data.items.0.status', 'reversed')
        ->assertJsonPath('data.items.0.reversed_by.id', $this->user->id);
});

test('P-000018 reverses a manual payment, preserves declared collection, and allows verification', function () {
    $product = Product::factory()->create(['store_id' => $this->store->id, 'stock' => 10]);
    $order = reversalOrder($this, ['status' => 'delivered']);
    OperationItem::factory()->create([
        'operation_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 10,
        'price' => 100,
        'subtotal' => 1000,
    ]);
    $vehicle = Vehicle::factory()->create(['store_id' => $this->store->id]);
    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $this->user->id,
        'status' => 'awaiting_reconciliation',
    ]);
    $stop = RouteStop::factory()->create(['route_id' => $route->id, 'order_id' => $order->id, 'status' => 'completed']);
    RouteStopItem::factory()->create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
        'quantity_planned' => 10,
        'quantity_loaded' => 10,
        'quantity_delivered' => 10,
    ]);
    $collection = RouteStopCollection::factory()->create([
        'store_id' => $this->store->id,
        'route_stop_id' => $stop->id,
        'commercial_operation_id' => $order->id,
        'store_payment_method_id' => $this->cashMethod->id,
        'declared_by' => $this->user->id,
        'declared_at' => now(),
        'status' => 'declared',
        'amount' => 100,
    ]);
    $payment = reversalPayment($this, $order, ['amount' => 1000]);

    $this->withToken($this->token)
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/verify")
        ->assertUnprocessable();

    reversePayment($this, $payment)->assertOk();

    expect($payment->fresh()->status)->toBe('reversed')
        ->and((float) $this->session->fresh()->expected_amount)->toBe(500.0)
        ->and($order->fresh()->pending_balance)->toBe(1000.0)
        ->and($collection->fresh()->status)->toBe('declared')
        ->and(CommercialOperationEvent::where('event_type', 'payment_reversed')->count())->toBe(1);

    $this->withToken($this->token)
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/verify")
        ->assertOk()
        ->assertJsonPath('data.status', 'verified');
});
