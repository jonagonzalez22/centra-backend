<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Feature;
use App\Models\Locality;
use App\Models\OperationPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
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
    ]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo('orders.view');
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

function makeListUser(Store $store, string $role = 'STORE_ADMIN', array $permissions = []): User
{
    $user = User::factory()->create(['store_id' => $store->id]);
    $user->assignRole($role);

    foreach ($permissions as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        $user->givePermissionTo($perm);
    }

    return $user;
}

function createOrderForStore(Store $store, array $attributes = []): \App\Models\CommercialOperation
{
    $user = User::factory()->create(['store_id' => $store->id]);

    return \App\Models\CommercialOperation::factory()->create(array_merge([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'type' => 'order',
        'status' => 'open',
    ], $attributes));
}

function listOrders(array $filters = [], ?string $token = null): \Illuminate\Testing\TestResponse
{
    $query = http_build_query($filters);
    $token = $token ?? test()->token;

    return test()->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/v1/store/orders?'.$query);
}

describe('GET /api/v1/store/orders — Orders List', function () {
    test('unauthenticated request returns 401', function () {
        $response = $this->getJson('/api/v1/store/orders');
        $response->assertStatus(401);
    });

    test('user without orders.view permission returns 403', function () {
        $userWithoutPerm = makeListUser($this->store, 'STORE_USER');
        $token = $userWithoutPerm->createToken('test-token')->plainTextToken;

        $response = listOrders([], $token);

        $response->assertStatus(403);
    });

    test('returns paginated orders for current store only', function () {
        $order1 = createOrderForStore($this->store);
        $order2 = createOrderForStore($this->store);

        $response = listOrders();

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($order1->id)
            ->toContain($order2->id);
    });

    test('does not return orders from other stores', function () {
        $otherStore = Store::factory()->create();
        $otherPlan = Plan::factory()->create();
        $otherStore->plan_id = $otherPlan->id;
        $otherStore->save();
        $posFeature = Feature::firstOrCreate(['code' => 'pos', 'name' => 'Punto de Venta']);
        $otherPlan->features()->syncWithoutDetaching([$posFeature->id => ['limit_value' => null]]);

        $myOrder = createOrderForStore($this->store);
        $otherOrder = createOrderForStore($otherStore);

        $response = listOrders();

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($myOrder->id)
            ->not()->toContain($otherOrder->id);
    });

    test('only returns type order operations', function () {
        $order = createOrderForStore($this->store);
        $sale = \App\Models\CommercialOperation::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'type' => 'sale',
            'status' => 'confirmed',
        ]);

        $response = listOrders();

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($order->id)
            ->not()->toContain($sale->id);
    });

    test('filters by status', function () {
        $openOrder = createOrderForStore($this->store, ['status' => 'open']);
        $cancelledOrder = createOrderForStore($this->store, ['status' => 'cancelled']);

        $response = listOrders(['status' => 'cancelled']);

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($cancelledOrder->id)
            ->not()->toContain($openOrder->id);
    });

    test('default filter shows only open and confirmed orders', function () {
        $openOrder = createOrderForStore($this->store, ['status' => 'open']);
        $confirmedOrder = createOrderForStore($this->store, ['status' => 'confirmed']);
        $cancelledOrder = createOrderForStore($this->store, ['status' => 'cancelled']);
        $closedOrder = createOrderForStore($this->store, ['status' => 'closed']);

        $response = listOrders();

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($openOrder->id)
            ->toContain($confirmedOrder->id)
            ->not()->toContain($cancelledOrder->id)
            ->not()->toContain($closedOrder->id);
    });

    test('filters by requested_delivery_date', function () {
        $matchingDate = '2026-12-25';
        $order1 = createOrderForStore($this->store, ['requested_delivery_date' => $matchingDate]);
        $order2 = createOrderForStore($this->store, ['requested_delivery_date' => '2026-12-26']);

        $response = listOrders(['date' => $matchingDate]);

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($order1->id)
            ->not()->toContain($order2->id);
    });

    test('filters by date range', function () {
        $orderInRange = createOrderForStore($this->store, ['requested_delivery_date' => '2026-07-15']);
        $orderBefore = createOrderForStore($this->store, ['requested_delivery_date' => '2026-06-01']);
        $orderAfter = createOrderForStore($this->store, ['requested_delivery_date' => '2026-08-01']);

        $response = listOrders([
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]);

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($orderInRange->id)
            ->not()->toContain($orderBefore->id)
            ->not()->toContain($orderAfter->id);
    });

    test('filters by operation_number partial match', function () {
        $order1 = createOrderForStore($this->store, ['operation_number' => 'P-000042']);
        $order2 = createOrderForStore($this->store, ['operation_number' => 'P-000099']);

        $response = listOrders(['operation_number' => '42']);

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($order1->id)
            ->not()->toContain($order2->id);
    });

    test('filters by customer_name partial match', function () {
        $customerA = Customer::factory()->create(['store_id' => $this->store->id, 'display_name' => 'Juan Perez']);
        $customerB = Customer::factory()->create(['store_id' => $this->store->id, 'display_name' => 'Maria Garcia']);

        $order1 = createOrderForStore($this->store, ['customer_id' => $customerA->id]);
        $order2 = createOrderForStore($this->store, ['customer_id' => $customerB->id]);

        $response = listOrders(['customer_name' => 'Perez']);

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($order1->id)
            ->not()->toContain($order2->id);
    });

    test('filters by locality', function () {
        $localityA = Locality::factory()->create(['province_id' => $this->province->id, 'name' => 'Palermo']);
        $localityB = Locality::factory()->create(['province_id' => $this->province->id, 'name' => 'Belgrano']);

        $customerA = Customer::factory()->create(['store_id' => $this->store->id]);
        $customerB = Customer::factory()->create(['store_id' => $this->store->id]);

        CustomerAddress::factory()->create([
            'customer_id' => $customerA->id,
            'locality_id' => $localityA->id,
            'is_main' => true,
        ]);
        CustomerAddress::factory()->create([
            'customer_id' => $customerB->id,
            'locality_id' => $localityB->id,
            'is_main' => true,
        ]);

        $order1 = createOrderForStore($this->store, ['customer_id' => $customerA->id]);
        $order2 = createOrderForStore($this->store, ['customer_id' => $customerB->id]);

        $response = listOrders(['locality' => 'Palermo']);

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($order1->id)
            ->not()->toContain($order2->id);
    });

    test('correct sort order — delivery_date ASC, operation_number ASC', function () {
        $order1 = createOrderForStore($this->store, [
            'requested_delivery_date' => '2026-07-20',
            'operation_number' => 'P-000003',
        ]);
        $order2 = createOrderForStore($this->store, [
            'requested_delivery_date' => '2026-07-20',
            'operation_number' => 'P-000001',
        ]);
        $order3 = createOrderForStore($this->store, [
            'requested_delivery_date' => '2026-07-18',
            'operation_number' => 'P-000005',
        ]);

        $response = listOrders();

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids[0])->toBe($order3->id);
        expect($ids[1])->toBe($order2->id);
        expect($ids[2])->toBe($order1->id);
    });

    test('includes paid_amount and pending_amount calculations', function () {
        $order = createOrderForStore($this->store, [
            'total' => 1000.00,
            'status' => 'open',
        ]);

        $paymentMethod = PaymentMethod::factory()->create();
        $storePaymentMethod = StorePaymentMethod::factory()->create([
            'store_id' => $this->store->id,
            'payment_method_id' => $paymentMethod->id,
        ]);
        OperationPayment::factory()->create([
            'operation_id' => $order->id,
            'store_payment_method_id' => $storePaymentMethod->id,
            'amount' => 400.00,
        ]);
        OperationPayment::factory()->create([
            'operation_id' => $order->id,
            'store_payment_method_id' => $storePaymentMethod->id,
            'amount' => 200.00,
        ]);

        $response = listOrders();

        $response->assertStatus(200);
        $data = collect($response->json('data.items'))->firstWhere('id', $order->id);
        expect($data)->not->toBeNull();
        expect((float) $data['paid_amount'])->toBe(600.00);
        expect((float) $data['pending_amount'])->toBe(400.00);
    });

    test('includes delivery_address from customer main address', function () {
        $order = createOrderForStore($this->store, [
            'customer_id' => $this->customer->id,
        ]);

        $response = listOrders();

        $response->assertStatus(200);
        $data = collect($response->json('data.items'))->firstWhere('id', $order->id);
        expect($data['delivery_address'])->not->toBeNull();
        expect($data['delivery_address']['street'])->toBe('Av. Corrientes');
        expect($data['delivery_address']['full_address'])->toBe('Av. Corrientes 1234');
        expect($data['delivery_address']['locality'])->toBe($this->locality->name);
    });
});
