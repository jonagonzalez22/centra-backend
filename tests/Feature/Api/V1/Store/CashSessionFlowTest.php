<?php

use App\Http\Resources\CashSessionResource;
use App\Models\CashSession;
use App\Models\CommercialOperation;
use App\Models\Feature;
use App\Models\OperationPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use App\Models\User;
use App\Services\CashBusinessDateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    foreach (['cash.view', 'cash.open', 'cash.submit', 'cash.close'] as $permission) {
        Permission::create(['name' => $permission, 'guard_name' => 'web']);
    }
    $this->store = Store::factory()->create(['timezone' => 'America/Argentina/Mendoza']);
    $plan = Plan::factory()->create();
    $this->store->update(['plan_id' => $plan->id]);
    $feature = Feature::firstOrCreate(['code' => 'cash'], ['name' => 'Caja']);
    $plan->features()->attach($feature, ['limit_value' => null]);
    $this->cashier = User::factory()->create(['store_id' => $this->store->id]);
    $this->cashier->assignRole('STORE_ADMIN');
    $this->cashier->givePermissionTo(['cash.view', 'cash.open', 'cash.submit']);
    $this->supervisor = User::factory()->create(['store_id' => $this->store->id]);
    $this->supervisor->assignRole('STORE_ADMIN');
    $this->supervisor->givePermissionTo(['cash.view', 'cash.close']);
    $this->cashierToken = $this->cashier->createToken('cashier')->plainTextToken;
    $this->supervisorToken = $this->supervisor->createToken('supervisor')->plainTextToken;
});

afterEach(fn () => Carbon::setTestNow());

function cashSessionFor(User $user, array $attributes = []): CashSession
{
    return CashSession::create(array_merge([
        'store_id' => $user->store_id,
        'user_id' => $user->id,
        'status' => 'open',
        'business_date' => app(CashBusinessDateService::class)->currentForStore($user->store),
        'opening_amount' => 20000,
        'expected_amount' => 20000,
        'opened_at' => now(),
    ], $attributes));
}

test('opens cash with business date expected amount and persisted notes', function () {
    Carbon::setTestNow('2026-09-10 10:00:00');

    $this->withToken($this->cashierToken)->postJson('/api/v1/store/cash/open', [
        'opening_amount' => 20000,
        'notes' => 'Turno mañana',
    ])->assertCreated()
        ->assertJsonPath('data.business_date', '2026-09-10')
        ->assertJsonPath('data.opening_amount', 20000)
        ->assertJsonMissingPath('data.expected_amount');

    $session = CashSession::sole();
    expect((float) $session->expected_amount)->toBe(20000.0)
        ->and($session->notes)->toBe('Turno mañana');
});

test('assigns the previous business date before the 04 cutoff', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 01:00:00', 'America/Argentina/Mendoza'));
    $this->withToken($this->cashierToken)->postJson('/api/v1/store/cash/open', ['opening_amount' => 0])
        ->assertCreated()->assertJsonPath('data.business_date', '2026-09-09');
});

test('assigns the current business date after the 04 cutoff', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-10 05:00:00', 'America/Argentina/Mendoza'));
    $this->withToken($this->cashierToken)->postJson('/api/v1/store/cash/open', ['opening_amount' => 0])
        ->assertCreated()->assertJsonPath('data.business_date', '2026-09-10');
});

test('allows opening with a stale open session but rejects a duplicate current session', function () {
    Carbon::setTestNow('2026-09-10 10:00:00');
    cashSessionFor($this->cashier, ['business_date' => '2026-09-09']);

    $this->withToken($this->cashierToken)->postJson('/api/v1/store/cash/open', ['opening_amount' => 100])
        ->assertCreated();
    $this->withToken($this->cashierToken)->postJson('/api/v1/store/cash/open', ['opening_amount' => 100])
        ->assertUnprocessable()->assertJsonValidationErrors('cash');

    expect(CashSession::where('status', 'open')->count())->toBe(2);
});

test('current ignores stale sessions and never exposes expected amount', function () {
    cashSessionFor($this->cashier, ['business_date' => now()->subDay()->toDateString()]);
    $current = cashSessionFor($this->cashier);

    $this->withToken($this->cashierToken)->getJson('/api/v1/store/cash/current')
        ->assertOk()
        ->assertJsonPath('data.id', $current->id)
        ->assertJsonMissingPath('data.expected_amount')
        ->assertJsonMissingPath('data.cash_income');
});

test('current rejects multiple operational sessions instead of choosing one', function () {
    cashSessionFor($this->cashier);
    cashSessionFor($this->cashier);

    $this->withToken($this->cashierToken)->getJson('/api/v1/store/cash/current')
        ->assertUnprocessable()->assertJsonPath('data', null);
});

test('overview separates current stale and pending without exposing expected', function () {
    $current = cashSessionFor($this->cashier);
    $stale = cashSessionFor($this->cashier, ['business_date' => now()->subDay()->toDateString()]);
    $pending = cashSessionFor($this->cashier, [
        'status' => 'pending_reconciliation',
        'declared_amount' => 19000,
        'submitted_at' => now(),
    ]);

    $response = $this->withToken($this->cashierToken)->getJson('/api/v1/store/cash/overview')->assertOk();
    $response->assertJsonPath('data.current.id', $current->id)
        ->assertJsonPath('data.stale_open.0.id', $stale->id)
        ->assertJsonPath('data.pending_reconciliation.0.id', $pending->id)
        ->assertJsonMissingPath('data.current.expected_amount')
        ->assertJsonMissingPath('data.stale_open.0.expected_amount')
        ->assertJsonMissingPath('data.pending_reconciliation.0.expected_amount');
});

test('owner submits current or stale open cash without financial disclosure', function (bool $stale) {
    $session = cashSessionFor($this->cashier, $stale ? ['business_date' => now()->subDay()->toDateString()] : []);

    $this->withToken($this->cashierToken)->postJson("/api/v1/store/cash/{$session->id}/submit", [
        'declared_amount' => 19500,
        'declaration_notes' => 'Sobre cerrado',
    ])->assertOk()
        ->assertJsonPath('data.status', 'pending_reconciliation')
        ->assertJsonPath('data.declared_amount', 19500)
        ->assertJsonMissingPath('data.expected_amount');

    $session->refresh();
    expect($session->submitted_at)->not->toBeNull()
        ->and($session->declaration_notes)->toBe('Sobre cerrado')
        ->and($session->real_amount)->toBeNull();
})->with([false, true]);

test('rejects submit by another user and a second submit', function () {
    $session = cashSessionFor($this->cashier);
    $this->withToken($this->cashierToken)->postJson("/api/v1/store/cash/{$session->id}/submit", [
        'declared_amount' => 20000,
    ])->assertOk();
    $this->withToken($this->cashierToken)->postJson("/api/v1/store/cash/{$session->id}/submit", [
        'declared_amount' => 20000,
    ])->assertUnprocessable();

    $otherSession = cashSessionFor($this->cashier, ['business_date' => now()->subDay()->toDateString()]);
    $this->supervisor->givePermissionTo('cash.submit');
    $this->app['auth']->forgetGuards();
    $this->withToken($this->supervisorToken)->postJson("/api/v1/store/cash/{$otherSession->id}/submit", [
        'declared_amount' => 20000,
    ])->assertForbidden();
});

test('pending list is permission and store scoped', function () {
    $own = cashSessionFor($this->cashier, ['status' => 'pending_reconciliation', 'declared_amount' => 100, 'submitted_at' => now()]);
    $otherStore = Store::factory()->create();
    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    cashSessionFor($otherUser, ['status' => 'pending_reconciliation', 'declared_amount' => 50, 'submitted_at' => now()]);

    $this->withToken($this->cashierToken)->getJson('/api/v1/store/cash/pending-reconciliation')->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withToken($this->supervisorToken)->getJson('/api/v1/store/cash/pending-reconciliation')
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $own->id);
});

test('reconciliation detail aggregates cash and non cash payments', function () {
    $session = cashSessionFor($this->cashier, [
        'status' => 'pending_reconciliation', 'expected_amount' => 21200,
        'declared_amount' => 21000, 'submitted_at' => now(),
    ]);
    $cash = PaymentMethod::factory()->create(['name' => 'Efectivo', 'code' => 'cash']);
    $market = PaymentMethod::factory()->create(['name' => 'Mercado Pago', 'code' => 'mercado_pago']);
    $cashStore = StorePaymentMethod::factory()->create(['store_id' => $this->store->id, 'payment_method_id' => $cash->id]);
    $marketStore = StorePaymentMethod::factory()->create(['store_id' => $this->store->id, 'payment_method_id' => $market->id]);
    $operation = CommercialOperation::factory()->create(['store_id' => $this->store->id, 'user_id' => $this->cashier->id]);
    OperationPayment::factory()->create(['operation_id' => $operation->id, 'store_payment_method_id' => $cashStore->id, 'cash_session_id' => $session->id, 'amount' => 1200]);
    OperationPayment::factory()->create(['operation_id' => $operation->id, 'store_payment_method_id' => $marketStore->id, 'cash_session_id' => $session->id, 'amount' => 1000]);

    $this->withToken($this->supervisorToken)->getJson("/api/v1/store/cash/{$session->id}/reconciliation")
        ->assertOk()
        ->assertJsonPath('data.cash_income', 1200)
        ->assertJsonPath('data.total_collected', 2200)
        ->assertJsonPath('data.payment_count', 2)
        ->assertJsonPath('data.operation_count', 1)
        ->assertJsonCount(2, 'data.totals_by_payment_method');
});

test('reconciliation detail rejects open own and cross tenant sessions', function () {
    $open = cashSessionFor($this->cashier);
    $ownPending = cashSessionFor($this->supervisor, ['status' => 'pending_reconciliation', 'declared_amount' => 0, 'submitted_at' => now()]);
    $this->withToken($this->supervisorToken)->getJson("/api/v1/store/cash/{$open->id}/reconciliation")->assertNotFound();
    $this->withToken($this->supervisorToken)->getJson("/api/v1/store/cash/{$ownPending->id}/reconciliation")->assertForbidden();

    $otherStore = Store::factory()->create();
    $other = User::factory()->create(['store_id' => $otherStore->id]);
    $foreign = cashSessionFor($other, ['status' => 'pending_reconciliation', 'declared_amount' => 0, 'submitted_at' => now()]);
    $this->withToken($this->supervisorToken)->getJson("/api/v1/store/cash/{$foreign->id}/reconciliation")->assertNotFound();
});

test('supervisor lists paginated non cash payments with traceability and strict filtering', function () {
    $session = cashSessionFor($this->cashier, [
        'status' => 'pending_reconciliation', 'declared_amount' => 100, 'submitted_at' => now(),
    ]);
    $otherSession = cashSessionFor($this->cashier, [
        'status' => 'pending_reconciliation', 'declared_amount' => 100, 'submitted_at' => now(),
    ]);
    $global = PaymentMethod::factory()->create(['name' => 'Transferencia', 'code' => 'bank_transfer']);
    $method = StorePaymentMethod::factory()->create([
        'store_id' => $this->store->id, 'payment_method_id' => $global->id,
    ]);
    $operation = CommercialOperation::factory()->create([
        'store_id' => $this->store->id, 'user_id' => $this->cashier->id,
        'operation_number' => 'P-000023', 'type' => 'order',
    ]);
    $payment = OperationPayment::factory()->create([
        'operation_id' => $operation->id,
        'store_payment_method_id' => $method->id,
        'cash_session_id' => $session->id,
        'registered_by' => $this->cashier->id,
        'amount' => 8800,
        'reference' => '12345678',
        'payment_details' => ['origin' => 'manual_collection'],
    ]);
    OperationPayment::factory()->create([
        'operation_id' => $operation->id,
        'store_payment_method_id' => $method->id,
        'cash_session_id' => $otherSession->id,
    ]);

    $this->withToken($this->supervisorToken)
        ->getJson("/api/v1/store/cash/{$session->id}/reconciliation/payment-methods/{$method->id}/payments?per_page=1")
        ->assertOk()
        ->assertJsonPath('data.payment_method.name', 'Transferencia')
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.per_page', 1)
        ->assertJsonPath('data.items.0.id', $payment->id)
        ->assertJsonPath('data.items.0.operation.operation_number', 'P-000023')
        ->assertJsonPath('data.items.0.reference', '12345678')
        ->assertJsonPath('data.items.0.origin', 'manual_collection')
        ->assertJsonPath('data.items.0.registered_by.name', $this->cashier->name);
});

test('reconciliation payments reject cash own invalid status tenant method and missing permission', function () {
    $pending = cashSessionFor($this->cashier, [
        'status' => 'pending_reconciliation', 'declared_amount' => 100, 'submitted_at' => now(),
    ]);
    $cash = PaymentMethod::factory()->create(['name' => 'Efectivo', 'code' => 'cash']);
    $cashMethod = StorePaymentMethod::factory()->create([
        'store_id' => $this->store->id, 'payment_method_id' => $cash->id,
    ]);
    $operation = CommercialOperation::factory()->create(['store_id' => $this->store->id]);
    OperationPayment::factory()->create([
        'operation_id' => $operation->id, 'store_payment_method_id' => $cashMethod->id,
        'cash_session_id' => $pending->id,
    ]);
    $path = fn (CashSession $session, StorePaymentMethod $method) => "/api/v1/store/cash/{$session->id}/reconciliation/payment-methods/{$method->id}/payments";

    $this->withToken($this->supervisorToken)->getJson($path($pending, $cashMethod))->assertNotFound();

    $transfer = PaymentMethod::factory()->create(['name' => 'Transferencia', 'code' => 'bank_transfer']);
    $transferMethod = StorePaymentMethod::factory()->create([
        'store_id' => $this->store->id, 'payment_method_id' => $transfer->id,
    ]);
    OperationPayment::factory()->create([
        'operation_id' => $operation->id, 'store_payment_method_id' => $transferMethod->id,
        'cash_session_id' => $pending->id,
    ]);
    $this->app['auth']->forgetGuards();
    $this->withToken($this->cashierToken)->getJson($path($pending, $transferMethod))->assertForbidden();

    $this->cashier->givePermissionTo('cash.close');
    $this->app['auth']->forgetGuards();
    $this->withToken($this->cashierToken)->getJson($path($pending, $transferMethod))->assertForbidden();

    $open = cashSessionFor($this->cashier);
    $closed = cashSessionFor($this->cashier, ['status' => 'closed', 'closed_at' => now()]);
    $this->app['auth']->forgetGuards();
    $this->withToken($this->supervisorToken)->getJson($path($open, $cashMethod))->assertNotFound();
    $this->withToken($this->supervisorToken)->getJson($path($closed, $cashMethod))->assertNotFound();

    $otherStore = Store::factory()->create();
    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    $foreignSession = cashSessionFor($otherUser, [
        'status' => 'pending_reconciliation', 'declared_amount' => 0, 'submitted_at' => now(),
    ]);
    $foreignGlobal = PaymentMethod::factory()->create(['name' => 'Tarjeta', 'code' => 'card']);
    $foreignMethod = StorePaymentMethod::factory()->create([
        'store_id' => $otherStore->id, 'payment_method_id' => $foreignGlobal->id,
    ]);
    $this->withToken($this->supervisorToken)->getJson($path($foreignSession, $foreignMethod))->assertNotFound();
    $this->withToken($this->supervisorToken)->getJson($path($pending, $foreignMethod))->assertNotFound();
});

test('closes pending cash by another user and derives difference', function () {
    $session = cashSessionFor($this->cashier, [
        'status' => 'pending_reconciliation', 'expected_amount' => 50000,
        'declared_amount' => 49500, 'submitted_at' => now(),
    ]);

    $this->withToken($this->supervisorToken)->postJson("/api/v1/store/cash/{$session->id}/close", [
        'real_amount' => 49500,
        'reconciliation_notes' => 'Faltante controlado',
    ])->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.real_amount', 49500)
        ->assertJsonPath('data.difference', -500)
        ->assertJsonPath('data.closed_by.id', $this->supervisor->id);
});

test('requires reconciliation notes only when there is a difference', function () {
    $difference = cashSessionFor($this->cashier, ['status' => 'pending_reconciliation', 'expected_amount' => 100, 'declared_amount' => 90, 'submitted_at' => now()]);
    $this->withToken($this->supervisorToken)->postJson("/api/v1/store/cash/{$difference->id}/close", ['real_amount' => 99])
        ->assertUnprocessable()->assertJsonValidationErrors('reconciliation_notes');

    $exact = cashSessionFor($this->cashier, ['status' => 'pending_reconciliation', 'expected_amount' => 100, 'declared_amount' => 100, 'submitted_at' => now()]);
    $this->withToken($this->supervisorToken)->postJson("/api/v1/store/cash/{$exact->id}/close", ['real_amount' => 100])
        ->assertOk()->assertJsonPath('data.difference', 0);
});

test('does not close open own or already closed cash', function () {
    $open = cashSessionFor($this->cashier);
    $this->withToken($this->supervisorToken)->postJson("/api/v1/store/cash/{$open->id}/close", ['real_amount' => 20000])
        ->assertUnprocessable();

    $own = cashSessionFor($this->supervisor, ['status' => 'pending_reconciliation', 'declared_amount' => 0, 'submitted_at' => now()]);
    $this->withToken($this->supervisorToken)->postJson("/api/v1/store/cash/{$own->id}/close", ['real_amount' => 20000])
        ->assertForbidden();
});

test('a second close cannot overwrite the first reconciliation', function () {
    $session = cashSessionFor($this->cashier, [
        'status' => 'pending_reconciliation', 'expected_amount' => 100,
        'declared_amount' => 100, 'submitted_at' => now(),
    ]);
    $otherSupervisor = User::factory()->create(['store_id' => $this->store->id]);
    $otherSupervisor->assignRole('STORE_ADMIN');
    $otherSupervisor->givePermissionTo('cash.close');
    $otherToken = $otherSupervisor->createToken('other-supervisor')->plainTextToken;

    $this->withToken($this->supervisorToken)->postJson("/api/v1/store/cash/{$session->id}/close", ['real_amount' => 100])
        ->assertOk();
    $this->app['auth']->forgetGuards();
    $this->withToken($otherToken)->postJson("/api/v1/store/cash/{$session->id}/close", [
        'real_amount' => 99, 'reconciliation_notes' => 'Intento tardío',
    ])->assertUnprocessable();

    expect((float) $session->fresh()->real_amount)->toBe(100.0)
        ->and($session->fresh()->closed_by)->toBe($this->supervisor->id);
});

test('historical closed cash serializes nullable reconciliation fields', function () {
    $session = cashSessionFor($this->cashier, [
        'status' => 'closed',
        'business_date' => null,
        'real_amount' => 20000,
        'closed_at' => now(),
    ]);

    $data = CashSessionResource::make($session)->resolve();

    expect($data['business_date'])->toBeNull()
        ->and($data['declared_amount'])->toBeNull()
        ->and($data['closed_by'])->toBeNull()
        ->and($data['difference'])->toEqual(0);
});
