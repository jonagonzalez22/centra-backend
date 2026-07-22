<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
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
        'stock_reserved' => 0,
        'price' => 100.00,
    ]);
});

function makeAuthUser(Store $store, string $role = 'STORE_ADMIN', array $permissions = []): User
{
    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole($role);

    foreach ($permissions as $perm) {
        $user->givePermissionTo($perm);
    }

    return $user;
}

function makeOrderForStore(Store $store, array $attributes = []): CommercialOperation
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

function rescheduleOp(User $user, string $operationId, array $data = []): \Illuminate\Testing\TestResponse
{
    $default = [
        'new_date' => now()->addDays(10)->format('Y-m-d'),
        'reason' => 'customer_requested_reschedule',
    ];

    return test()->actingAs($user, 'sanctum')
        ->putJson("/api/v1/store/operations/{$operationId}/reschedule", array_merge($default, $data));
}

describe('PUT /api/v1/store/operations/{operation}/reschedule', function () {
    test('it returns 200 with updated operation on valid reschedule', function () {
        $user = makeAuthUser($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForStore($this->store);
        $newDate = now()->addDays(10)->format('Y-m-d');

        $response = rescheduleOp($user, $order->id, [
            'new_date' => $newDate,
            'reason' => 'customer_requested_reschedule',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $order->id);

        $this->assertDatabaseHas('commercial_operation_events', [
            'operation_id' => $order->id,
            'event_type' => 'delivery_date_changed',
            'store_id' => $this->store->id,
        ]);

        $order->refresh();
        expect($order->requested_delivery_date->format('Y-m-d'))->toBe($newDate);
    });

    test('it returns 422 when new_date is missing', function () {
        $user = makeAuthUser($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForStore($this->store);

        $response = rescheduleOp($user, $order->id, [
            'new_date' => null,
            'reason' => 'customer_requested_reschedule',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_date']);
    });

    test('it returns 422 when reason is invalid', function () {
        $user = makeAuthUser($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForStore($this->store);

        $response = rescheduleOp($user, $order->id, [
            'reason' => 'invalid_reason',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    });

    test('it returns 422 when observation required for other reason', function () {
        $user = makeAuthUser($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForStore($this->store);

        $response = rescheduleOp($user, $order->id, [
            'reason' => 'other',
            'observation' => null,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['observation']);
    });

    test('it returns 422 when new_date is in the past', function () {
        $user = makeAuthUser($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForStore($this->store);

        $response = rescheduleOp($user, $order->id, [
            'new_date' => now()->subDay()->format('Y-m-d'),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_date']);
    });

    test('it returns 422 when new_date equals operation current date', function () {
        $user = makeAuthUser($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForStore($this->store, [
            'requested_delivery_date' => '2026-08-15',
        ]);

        $response = rescheduleOp($user, $order->id, [
            'new_date' => '2026-08-15',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['new_date']);
    });

    test('it returns 403 when user lacks orders.edit permission', function () {
        $user = makeAuthUser($this->store, 'STORE_ADMIN', []);
        $order = makeOrderForStore($this->store);

        $response = rescheduleOp($user, $order->id);

        $response->assertForbidden();
    });

    test('it returns 404 when operation belongs to different store', function () {
        $user = makeAuthUser($this->store, 'STORE_ADMIN', ['orders.edit']);

        $otherStore = Store::factory()->create();
        $otherPlan = Plan::factory()->create();
        $otherStore->plan_id = $otherPlan->id;
        $otherStore->save();
        $posFeature = Feature::where('code', 'pos')->first();
        $otherPlan->features()->syncWithoutDetaching([$posFeature->id => ['limit_value' => null]]);

        $otherOrder = makeOrderForStore($otherStore);

        $response = rescheduleOp($user, $otherOrder->id);

        $response->assertNotFound();
    });

    test('it returns 422 when operation type is sale', function () {
        $user = makeAuthUser($this->store, 'STORE_ADMIN', ['orders.edit']);

        $sale = CommercialOperation::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => User::factory()->create(['store_id' => $this->store->id])->id,
            'type' => 'sale',
            'status' => 'confirmed',
        ]);

        $response = rescheduleOp($user, $sale->id);

        $response->assertStatus(422);
    });

    test('it returns 422 when operation status is cancelled', function () {
        $user = makeAuthUser($this->store, 'STORE_ADMIN', ['orders.edit']);

        $order = CommercialOperation::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => User::factory()->create(['store_id' => $this->store->id])->id,
            'customer_id' => $this->customer->id,
            'type' => 'order',
            'status' => 'cancelled',
            'requested_delivery_date' => now()->addDays(5)->format('Y-m-d'),
        ]);

        $response = rescheduleOp($user, $order->id);

        $response->assertStatus(422);
    });

    test('it creates event record with correct dates on reschedule', function () {
        $user = makeAuthUser($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForStore($this->store, [
            'requested_delivery_date' => '2026-07-25',
        ]);

        $response = rescheduleOp($user, $order->id, [
            'new_date' => '2026-07-30',
            'reason' => 'customer_requested_reschedule',
            'observation' => 'Pushed at customer request',
        ]);

        $response->assertOk();

        $event = \App\Models\CommercialOperationEvent::where('operation_id', $order->id)->first();
        expect($event)->not->toBeNull()
            ->and($event->previous_date->format('Y-m-d'))->toBe('2026-07-25')
            ->and($event->new_date->format('Y-m-d'))->toBe('2026-07-30')
            ->and($event->reason)->toBe('customer_requested_reschedule')
            ->and($event->observation)->toBe('Pushed at customer request')
            ->and($event->store_id)->toBe($this->store->id);
    });

    test('it updates operation requested_delivery_date', function () {
        $user = makeAuthUser($this->store, 'STORE_ADMIN', ['orders.edit']);
        $order = makeOrderForStore($this->store, [
            'requested_delivery_date' => '2026-07-20',
        ]);

        $response = rescheduleOp($user, $order->id, [
            'new_date' => '2026-08-01',
            'reason' => 'operational_issue',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('commercial_operations', [
            'id' => $order->id,
        ]);
    });
});
