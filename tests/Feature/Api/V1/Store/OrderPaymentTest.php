<?php

use App\Models\CashSession;
use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\RouteStopCollection;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use App\Models\User;
use App\Services\CashBusinessDateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Permission::create(['name' => 'orders.collect', 'guard_name' => 'web']);
    $this->store = Store::factory()->create();
    $plan = Plan::factory()->create();
    $this->store->update(['plan_id' => $plan->id]);
    $feature = Feature::firstOrCreate(['code' => 'pos'], ['name' => 'Punto de Venta']);
    $plan->features()->attach($feature, ['limit_value' => null]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo('orders.collect');
    $this->token = $this->user->createToken('test')->plainTextToken;
    $customer = Customer::factory()->create(['store_id' => $this->store->id]);
    $this->order = CommercialOperation::factory()->create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'customer_id' => $customer->id,
        'type' => 'order',
        'status' => 'delivered',
        'total' => 15600,
    ]);
    $this->cashMethod = PaymentMethod::factory()->create(['code' => 'cash', 'is_active' => true]);
    $this->cashStoreMethod = StorePaymentMethod::factory()->create([
        'store_id' => $this->store->id,
        'payment_method_id' => $this->cashMethod->id,
        'is_enabled' => true,
    ]);
    $this->transferMethod = PaymentMethod::factory()->create(['code' => 'transfer', 'is_active' => true]);
    $this->transferStoreMethod = StorePaymentMethod::factory()->create([
        'store_id' => $this->store->id,
        'payment_method_id' => $this->transferMethod->id,
        'is_enabled' => true,
        'requires_reference' => true,
    ]);
    $this->session = CashSession::create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'status' => 'open',
        'business_date' => app(CashBusinessDateService::class)->currentForStore($this->store),
        'opening_amount' => 20000,
        'expected_amount' => 20000,
        'opened_at' => now(),
    ]);
});

function postOrderPayment(string $orderId, array $payload, ?string $token = null)
{
    return test()->withToken($token ?? test()->token)
        ->postJson("/api/v1/store/orders/{$orderId}/payments", $payload);
}

test('registers partial cash payment in current session without changing delivery status', function () {
    $response = postOrderPayment($this->order->id, [
        'store_payment_method_id' => $this->cashStoreMethod->id,
        'amount' => 10000,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'delivered')
        ->assertJsonPath('data.paid_amount', 10000)
        ->assertJsonPath('data.pending_amount', 5600)
        ->assertJsonPath('data.payments.0.cash_session.id', $this->session->id)
        ->assertJsonPath('data.payments.0.registered_by.id', $this->user->id)
        ->assertJsonPath('data.payments.0.origin', 'manual_collection');
    expect((float) $this->session->fresh()->expected_amount)->toBe(30000.0);
});

test('non cash payment is linked to session without changing expected cash', function () {
    postOrderPayment($this->order->id, [
        'store_payment_method_id' => $this->transferStoreMethod->id,
        'amount' => 5600,
        'reference' => '123456',
    ])->assertCreated();

    expect((float) $this->session->fresh()->expected_amount)->toBe(20000.0);
    $this->assertDatabaseHas('operation_payments', [
        'operation_id' => $this->order->id,
        'cash_session_id' => $this->session->id,
        'registered_by' => $this->user->id,
        'reference' => '123456',
    ]);
});

test('second payment completes debt and history contains payment events', function () {
    postOrderPayment($this->order->id, ['store_payment_method_id' => $this->cashStoreMethod->id, 'amount' => 10000]);
    $response = postOrderPayment($this->order->id, [
        'store_payment_method_id' => $this->transferStoreMethod->id,
        'amount' => 5600,
        'reference' => '123456',
    ]);

    $response->assertCreated()->assertJsonPath('data.pending_amount', 0);
    expect(collect($response->json('data.history'))->where('type', 'payment_registered'))->toHaveCount(2);
});

test('rejects overpayment atomically', function () {
    postOrderPayment($this->order->id, [
        'store_payment_method_id' => $this->cashStoreMethod->id,
        'amount' => 16000,
    ])->assertUnprocessable();
    $this->assertDatabaseCount('operation_payments', 0);
    expect((float) $this->session->fresh()->expected_amount)->toBe(20000.0);
    $this->assertDatabaseCount('commercial_operation_events', 0);
});

test('requires current user open cash session', function () {
    $this->session->update(['status' => 'closed', 'closed_at' => now()]);
    postOrderPayment($this->order->id, [
        'store_payment_method_id' => $this->cashStoreMethod->id,
        'amount' => 100,
    ])->assertUnprocessable()->assertJsonValidationErrors('cash_session');
});

test('does not use a stale or pending cash session for payments', function (array $sessionState) {
    $this->session->update($sessionState);
    postOrderPayment($this->order->id, [
        'store_payment_method_id' => $this->cashStoreMethod->id,
        'amount' => 100,
    ])->assertUnprocessable()->assertJsonValidationErrors('cash_session');
    $this->assertDatabaseCount('operation_payments', 0);
})->with([
    'stale open' => [['business_date' => now()->subDay()->toDateString()]],
    'pending reconciliation' => [[
        'status' => 'pending_reconciliation',
        'declared_amount' => 20000,
        'submitted_at' => now(),
    ]],
]);

test('uses the current session while a stale open session also exists', function () {
    CashSession::create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'status' => 'open',
        'business_date' => now()->subDay()->toDateString(),
        'opening_amount' => 0,
        'expected_amount' => 0,
        'opened_at' => now()->subDay(),
    ]);

    postOrderPayment($this->order->id, [
        'store_payment_method_id' => $this->cashStoreMethod->id,
        'amount' => 100,
    ])->assertCreated()->assertJsonPath('data.payments.0.cash_session.id', $this->session->id);
});

test('rejects anomalous multiple open sessions', function () {
    CashSession::create([
        'store_id' => $this->store->id, 'user_id' => $this->user->id, 'status' => 'open',
        'business_date' => $this->session->business_date,
        'opening_amount' => 0, 'expected_amount' => 0, 'opened_at' => now(),
    ]);
    postOrderPayment($this->order->id, [
        'store_payment_method_id' => $this->cashStoreMethod->id,
        'amount' => 100,
    ])->assertUnprocessable()->assertJsonValidationErrors('cash_session');
});

test('rejects disabled method and missing required reference', function () {
    $this->cashStoreMethod->update(['is_enabled' => false]);
    postOrderPayment($this->order->id, [
        'store_payment_method_id' => $this->cashStoreMethod->id, 'amount' => 100,
    ])->assertUnprocessable()->assertJsonValidationErrors('store_payment_method_id');
    postOrderPayment($this->order->id, [
        'store_payment_method_id' => $this->transferStoreMethod->id, 'amount' => 100,
    ])->assertUnprocessable()->assertJsonValidationErrors('reference');
});

test('rejects user without collect permission', function () {
    $other = User::factory()->create(['store_id' => $this->store->id]);
    $other->assignRole('STORE_ADMIN');
    $otherToken = $other->createToken('test')->plainTextToken;
    postOrderPayment($this->order->id, [
        'store_payment_method_id' => $this->cashStoreMethod->id, 'amount' => 100,
    ], $otherToken)->assertForbidden();
});

test('does not expose an order from another store', function () {
    $otherStore = Store::factory()->create();
    $foreignOrder = CommercialOperation::factory()->create([
        'store_id' => $otherStore->id, 'user_id' => User::factory()->create(['store_id' => $otherStore->id])->id,
        'type' => 'order', 'total' => 100,
    ]);
    postOrderPayment($foreignOrder->id, [
        'store_payment_method_id' => $this->cashStoreMethod->id, 'amount' => 100,
    ])->assertNotFound();
});

test('allows partially delivered order and preserves rejected collection', function () {
    $this->order->update(['status' => 'partially_delivered']);
    $collection = RouteStopCollection::factory()->create([
        'store_id' => $this->store->id,
        'commercial_operation_id' => $this->order->id,
        'store_payment_method_id' => $this->cashStoreMethod->id,
        'declared_by' => $this->user->id,
        'declared_at' => now(),
        'status' => 'rejected',
        'operation_payment_id' => null,
    ]);
    postOrderPayment($this->order->id, [
        'store_payment_method_id' => $this->cashStoreMethod->id, 'amount' => 100,
    ])->assertCreated()->assertJsonPath('data.status', 'partially_delivered');
    expect($collection->fresh()->status)->toBe('rejected')
        ->and($collection->fresh()->operation_payment_id)->toBeNull();
});
