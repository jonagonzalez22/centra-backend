<?php

use App\Models\CommercialOperation;
use App\Models\Feature;
use App\Models\OperationPayment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::findOrCreate('STORE_ADMIN', 'web');

    $reports = Feature::factory()->create(['code' => 'reports']);
    $plan = Plan::factory()->create();
    $plan->features()->attach($reports);

    $this->storeA = Store::factory()->create(['plan_id' => $plan->id, 'timezone' => 'America/Argentina/Mendoza']);
    $this->storeB = Store::factory()->create(['plan_id' => $plan->id, 'timezone' => 'America/Argentina/Mendoza']);
    $this->userA = User::factory()->create(['store_id' => $this->storeA->id]);
    $this->userB = User::factory()->create(['store_id' => $this->storeB->id]);
    $this->userA->assignRole('STORE_ADMIN');
    $this->userB->assignRole('STORE_ADMIN');
    $this->methodA = StorePaymentMethod::factory()->forStore($this->storeA)->create();
    $this->methodB = StorePaymentMethod::factory()->forStore($this->storeB)->create();
});

function reportOperation(Store $store, User $user, array $attributes = []): CommercialOperation
{
    return CommercialOperation::factory()->forStore($store)->forUser($user)->create(array_merge([
        'operation_number' => 'R-'.fake()->unique()->numerify('######'),
        'created_at' => CarbonImmutable::parse('2026-11-15 15:00:00', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-11-15 15:00:00', 'UTC'),
    ], $attributes));
}

function reportPayment(CommercialOperation $operation, StorePaymentMethod $method, array $attributes = []): OperationPayment
{
    return OperationPayment::factory()->forOperation($operation)->forStorePaymentMethod($method)->create(array_merge([
        'status' => 'active',
        'created_at' => CarbonImmutable::parse('2026-11-15 15:00:00', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-11-15 15:00:00', 'UTC'),
    ], $attributes));
}

function reportProduct(Store $store, array $attributes = []): Product
{
    return Product::factory()->forStore($store)->create(array_merge([
        'stock_measurement_unit_id' => \App\Models\MeasurementUnit::query()->where('code', 'unit')->value('id'),
    ], $attributes));
}

function summaryFor(User $user, string $from = '2026-11-01', string $to = '2026-11-30')
{
    return test()->actingAs($user, 'sanctum')->getJson("/api/v1/store/reports/summary?date_from={$from}&date_to={$to}");
}

test('isolates period metrics, payments and inventory between stores', function (): void {
    reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '100.00']);
    reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '200.00']);
    reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'open', 'total' => '500.00']);
    $paymentA = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'open', 'total' => '100.00']);
    reportPayment($paymentA, $this->methodA, ['amount' => '60.00']);
    reportProduct($this->storeA, ['stock' => '0.0000', 'stock_min' => '5.0000']);
    reportProduct($this->storeA, ['stock' => '3.0000', 'stock_min' => '5.0000']);
    reportProduct($this->storeA, ['stock' => '10.0000', 'stock_min' => '5.0000']);
    reportProduct($this->storeA, ['stock' => '5.0000', 'stock_min' => '0.0000']);

    $paymentB = reportOperation($this->storeB, $this->userB, ['type' => 'sale', 'status' => 'confirmed', 'total' => '7777.00']);
    reportOperation($this->storeB, $this->userB, ['type' => 'order', 'status' => 'open', 'total' => '8888.00']);
    reportPayment($paymentB, $this->methodB, ['amount' => '9999.00']);
    reportProduct($this->storeB, ['stock' => '0.0000', 'stock_min' => '5.0000']);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.pos_sales.amount', 300)
        ->assertJsonPath('data.metrics.pos_sales.count', 2)
        ->assertJsonPath('data.metrics.pos_sales.average_ticket', 150)
        ->assertJsonPath('data.metrics.orders_taken.amount', 600)
        ->assertJsonPath('data.metrics.orders_taken.count', 2)
        ->assertJsonPath('data.metrics.collected.amount', 60)
        ->assertJsonPath('data.current.inventory_alerts.low_stock_count', 1)
        ->assertJsonPath('data.current.inventory_alerts.out_of_stock_count', 1);

    summaryFor($this->userB)->assertOk()
        ->assertJsonPath('data.metrics.pos_sales.amount', 7777)
        ->assertJsonPath('data.metrics.orders_taken.amount', 8888)
        ->assertJsonPath('data.metrics.collected.amount', 9999)
        ->assertJsonPath('data.current.inventory_alerts.low_stock_count', 0)
        ->assertJsonPath('data.current.inventory_alerts.out_of_stock_count', 1);
});

test('counts out of stock products by their commercial availability', function (): void {
    reportProduct($this->storeA, ['stock' => '0.0000', 'stock_min' => '5.0000']);
    reportProduct($this->storeA, ['stock' => '10.0000', 'stock_min' => '5.0000']);

    $sharedAvailableSource = reportProduct($this->storeA, ['stock' => '5.0000', 'stock_min' => '0.0000']);
    reportProduct($this->storeA, [
        'stock' => '0.0000',
        'stock_source_product_id' => $sharedAvailableSource->id,
        'stock_consumption_quantity' => '1.0000',
        'sale_quantity_step' => '1.0000',
    ]);

    $sharedEmptySource = reportProduct($this->storeA, ['stock' => '0.0000', 'is_active' => false]);
    reportProduct($this->storeA, [
        'stock' => '0.0000',
        'stock_source_product_id' => $sharedEmptySource->id,
        'stock_consumption_quantity' => '1.0000',
        'sale_quantity_step' => '1.0000',
    ]);

    reportProduct($this->storeB, ['stock' => '0.0000', 'stock_min' => '5.0000']);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.current.inventory_alerts.low_stock_count', 0)
        ->assertJsonPath('data.current.inventory_alerts.out_of_stock_count', 2);
});

test('excludes reversed payments and cancelled sales and orders', function (): void {
    reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '1000.00']);
    reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'cancelled', 'total' => '9000.00']);
    $operation = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'open', 'total' => '1000.00']);
    reportPayment($operation, $this->methodA, ['amount' => '1000.00', 'status' => 'active']);
    reportPayment($operation, $this->methodA, ['amount' => '9000.00', 'status' => 'reversed']);
    reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'partially_delivered', 'total' => '2000.00']);
    reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'delivered', 'total' => '3000.00']);
    reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'cancelled', 'total' => '9000.00']);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.pos_sales.amount', 1000)
        ->assertJsonPath('data.metrics.pos_sales.count', 1)
        ->assertJsonPath('data.metrics.orders_taken.amount', 6000)
        ->assertJsonPath('data.metrics.orders_taken.count', 3)
        ->assertJsonPath('data.metrics.collected.amount', 1000);
});

test('reports current pending balance independently from the selected period', function (): void {
    $october = CarbonImmutable::parse('2026-10-10 15:00:00', 'UTC');
    $open = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'open', 'total' => '1000.00', 'created_at' => $october]);
    reportPayment($open, $this->methodA, ['amount' => '100.00']);
    $partial = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'partially_delivered', 'total' => '2000.00']);
    reportPayment($partial, $this->methodA, ['amount' => '500.00']);
    $delivered = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'delivered', 'total' => '3000.00']);
    reportPayment($delivered, $this->methodA, ['amount' => '500.00', 'status' => 'reversed']);
    $creditSale = reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '4000.00']);
    reportPayment($creditSale, $this->methodA, ['amount' => '1000.00']);
    reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'cancelled', 'total' => '9000.00']);
    reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'closed', 'total' => '9000.00']);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.current.pending_balance.amount', 8400);
});

test('uses the store timezone at local day boundaries', function (): void {
    reportOperation($this->storeA, $this->userA, [
        'type' => 'sale', 'status' => 'confirmed', 'total' => '100.00',
        'created_at' => CarbonImmutable::parse('2026-11-01 02:59:59', 'UTC'),
    ]);
    reportOperation($this->storeA, $this->userA, [
        'type' => 'sale', 'status' => 'confirmed', 'total' => '200.00',
        'created_at' => CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC'),
    ]);

    summaryFor($this->userA, '2026-11-01', '2026-11-01')->assertOk()
        ->assertJsonPath('data.period.timezone', 'America/Argentina/Mendoza')
        ->assertJsonPath('data.metrics.pos_sales.amount', 200)
        ->assertJsonPath('data.metrics.pos_sales.count', 1);
});

test('validates dates and does not accept a global store report', function (): void {
    summaryFor($this->userA, '2026-11-30', '2026-11-01')->assertUnprocessable()
        ->assertJsonValidationErrors('date_to');

    $backoffice = User::factory()->create(['store_id' => null]);
    $backoffice->assignRole('STORE_ADMIN');
    summaryFor($backoffice)->assertForbidden()
        ->assertJsonPath('errors.store.0', 'Se requiere una tienda válida para consultar informes.');
});
