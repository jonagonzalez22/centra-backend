<?php

use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\DeliveryRoute;
use App\Models\OperationPayment;
use App\Models\RouteStop;
use App\Models\RouteStopCollection;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Permission::create(['name' => 'payments.view', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo('payments.view');
    $this->method = StorePaymentMethod::factory()->forStore($this->store)->create();
});

function receivedPaymentOperation(Store $store, array $attributes = []): CommercialOperation
{
    return CommercialOperation::factory()->forStore($store)->create(array_merge([
        'user_id' => User::factory()->create(['store_id' => $store->id])->id,
        'operation_number' => 'V-'.fake()->unique()->numerify('######'),
    ], $attributes));
}

function receivedPayment(CommercialOperation $operation, StorePaymentMethod $method, array $attributes = []): OperationPayment
{
    return OperationPayment::factory()->create(array_merge([
        'operation_id' => $operation->id,
        'store_payment_method_id' => $method->id,
        'registered_by' => null,
        'status' => 'active',
    ], $attributes));
}

test('lists POS, order deposit and manual collection payments with their normalized origins', function (): void {
    $sale = receivedPaymentOperation($this->store, ['operation_number' => 'V-000010']);
    $order = receivedPaymentOperation($this->store, ['type' => 'order', 'operation_number' => 'P-000022']);

    $cash = receivedPayment($sale, $this->method, ['amount' => 100, 'payment_details' => ['origin' => 'pos_sale']]);
    $transfer = receivedPayment($sale, $this->method, ['amount' => 200, 'payment_details' => ['origin' => 'pos_sale']]);
    $deposit = receivedPayment($order, $this->method, ['payment_details' => ['origin' => 'order_deposit']]);
    $manual = receivedPayment($order, $this->method, ['payment_details' => ['origin' => 'manual_collection'], 'registered_by' => $this->user->id]);

    $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/received-payments?per_page=20');

    $response->assertOk()
        ->assertJsonPath('data.total', 4)
        ->assertJsonFragment(['id' => $cash->id, 'origin' => 'pos_sale'])
        ->assertJsonFragment(['id' => $transfer->id, 'origin' => 'pos_sale'])
        ->assertJsonFragment(['id' => $deposit->id, 'origin' => 'order_deposit'])
        ->assertJsonFragment(['id' => $manual->id, 'origin' => 'manual_collection'])
        ->assertJsonFragment(['operation_number' => 'V-000010'])
        ->assertJsonFragment(['operation_number' => 'P-000022']);
});

test('only verified route collections with an operation payment appear as route payments', function (): void {
    $operation = receivedPaymentOperation($this->store, ['type' => 'order']);
    $driver = User::factory()->create(['store_id' => $this->store->id, 'name' => 'Chofer Ruta']);
    $declaredBy = User::factory()->create(['store_id' => $this->store->id, 'name' => 'Declarante']);
    $verifiedBy = User::factory()->create(['store_id' => $this->store->id, 'name' => 'Conciliador']);
    $route = DeliveryRoute::factory()->create([
        'store_id' => $this->store->id,
        'vehicle_id' => Vehicle::factory()->forStore($this->store),
        'driver_id' => $driver->id,
    ]);
    $stop = RouteStop::factory()->create(['route_id' => $route->id, 'order_id' => $operation->id]);
    $declared = RouteStopCollection::factory()->declared()->create([
        'store_id' => $this->store->id, 'route_stop_id' => $stop->id, 'commercial_operation_id' => $operation->id,
        'store_payment_method_id' => $this->method->id, 'declared_by' => $declaredBy->id, 'declared_at' => now()->subHour(),
    ]);
    $rejected = RouteStopCollection::factory()->create([
        'store_id' => $this->store->id, 'route_stop_id' => $stop->id, 'commercial_operation_id' => $operation->id,
        'store_payment_method_id' => $this->method->id, 'status' => 'rejected', 'declared_by' => $declaredBy->id,
        'declared_at' => now()->subHour(),
    ]);
    $payment = receivedPayment($operation, $this->method, ['payment_details' => ['route_stop_collection_id' => 'legacy-id']]);
    $verified = RouteStopCollection::factory()->create([
        'store_id' => $this->store->id, 'route_stop_id' => $stop->id, 'commercial_operation_id' => $operation->id,
        'store_payment_method_id' => $this->method->id, 'status' => 'verified', 'declared_by' => $declaredBy->id,
        'declared_at' => now()->subHour(), 'verified_by' => $verifiedBy->id, 'verified_at' => now(), 'operation_payment_id' => $payment->id,
    ]);

    $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/received-payments?origin=route_collection');

    $response->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.items.0.id', $payment->id)
        ->assertJsonPath('data.items.0.origin', 'route_collection')
        ->assertJsonPath('data.items.0.route.id', $route->id)
        ->assertJsonPath('data.items.0.route.route_stop_id', $stop->id)
        ->assertJsonPath('data.items.0.route.route_stop_collection_id', $verified->id)
        ->assertJsonPath('data.items.0.route.declared_by.id', $declaredBy->id)
        ->assertJsonPath('data.items.0.route.verified_by.id', $verifiedBy->id)
        ->assertJsonPath('data.items.0.route.driver.id', $driver->id)
        ->assertJsonMissing(['id' => $declared->id])
        ->assertJsonMissing(['id' => $rejected->id]);
});

test('includes reversed payments and supports all received payment filters', function (): void {
    $customer = Customer::factory()->create(['store_id' => $this->store->id, 'display_name' => 'Cliente Real']);
    $registeredBy = User::factory()->create(['store_id' => $this->store->id]);
    $operation = receivedPaymentOperation($this->store, ['customer_id' => $customer->id, 'operation_number' => 'P-000022']);
    $reversed = receivedPayment($operation, $this->method, [
        'reference' => 'transfer-001', 'registered_by' => $registeredBy->id, 'status' => 'reversed',
        'reversed_at' => now(), 'reversed_by' => $this->user->id, 'payment_details' => ['origin' => 'manual_collection'],
        'created_at' => now()->subDay(),
    ]);
    $fallbackOperation = receivedPaymentOperation($this->store, ['customer_display_name' => 'Mostrador Ana']);
    $fallback = receivedPayment($fallbackOperation, $this->method, ['payment_details' => ['origin' => 'pos_sale']]);
    $unknownOperation = receivedPaymentOperation($this->store);
    $unknown = receivedPayment($unknownOperation, $this->method, ['payment_details' => ['origin' => 'legacy']]);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/received-payments?date_from='.now()->subDays(2)->format('Y-m-d').'&date_to='.now()->format('Y-m-d').'&operation_number=P-000022&customer=Cliente%20Real&store_payment_method_id='.$this->method->id.'&origin=manual_collection&status=reversed&collected_by='.$registeredBy->id)
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.items.0.id', $reversed->id)
        ->assertJsonPath('data.items.0.reversed_by.id', $this->user->id)
        ->assertJsonPath('data.items.0.customer.id', $customer->id)
        ->assertJsonPath('data.items.0.customer.display_name', 'Cliente Real');

    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/received-payments?customer=Mostrador%20Ana')
        ->assertOk()->assertJsonPath('data.items.0.id', $fallback->id)
        ->assertJsonPath('data.items.0.customer.display_name', 'Mostrador Ana');

    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/received-payments?origin=unknown')
        ->assertOk()->assertJsonFragment(['id' => $unknown->id, 'origin' => 'unknown'])
        ->assertJsonPath('data.items.0.customer.id', null)
        ->assertJsonPath('data.items.0.customer.display_name', null);
});

test('paginates newest first and cannot expose another store payments', function (): void {
    $older = receivedPayment(receivedPaymentOperation($this->store), $this->method, ['created_at' => now()->subDay(), 'payment_details' => ['origin' => 'pos_sale']]);
    $newer = receivedPayment(receivedPaymentOperation($this->store), $this->method, ['created_at' => now(), 'payment_details' => ['origin' => 'pos_sale']]);
    $otherStore = Store::factory()->create();
    $otherMethod = StorePaymentMethod::factory()->forStore($otherStore)->create();
    $other = receivedPayment(receivedPaymentOperation($otherStore), $otherMethod, ['payment_details' => ['origin' => 'pos_sale']]);

    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/received-payments?per_page=1')
        ->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.per_page', 1)
        ->assertJsonPath('data.items.0.id', $newer->id)
        ->assertJsonMissing(['id' => $older->id])
        ->assertJsonMissing(['id' => $other->id]);
});

test('requires payments view permission', function (): void {
    $this->user->revokePermissionTo('payments.view');

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/received-payments')
        ->assertForbidden();
});

test('lists enabled and historically used payment methods only for the current store', function (): void {
    $enabled = StorePaymentMethod::factory()->forStore($this->store)->create(['custom_name' => 'Efectivo tienda']);
    $historic = StorePaymentMethod::factory()->forStore($this->store)->disabled()->create(['custom_name' => 'Mercado Pago']);
    $unused = StorePaymentMethod::factory()->forStore($this->store)->disabled()->create(['custom_name' => 'Sin uso']);
    $otherStore = Store::factory()->create();
    $otherMethod = StorePaymentMethod::factory()->forStore($otherStore)->disabled()->create(['custom_name' => 'Otra tienda']);

    receivedPayment(receivedPaymentOperation($this->store), $historic);
    receivedPayment(receivedPaymentOperation($otherStore), $otherMethod);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/received-payments/filter-options')
        ->assertOk()
        ->assertJsonFragment(['id' => $enabled->id, 'name' => 'Efectivo tienda', 'is_enabled' => true])
        ->assertJsonFragment(['id' => $historic->id, 'name' => 'Mercado Pago', 'is_enabled' => false])
        ->assertJsonMissing(['id' => $unused->id])
        ->assertJsonMissing(['id' => $otherMethod->id]);
});

test('filter options require payments view permission', function (): void {
    $this->user->revokePermissionTo('payments.view');

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/store/received-payments/filter-options')
        ->assertForbidden();
});

test('filters normal payments by registrar and route payments only by declarant', function (): void {
    $sebastian = User::factory()->create(['store_id' => $this->store->id, 'name' => 'Sebastian Rodriguez']);
    $hector = User::factory()->create(['store_id' => $this->store->id, 'name' => 'Hector Videla']);
    $pos = receivedPayment(receivedPaymentOperation($this->store), $this->method, [
        'registered_by' => $sebastian->id, 'payment_details' => ['origin' => 'pos_sale'],
    ]);
    $manual = receivedPayment(receivedPaymentOperation($this->store), $this->method, [
        'registered_by' => $sebastian->id, 'payment_details' => ['origin' => 'manual_collection'],
    ]);
    $operation = receivedPaymentOperation($this->store);
    $routePayment = receivedPayment($operation, $this->method, [
        'registered_by' => $sebastian->id, 'payment_details' => ['route_stop_collection_id' => 'route-collection'],
    ]);
    $route = DeliveryRoute::factory()->create(['store_id' => $this->store->id, 'vehicle_id' => Vehicle::factory()->forStore($this->store)]);
    $stop = RouteStop::factory()->create(['route_id' => $route->id, 'order_id' => $operation->id]);
    RouteStopCollection::factory()->create([
        'store_id' => $this->store->id, 'route_stop_id' => $stop->id, 'commercial_operation_id' => $operation->id,
        'store_payment_method_id' => $this->method->id, 'operation_payment_id' => $routePayment->id,
        'declared_by' => $hector->id, 'declared_at' => now()->subHour(), 'verified_by' => $sebastian->id,
    ]);

    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/received-payments?collected_by='.$sebastian->id)
        ->assertOk()->assertJsonFragment(['id' => $pos->id])->assertJsonFragment(['id' => $manual->id])
        ->assertJsonMissing(['id' => $routePayment->id]);
    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/received-payments?collected_by='.$hector->id)
        ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $routePayment->id);
});

test('filter options expose only distinct historical collectors for the current store', function (): void {
    $sebastian = User::factory()->create(['store_id' => $this->store->id, 'name' => 'Sebastian Rodriguez']);
    $hector = User::factory()->create(['store_id' => $this->store->id, 'name' => 'Hector Videla', 'is_active' => false]);
    $verifier = User::factory()->create(['store_id' => $this->store->id, 'name' => 'Only verifier']);
    receivedPayment(receivedPaymentOperation($this->store), $this->method, ['registered_by' => $sebastian->id]);
    $operation = receivedPaymentOperation($this->store);
    $routePayment = receivedPayment($operation, $this->method);
    $route = DeliveryRoute::factory()->create(['store_id' => $this->store->id, 'vehicle_id' => Vehicle::factory()->forStore($this->store)]);
    $stop = RouteStop::factory()->create(['route_id' => $route->id, 'order_id' => $operation->id]);
    RouteStopCollection::factory()->create(['store_id' => $this->store->id, 'route_stop_id' => $stop->id, 'commercial_operation_id' => $operation->id, 'store_payment_method_id' => $this->method->id, 'operation_payment_id' => $routePayment->id, 'declared_by' => $hector->id, 'declared_at' => now()->subHour(), 'verified_by' => $verifier->id]);
    $otherStore = Store::factory()->create();
    $otherMethod = StorePaymentMethod::factory()->forStore($otherStore)->create();
    $outsider = User::factory()->create(['store_id' => $otherStore->id, 'name' => 'Otra tienda']);
    receivedPayment(receivedPaymentOperation($otherStore), $otherMethod, ['registered_by' => $outsider->id]);

    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/received-payments/filter-options')
        ->assertOk()->assertJsonFragment(['id' => $sebastian->id, 'name' => 'Sebastian Rodriguez'])
        ->assertJsonFragment(['id' => $hector->id, 'name' => 'Hector Videla'])
        ->assertJsonMissing(['id' => $verifier->id])->assertJsonMissing(['id' => $outsider->id]);
});
