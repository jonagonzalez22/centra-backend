<?php

use App\Models\Category;
use App\Models\CommercialOperation;
use App\Models\CommercialOperationCounter;
use App\Models\Customer;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_USER', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();
    $this->plan = Plan::factory()->create();
    $this->store->plan_id = $this->plan->id;
    $this->store->save();
    $this->plan->features()->attach(
        \App\Models\Feature::where('code', 'pos')->firstOrCreate(['code' => 'pos', 'name' => 'Punto de Venta']),
        ['limit_value' => null]
    );

    $this->category = Category::factory()->create(['store_id' => $this->store->id]);
    $this->product = Product::factory()->create([
        'store_id' => $this->store->id,
        'category_id' => $this->category->id,
    ]);
    $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

function createOperationForStore(Store $store, array $attributes = []): CommercialOperation
{
    $user = User::factory()->create(['store_id' => $store->id]);
    return CommercialOperation::factory()->create(array_merge([
        'store_id' => $store->id,
        'user_id' => $user->id,
    ], $attributes));
}

function listOperations(array $filters = []): \Illuminate\Testing\TestResponse
{
    $query = http_build_query($filters);
    return test()->withHeader('Authorization', 'Bearer ' . test()->token)
        ->getJson('/api/v1/store/operations?' . $query);
}

function getOperation(string $id): \Illuminate\Testing\TestResponse
{
    return test()->withHeader('Authorization', 'Bearer ' . test()->token)
        ->getJson('/api/v1/store/operations/' . $id);
}

describe('GET /api/v1/store/operations', function () {
    test('returns operations for the authenticated user store', function () {
        $operation = createOperationForStore($this->store, [
            'type' => 'sale',
            'status' => 'completed',
        ]);

        $response = listOperations();

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.items.0.id', $operation->id);
    });

    test('users can only list operations from their own store', function () {
        $otherStore = Store::factory()->create();
        $otherPlan = Plan::factory()->create();
        $otherStore->plan_id = $otherPlan->id;
        $otherStore->save();
        $otherPlan->features()->attach(
            \App\Models\Feature::where('code', 'pos')->firstOrCreate(['code' => 'pos', 'name' => 'Punto de Venta']),
            ['limit_value' => null]
        );

        $myOperation = createOperationForStore($this->store);
        $otherOperation = createOperationForStore($otherStore);

        $response = listOperations();

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($myOperation->id)
            ->not()->toContain($otherOperation->id);
    });

    test('filters by type work correctly', function () {
        $sale = createOperationForStore($this->store, ['type' => 'sale']);
        $order = createOperationForStore($this->store, ['type' => 'order']);

        $response = listOperations(['type' => 'sale']);

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($sale->id)
            ->not()->toContain($order->id);
    });

    test('filters by status work correctly', function () {
        $pending = createOperationForStore($this->store, ['status' => 'pending']);
        $completed = createOperationForStore($this->store, ['status' => 'completed']);

        $response = listOperations(['status' => 'completed']);

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($completed->id)
            ->not()->toContain($pending->id);
    });

    test('filters by customer_id work correctly', function () {
        $customer2 = Customer::factory()->create(['store_id' => $this->store->id]);
        $op1 = createOperationForStore($this->store, ['customer_id' => $this->customer->id]);
        $op2 = createOperationForStore($this->store, ['customer_id' => $customer2->id]);

        $response = listOperations(['customer_id' => $this->customer->id]);

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($op1->id)
            ->not()->toContain($op2->id);
    });

    test('filters by date_from and date_to work correctly', function () {
        $oldOperation = createOperationForStore($this->store);
        $oldOperation->created_at = now()->subDays(10);
        $oldOperation->save();

        $newOperation = createOperationForStore($this->store);
        $newOperation->created_at = now()->subDays(2);
        $newOperation->save();

        $response = listOperations([
            'date_from' => now()->subDays(5)->format('Y-m-d'),
            'date_to' => now()->format('Y-m-d'),
        ]);

        $response->assertStatus(200);
        $ids = collect($response->json('data.items'))->pluck('id')->toArray();
        expect($ids)->toContain($newOperation->id)
            ->not()->toContain($oldOperation->id);
    });

    test('unauthenticated request returns 401', function () {
        $response = $this->getJson('/api/v1/store/operations');
        $response->assertStatus(401);
    });
});

describe('GET /api/v1/store/operations/{id}', function () {
    test('returns operation when it belongs to user store', function () {
        $operation = createOperationForStore($this->store, [
            'customer_id' => $this->customer->id,
        ]);

        OperationItem::factory()->create([
            'operation_id' => $operation->id,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
        ]);

        $paymentMethod = PaymentMethod::factory()->create();
        $storePaymentMethod = StorePaymentMethod::factory()->create([
            'store_id' => $this->store->id,
            'payment_method_id' => $paymentMethod->id,
        ]);
        OperationPayment::factory()->create([
            'operation_id' => $operation->id,
            'store_payment_method_id' => $storePaymentMethod->id,
        ]);

        $response = getOperation($operation->id);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.id', $operation->id)
            ->assertJsonPath('data.operation_number', $operation->operation_number);
    });

    test('returns 404 when operation does not belong to user store', function () {
        $otherStore = Store::factory()->create();
        $otherOperation = createOperationForStore($otherStore);

        $response = getOperation($otherOperation->id);

        $response->assertStatus(404)
            ->assertJsonPath('status', 'error');
    });

    test('returns 404 for non-existent operation', function () {
        $fakeId = \Illuminate\Support\Str::uuid();
        $response = getOperation($fakeId);

        $response->assertStatus(404)
            ->assertJsonPath('status', 'error');
    });

    test('unauthenticated request returns 401', function () {
        $operation = createOperationForStore($this->store);
        $response = $this->getJson('/api/v1/store/operations/' . $operation->id);
        $response->assertStatus(401);
    });
});

describe('operation_items table structure', function () {
    test('operation_items do not contain store_id', function () {
        $operation = createOperationForStore($this->store);
        $item = OperationItem::factory()->create(['operation_id' => $operation->id]);

        expect($item)->not()->toHaveProperty('store_id');
        expect(Schema::hasColumn('operation_items', 'store_id'))->toBeFalse();
    });
});

describe('operation_payments table structure', function () {
    test('operation_payments do not contain store_id', function () {
        $operation = createOperationForStore($this->store);
        $paymentMethod = PaymentMethod::factory()->create();
        $storePaymentMethod = StorePaymentMethod::factory()->create([
            'store_id' => $this->store->id,
            'payment_method_id' => $paymentMethod->id,
        ]);
        $payment = OperationPayment::factory()->create([
            'operation_id' => $operation->id,
            'store_payment_method_id' => $storePaymentMethod->id,
        ]);

        expect($payment)->not()->toHaveProperty('store_id');
        expect(Schema::hasColumn('operation_payments', 'store_id'))->toBeFalse();
    });
});

describe('CommercialOperation::generateNumber()', function () {
    test('generates V-000001 for first sale', function () {
        $number = CommercialOperation::generateNumber('sale', $this->store->id);
        expect($number)->toBe('V-000001');
    });

    test('generates P-000001 for first order', function () {
        $number = CommercialOperation::generateNumber('order', $this->store->id);
        expect($number)->toBe('P-000001');
    });

    test('second sale in same store generates V-000002', function () {
        CommercialOperation::generateNumber('sale', $this->store->id);
        $number = CommercialOperation::generateNumber('sale', $this->store->id);
        expect($number)->toBe('V-000002');
    });

    test('sale in different store starts at V-000001 again', function () {
        CommercialOperation::generateNumber('sale', $this->store->id);

        $otherStore = Store::factory()->create();
        $otherNumber = CommercialOperation::generateNumber('sale', $otherStore->id);

        expect($otherNumber)->toBe('V-000001');
    });

    test('throws exception for invalid type', function () {
        expect(fn () => CommercialOperation::generateNumber('invalid', $this->store->id))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('sale and order counters are independent', function () {
        $saleNumber = CommercialOperation::generateNumber('sale', $this->store->id);
        $orderNumber = CommercialOperation::generateNumber('order', $this->store->id);

        expect($saleNumber)->toBe('V-000001');
        expect($orderNumber)->toBe('P-000001');
    });
});
