<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\CommercialOperation;
use App\Models\CommercialOperationEvent;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\OperationItem;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    Permission::firstOrCreate(['name' => 'orders.edit', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();
    $this->plan = Plan::factory()->create();
    $this->store->plan_id = $this->plan->id;
    $this->store->save();

    $posFeature = Feature::firstOrCreate(['code' => 'pos', 'name' => 'Punto de Venta']);
    $this->plan->features()->syncWithoutDetaching([$posFeature->id => ['limit_value' => null]]);

    $this->category = Category::factory()->create(['store_id' => $this->store->id]);
    $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);

    $this->product = Product::factory()->create([
        'store_id' => $this->store->id,
        'category_id' => $this->category->id,
        'stock' => 10,
        'stock_reserved' => 5,
        'price' => 100.00,
    ]);
});

// ─── Helpers ───────────────────────────────────────────────────────────

function makeAuthUserCancel(Store $store, string $role = 'STORE_ADMIN', array $permissions = []): User
{
    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole($role);

    foreach ($permissions as $perm) {
        $user->givePermissionTo($perm);
    }

    return $user;
}

function makeOrderForCancel(Store $store, array $attributes = []): CommercialOperation
{
    $user = User::factory()->create(['store_id' => $store->id]);

    return CommercialOperation::factory()->create(array_merge([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'customer_id' => Customer::factory()->create(['store_id' => $store->id])->id,
        'type' => 'order',
        'status' => 'open',
        'requested_delivery_date' => now()->addDays(5)->format('Y-m-d'),
    ], $attributes));
}

function cancelOp(User $user, string $operationId, array $data = []): \Illuminate\Testing\TestResponse
{
    $default = [
        'reason_code' => 'customer_cancelled',
    ];

    return test()->actingAs($user, 'sanctum')
        ->putJson("/api/v1/store/operations/{$operationId}/cancel", array_merge($default, $data));
}

// ─── CANCEL-008: Migration columns ─────────────────────────────────────

describe('CANCEL-008: Migration — Events table columns', function () {
    it('adds previous_status, new_status, reason_code, and reason_note columns to events table', function () {
        expect(Schema::hasColumn('commercial_operation_events', 'previous_status'))->toBeTrue();
        expect(Schema::hasColumn('commercial_operation_events', 'new_status'))->toBeTrue();
        expect(Schema::hasColumn('commercial_operation_events', 'reason_code'))->toBeTrue();
        expect(Schema::hasColumn('commercial_operation_events', 'reason_note'))->toBeTrue();
    });

    it('allows existing reschedule events to coexist with new nullable columns', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForCancel($this->store);

        // Create a reschedule event (existing pattern)
        $event = CommercialOperationEvent::create([
            'store_id' => $this->store->id,
            'operation_id' => $order->id,
            'event_type' => 'delivery_date_changed',
            'previous_date' => '2026-07-20',
            'new_date' => '2026-07-25',
            'reason' => 'customer_requested_reschedule',
            'observation' => null,
            'user_id' => $user->id,
        ]);

        // Refresh from DB to see actual stored values
        $event->refresh();

        expect($event->previous_status)->toBeNull();
        expect($event->new_status)->toBeNull();
        expect($event->reason_code)->toBeNull();
        expect($event->reason_note)->toBeNull();
        // Existing columns still work
        expect($event->previous_date->format('Y-m-d'))->toBe('2026-07-20');
        expect($event->new_date->format('Y-m-d'))->toBe('2026-07-25');
        expect($event->reason)->toBe('customer_requested_reschedule');
    });
});

// ─── CANCEL-009: Model updates ─────────────────────────────────────────

describe('CANCEL-009: Model updates', function () {
    it('accepts new cancellation fields through fillable without mass-assignment errors', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForCancel($this->store);

        $event = CommercialOperationEvent::create([
            'store_id' => $this->store->id,
            'operation_id' => $order->id,
            'event_type' => 'order_cancelled',
            'previous_date' => '2026-07-25',
            'new_date' => null,
            'reason' => 'customer_cancelled',
            'observation' => null,
            'user_id' => $user->id,
            'previous_status' => 'open',
            'new_status' => 'cancelled',
            'reason_code' => 'customer_cancelled',
            'reason_note' => null,
        ]);

        expect($event->previous_status)->toBe('open');
        expect($event->new_status)->toBe('cancelled');
        expect($event->reason_code)->toBe('customer_cancelled');
        expect($event->reason_note)->toBeNull();
    });
});

// ─── CANCEL-001 + CANCEL-007: Successful cancel endpoint ───────────────

describe('PUT /api/v1/store/operations/{operation}/cancel', function () {
    it('returns 200 and cancels order with future delivery, releasing stock_reserved', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForCancel($this->store, [
            'requested_delivery_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $product = $this->product;
        $product->update(['stock_reserved' => 5]);

        OperationItem::factory()->create([
            'operation_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'price' => 100.00,
            'subtotal' => 200.00,
        ]);

        $response = cancelOp($user, $order->id, [
            'reason_code' => 'customer_cancelled',
            'reason_note' => 'Ya no lo necesita',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.id', $order->id);

        // Verify stock_reserved released (5 - 2 = 3)
        $product->refresh();
        expect($product->stock_reserved)->toBe(3);

        // Verify event created
        $this->assertDatabaseHas('commercial_operation_events', [
            'operation_id' => $order->id,
            'event_type' => 'order_cancelled',
            'store_id' => $this->store->id,
            'previous_status' => 'open',
            'new_status' => 'cancelled',
            'reason_code' => 'customer_cancelled',
        ]);

        // Verify operation status
        $order->refresh();
        expect($order->status)->toBe('cancelled');
    });

    it('returns 200 and cancels same-day order without modifying stock_reserved', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForCancel($this->store, [
            'requested_delivery_date' => now()->format('Y-m-d'),
        ]);

        $product = $this->product;
        $product->update(['stock_reserved' => 5]);

        OperationItem::factory()->create([
            'operation_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'price' => 100.00,
            'subtotal' => 200.00,
        ]);

        $response = cancelOp($user, $order->id, [
            'reason_code' => 'customer_cancelled',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // Stock reserved must NOT change for same-day delivery
        $product->refresh();
        expect($product->stock_reserved)->toBe(5);
    });

    it('returns 403 when user lacks orders.edit permission', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', []);
        $order = makeOrderForCancel($this->store);

        $response = cancelOp($user, $order->id);

        $response->assertForbidden();
    });

    it('returns 404 when operation belongs to different store', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);

        $otherStore = Store::factory()->create();
        $otherPlan = Plan::factory()->create();
        $otherStore->plan_id = $otherPlan->id;
        $otherStore->save();
        $posFeature = Feature::where('code', 'pos')->first();
        $otherPlan->features()->syncWithoutDetaching([$posFeature->id => ['limit_value' => null]]);

        $otherOrder = makeOrderForCancel($otherStore);

        $response = cancelOp($user, $otherOrder->id);

        $response->assertNotFound();
    });

    it('returns 200 with correct success response shape', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForCancel($this->store);

        $response = cancelOp($user, $order->id, [
            'reason_code' => 'customer_cancelled',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'status',
                'message',
                'data',
                'errors',
            ])
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Operación cancelada exitosamente.')
            ->assertJsonPath('errors', null);
    });
});

// ─── CANCEL-002: Request validation ────────────────────────────────────

describe('CANCEL-002: Request validation', function () {
    it('returns 422 when reason_code is missing', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForCancel($this->store);

        $response = test()->actingAs($user, 'sanctum')
            ->putJson("/api/v1/store/operations/{$order->id}/cancel", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason_code']);
    });

    it('returns 422 when reason_code is invalid', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForCancel($this->store);

        $response = cancelOp($user, $order->id, [
            'reason_code' => 'invalid_reason',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason_code']);
    });

    it('returns 422 when reason_code is other and reason_note is missing', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForCancel($this->store);

        $response = cancelOp($user, $order->id, [
            'reason_code' => 'other',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason_note']);
    });

    it('accepts valid reason_codes: customer_cancelled, payment_failed, out_of_stock, pricing_error, duplicate_order, other', function (string $code) {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForCancel($this->store);

        $data = ['reason_code' => $code];
        if ($code === 'other') {
            $data['reason_note'] = 'Razón detallada aquí';
        }

        $response = cancelOp($user, $order->id, $data);

        $response->assertOk();
    })->with([
        'customer_cancelled',
        'payment_failed',
        'out_of_stock',
        'pricing_error',
        'duplicate_order',
        'other',
    ]);
});

// ─── CANCEL-003: Business rule validation ──────────────────────────────

describe('CANCEL-003: Business rule validation', function () {
    it('returns 422 when operation type is sale', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);

        $sale = CommercialOperation::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => User::factory()->create(['store_id' => $this->store->id])->id,
            'type' => 'sale',
            'status' => 'confirmed',
        ]);

        $response = cancelOp($user, $sale->id);

        $response->assertStatus(422);
    });

    it('returns 422 when operation status is confirmed (not open)', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);

        $order = CommercialOperation::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => User::factory()->create(['store_id' => $this->store->id])->id,
            'customer_id' => $this->customer->id,
            'type' => 'order',
            'status' => 'confirmed',
            'requested_delivery_date' => now()->addDays(5)->format('Y-m-d'),
        ]);

        $response = cancelOp($user, $order->id);

        $response->assertStatus(422);
    });

    it('returns 422 when operation is already cancelled', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);

        $order = CommercialOperation::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => User::factory()->create(['store_id' => $this->store->id])->id,
            'customer_id' => $this->customer->id,
            'type' => 'order',
            'status' => 'cancelled',
            'requested_delivery_date' => now()->addDays(5)->format('Y-m-d'),
        ]);

        $response = cancelOp($user, $order->id);

        $response->assertStatus(422);
    });
});

// ─── CANCEL-004: Event creation details ────────────────────────────────

describe('CANCEL-004: Event creation details', function () {
    it('records cancellation event with correct fields', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForCancel($this->store, [
            'requested_delivery_date' => '2026-07-25',
        ]);

        $response = cancelOp($user, $order->id, [
            'reason_code' => 'customer_cancelled',
        ]);

        $response->assertOk();

        $event = CommercialOperationEvent::where('operation_id', $order->id)->first();

        expect($event)->not->toBeNull()
            ->and($event->event_type)->toBe('order_cancelled')
            ->and($event->previous_status)->toBe('open')
            ->and($event->new_status)->toBe('cancelled')
            ->and($event->reason_code)->toBe('customer_cancelled')
            ->and($event->previous_date->format('Y-m-d'))->toBe('2026-07-25')
            ->and($event->new_date)->toBeNull()
            ->and($event->store_id)->toBe($this->store->id)
            ->and($event->user_id)->toBe($user->id);
    });
});

// ─── CANCEL-005: Stock release — multiple items ────────────────────────

describe('CANCEL-005: Stock release — multiple items', function () {
    it('releases reserved stock proportionally for all items on future delivery cancel', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForCancel($this->store, [
            'requested_delivery_date' => now()->addDays(3)->format('Y-m-d'),
        ]);

        $productA = $this->product;
        $productA->update(['stock_reserved' => 5]);

        $productB = Product::factory()->create([
            'store_id' => $this->store->id,
            'category_id' => $this->category->id,
            'stock' => 20,
            'stock_reserved' => 8,
            'price' => 50.00,
        ]);

        OperationItem::factory()->create([
            'operation_id' => $order->id,
            'product_id' => $productA->id,
            'product_name' => $productA->name,
            'quantity' => 3,
            'price' => 100.00,
            'subtotal' => 300.00,
        ]);

        OperationItem::factory()->create([
            'operation_id' => $order->id,
            'product_id' => $productB->id,
            'product_name' => $productB->name,
            'quantity' => 2,
            'price' => 50.00,
            'subtotal' => 100.00,
        ]);

        $response = cancelOp($user, $order->id, [
            'reason_code' => 'customer_cancelled',
        ]);

        $response->assertOk();

        $productA->refresh();
        $productB->refresh();

        expect($productA->stock_reserved)->toBe(2);  // 5 - 3
        expect($productB->stock_reserved)->toBe(6);  // 8 - 2
    });
});

// ─── CANCEL-006: Concurrent cancel protection ──────────────────────────

describe('CANCEL-006: Transaction safety', function () {
    it('rejects a second cancel on an already cancelled operation', function () {
        $user = makeAuthUserCancel($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForCancel($this->store);

        // First cancel — should succeed
        $first = cancelOp($user, $order->id, [
            'reason_code' => 'customer_cancelled',
        ]);
        $first->assertOk();

        // Second cancel — should be rejected (status already changed)
        $second = cancelOp($user, $order->id, [
            'reason_code' => 'duplicate_order',
        ]);
        $second->assertStatus(422);
    });
});
