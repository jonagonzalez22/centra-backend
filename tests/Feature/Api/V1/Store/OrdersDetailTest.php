<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Feature;
use App\Models\Locality;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Province;
use App\Models\Store;
use App\Models\StorePaymentMethod;
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
