<?php

use App\Models\CashSession;
use App\Models\CommercialOperation;
use App\Models\CommercialOperationEvent;
use App\Models\Feature;
use App\Models\InventoryMovement;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use App\Models\User;
use App\Services\CashSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    foreach (['sales_history.view', 'sales_history.print', 'sales.cancel'] as $permission) {
        Permission::create(['name' => $permission, 'guard_name' => 'web']);
    }

    $this->store = Store::factory()->create();
    $plan = Plan::factory()->create();
    $this->store->update(['plan_id' => $plan->id]);
    $plan->features()->attach(Feature::firstOrCreate(['code' => 'pos', 'name' => 'Punto de Venta']));
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo('sales.cancel');
});

function saleForCancellation(Store $store, User $user, int $quantity = 2): array
{
    $product = Product::factory()->forStore($store)->create(['stock' => 8, 'stock_reserved' => 1]);
    $sale = CommercialOperation::factory()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'type' => 'sale',
        'status' => 'confirmed',
        'operation_number' => 'V-000100',
        'subtotal' => 100,
        'total' => 100,
    ]);
    OperationItem::create([
        'operation_id' => $sale->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'price' => 50,
        'subtotal' => 100,
        'tax_amount' => 0,
        'discount_amount' => 0,
    ]);

    return [$sale, $product];
}

function openCashSessionForSale(User $user, array $attributes = []): CashSession
{
    return CashSession::create(array_merge([
        'store_id' => $user->store_id,
        'user_id' => $user->id,
        'status' => 'open',
        'opening_amount' => 1000,
        'expected_amount' => 1000,
        'opened_at' => now(),
    ], $attributes));
}

function paymentMethodForSale(Store $store, string $code): StorePaymentMethod
{
    $method = PaymentMethod::factory()->create(['name' => ucfirst($code), 'code' => $code]);

    return StorePaymentMethod::factory()
        ->forStore($store)
        ->forPaymentMethod($method)
        ->create();
}

function paymentForSale(
    CommercialOperation $sale,
    StorePaymentMethod $method,
    CashSession $session,
    float $amount
): OperationPayment {
    return OperationPayment::create([
        'operation_id' => $sale->id,
        'store_payment_method_id' => $method->id,
        'cash_session_id' => $session->id,
        'registered_by' => $session->user_id,
        'amount' => $amount,
        'status' => 'active',
    ]);
}

test('cancels a confirmed sale, restores stock, reverses payments and adjusts cash expected amount', function () {
    [$sale, $product] = saleForCancellation($this->store, $this->user);
    $session = openCashSessionForSale($this->user, ['expected_amount' => 1100]);
    $cash = paymentMethodForSale($this->store, 'cash');
    $payment = paymentForSale($sale, $cash, $session, 100);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/sales/{$sale->id}/cancel", ['reason_code' => 'pricing_error'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($product->fresh()->stock)->toBe('10.0000')
        ->and($product->fresh()->stock_reserved)->toBe('1.0000')
        ->and((float) $session->fresh()->expected_amount)->toBe(1000.0)
        ->and($payment->fresh()->status)->toBe('reversed')
        ->and($payment->fresh()->reversed_at)->not->toBeNull()
        ->and($payment->fresh()->reversed_by)->toBe($this->user->id)
        ->and(InventoryMovement::count())->toBe(0);

    $event = CommercialOperationEvent::sole();
    expect($event->event_type)->toBe('sale_cancelled')
        ->and($event->previous_status)->toBe('confirmed')
        ->and($event->new_status)->toBe('cancelled')
        ->and($event->reason_code)->toBe('pricing_error')
        ->and($event->previous_date)->toBeNull()
        ->and($event->metadata['payment_ids_reversed'])->toBe([$payment->id])
        ->and($event->metadata['cash_session_ids'])->toBe([$session->id])
        ->and($event->metadata['restored_stock'])->toBe([['product_id' => $product->id, 'quantity' => '2.0000']]);
});

test('reverses every payment while changing expected amount only for cash and excluding reversed payments from reconciliation', function () {
    [$sale] = saleForCancellation($this->store, $this->user);
    $cashSession = openCashSessionForSale($this->user, ['expected_amount' => 1050]);
    $transferSession = openCashSessionForSale($this->user, ['expected_amount' => 700]);
    $cashMethod = paymentMethodForSale($this->store, 'cash');
    $cashPayment = paymentForSale($sale, $cashMethod, $cashSession, 50);
    $transferPayment = paymentForSale($sale, paymentMethodForSale($this->store, 'transfer'), $transferSession, 50);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/sales/{$sale->id}/cancel", ['reason_code' => 'payment_failed'])
        ->assertOk();

    expect((float) $cashSession->fresh()->expected_amount)->toBe(1000.0)
        ->and((float) $transferSession->fresh()->expected_amount)->toBe(700.0)
        ->and($cashPayment->fresh()->status)->toBe('reversed')
        ->and($transferPayment->fresh()->status)->toBe('reversed');

    $summary = app(CashSessionService::class)->reconciliationSummary($cashSession->fresh());
    expect($summary['payment_count'])->toBe(0)
        ->and($summary['total_collected'])->toBe(0.0)
        ->and($summary['operation_count'])->toBe(0);

    $payments = app(CashSessionService::class)->reconciliationPayments(
        $cashSession->fresh(),
        $cashMethod,
        10
    );
    expect($payments->total())->toBe(0);
});

test('allows cancelling a confirmed sale without payments', function () {
    [$sale, $product] = saleForCancellation($this->store, $this->user);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/sales/{$sale->id}/cancel", ['reason_code' => 'customer_cancelled'])
        ->assertOk();

    expect($sale->fresh()->status)->toBe('cancelled')
        ->and($product->fresh()->stock)->toBe('10.0000');
});

test('rolls back stock and payment changes when cash expected amount is inconsistent', function () {
    [$sale, $product] = saleForCancellation($this->store, $this->user);
    $session = openCashSessionForSale($this->user, ['expected_amount' => 25]);
    $payment = paymentForSale($sale, paymentMethodForSale($this->store, 'cash'), $session, 50);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/sales/{$sale->id}/cancel", ['reason_code' => 'payment_failed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cash');

    expect($sale->fresh()->status)->toBe('confirmed')
        ->and($product->fresh()->stock)->toBe('8.0000')
        ->and((float) $session->fresh()->expected_amount)->toBe(25.0)
        ->and($payment->fresh()->status)->toBe('active')
        ->and(CommercialOperationEvent::count())->toBe(0);
});

test('rejects cancellation when a linked cash session is pending or closed and rolls back all effects', function (string $status) {
    [$sale, $product] = saleForCancellation($this->store, $this->user);
    $openSession = openCashSessionForSale($this->user, ['expected_amount' => 1050]);
    $blockedSession = openCashSessionForSale($this->user, [
        'status' => $status,
        'expected_amount' => 500,
        'submitted_at' => $status === 'pending_reconciliation' ? now() : null,
        'closed_at' => $status === 'closed' ? now() : null,
    ]);
    $activePayment = paymentForSale($sale, paymentMethodForSale($this->store, 'cash'), $openSession, 50);
    $blockedPayment = paymentForSale($sale, paymentMethodForSale($this->store, 'transfer'), $blockedSession, 50);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/sales/{$sale->id}/cancel", ['reason_code' => 'payment_failed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cash');

    expect($sale->fresh()->status)->toBe('confirmed')
        ->and($product->fresh()->stock)->toBe('8.0000')
        ->and((float) $openSession->fresh()->expected_amount)->toBe(1050.0)
        ->and($activePayment->fresh()->status)->toBe('active')
        ->and($blockedPayment->fresh()->status)->toBe('active')
        ->and(CommercialOperationEvent::count())->toBe(0);
})->with(['pending_reconciliation', 'closed']);

test('requires the sales cancel permission, a confirmed sale and a valid store scope', function () {
    [$sale] = saleForCancellation($this->store, $this->user);
    $this->user->revokePermissionTo('sales.cancel');

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/sales/{$sale->id}/cancel", ['reason_code' => 'pricing_error'])
        ->assertForbidden();

    $this->user->givePermissionTo('sales.cancel');
    $sale->update(['status' => 'cancelled']);
    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/sales/{$sale->id}/cancel", ['reason_code' => 'pricing_error'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    $otherStore = Store::factory()->create();
    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    $otherSale = CommercialOperation::factory()->create([
        'store_id' => $otherStore->id,
        'user_id' => $otherUser->id,
        'type' => 'sale',
        'status' => 'confirmed',
    ]);
    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/sales/{$otherSale->id}/cancel", ['reason_code' => 'pricing_error'])
        ->assertNotFound();
});

test('requires a detail for other and rejects a second cancellation', function () {
    [$sale] = saleForCancellation($this->store, $this->user);

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/sales/{$sale->id}/cancel", ['reason_code' => 'other'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason_note');

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/sales/{$sale->id}/cancel", [
            'reason_code' => 'other',
            'reason_note' => 'Error en la carga',
        ])
        ->assertOk();

    $this->actingAs($this->user, 'sanctum')
        ->putJson("/api/v1/store/sales/{$sale->id}/cancel", ['reason_code' => 'pricing_error'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});
