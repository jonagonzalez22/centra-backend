<?php

use App\Models\CommercialOperation;
use App\Models\DeliveryRoute;
use App\Models\Feature;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\Plan;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
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

function reportDelivery(
    Store $store,
    CommercialOperation $operation,
    string $occurredAt,
    string $status = 'completed',
    string $quantityDelivered = '1.0000',
): RouteStop {
    $route = DeliveryRoute::factory()->forStore($store)->create();
    $stop = RouteStop::factory()->create([
        'route_id' => $route->id,
        'order_id' => $operation->id,
        'status' => $status,
        'completed_at' => CarbonImmutable::parse($occurredAt, 'UTC'),
    ]);
    RouteStopItem::factory()->create([
        'route_stop_id' => $stop->id,
        'product_id' => reportProduct($store)->id,
        'quantity_delivered' => $quantityDelivered,
    ]);

    return $stop;
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

function analyticsFor(User $user, string $from = '2026-11-01', string $to = '2026-11-30')
{
    return test()->actingAs($user, 'sanctum')->getJson("/api/v1/store/reports/analytics?date_from={$from}&date_to={$to}");
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

test('counts physical delivery stops in the selected period and store', function (): void {
    $partial = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'partially_delivered']);
    $firstStop = reportDelivery($this->storeA, $partial, '2026-11-15 15:00:00', quantityDelivered: '6.0000');
    RouteStopItem::factory()->create([
        'route_stop_id' => $firstStop->id,
        'product_id' => reportProduct($this->storeA)->id,
        'quantity_delivered' => '1.0000',
        'is_extra' => true,
    ]);
    reportDelivery($this->storeA, $partial, '2026-11-16 15:00:00', quantityDelivered: '4.0000');
    $outside = reportOperation($this->storeA, $this->userA, ['type' => 'order']);
    reportDelivery($this->storeA, $outside, '2026-10-31 15:00:00');
    $failed = reportOperation($this->storeA, $this->userA, ['type' => 'order']);
    reportDelivery($this->storeA, $failed, '2026-11-15 15:00:00', status: 'failed');
    $empty = reportOperation($this->storeA, $this->userA, ['type' => 'order']);
    reportDelivery($this->storeA, $empty, '2026-11-15 15:00:00', quantityDelivered: '0.0000');

    $foreign = reportOperation($this->storeB, $this->userB, ['type' => 'order']);
    reportDelivery($this->storeB, $foreign, '2026-11-15 15:00:00');

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.deliveries_completed.count', 2);
    summaryFor($this->userB)->assertOk()
        ->assertJsonPath('data.metrics.deliveries_completed.count', 1);
});

test('uses store timezone boundaries for physical deliveries', function (): void {
    $outside = reportOperation($this->storeA, $this->userA, ['type' => 'order']);
    reportDelivery($this->storeA, $outside, '2026-11-01 02:59:59');
    $inside = reportOperation($this->storeA, $this->userA, ['type' => 'order']);
    reportDelivery($this->storeA, $inside, '2026-11-01 03:00:00');

    summaryFor($this->userA, '2026-11-01', '2026-11-01')->assertOk()
        ->assertJsonPath('data.metrics.deliveries_completed.count', 1);
});

test('reports current attention indicators scoped to the authenticated store', function (): void {
    reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'partially_delivered']);
    $deliveredWithBalance = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'delivered', 'total' => '1000.00']);
    reportPayment($deliveredWithBalance, $this->methodA, ['amount' => '400.00']);
    $fullyPaidDelivered = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'delivered', 'total' => '1000.00']);
    reportPayment($fullyPaidDelivered, $this->methodA, ['amount' => '1000.00']);
    DeliveryRoute::factory()->forStore($this->storeA)->create(['status' => 'awaiting_reconciliation']);
    DeliveryRoute::factory()->forStore($this->storeA)->create(['status' => 'dispatched']);

    reportOperation($this->storeB, $this->userB, ['type' => 'order', 'status' => 'partially_delivered']);
    $foreignDelivered = reportOperation($this->storeB, $this->userB, ['type' => 'order', 'status' => 'delivered', 'total' => '5000.00']);
    reportPayment($foreignDelivered, $this->methodB, ['amount' => '1.00']);
    DeliveryRoute::factory()->forStore($this->storeB)->create(['status' => 'awaiting_reconciliation']);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.current.attention.partially_delivered_orders', 1)
        ->assertJsonPath('data.current.attention.delivered_with_balance', 1)
        ->assertJsonPath('data.current.attention.routes_pending_reconciliation', 1);
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

test('returns tenant scoped daily analytics with local day boundaries, historical methods and pos products', function (): void {
    $atLocalStart = CarbonImmutable::parse('2026-11-01 03:00:00', 'UTC');
    $sale = reportOperation($this->storeA, $this->userA, [
        'type' => 'sale', 'status' => 'confirmed', 'total' => '100.00', 'created_at' => $atLocalStart,
    ]);
    $cancelled = reportOperation($this->storeA, $this->userA, [
        'type' => 'sale', 'status' => 'cancelled', 'total' => '500.00', 'created_at' => $atLocalStart,
    ]);
    $order = reportOperation($this->storeA, $this->userA, [
        'type' => 'order', 'status' => 'open', 'created_at' => CarbonImmutable::parse('2026-11-02 15:00:00', 'UTC'),
    ]);
    reportOperation($this->storeA, $this->userA, [
        'type' => 'order', 'status' => 'cancelled', 'created_at' => CarbonImmutable::parse('2026-11-02 15:00:00', 'UTC'),
    ]);
    reportDelivery($this->storeA, $order, '2026-11-02 15:00:00', quantityDelivered: '2.0000');
    reportDelivery($this->storeA, $order, '2026-11-03 15:00:00', quantityDelivered: '1.0000');

    $this->methodA->update(['custom_name' => 'Efectivo histórico', 'is_enabled' => false]);
    reportPayment($sale, $this->methodA, ['amount' => '10.00', 'created_at' => $atLocalStart]);
    reportPayment($order, $this->methodA, ['amount' => '40.00', 'created_at' => $atLocalStart]);
    reportPayment($order, $this->methodA, ['amount' => '90.00', 'status' => 'reversed', 'created_at' => $atLocalStart]);

    $productA = reportProduct($this->storeA, ['name' => 'Producto histórico']);
    $productB = reportProduct($this->storeA, ['name' => 'Otro producto']);
    OperationItem::factory()->forOperation($sale)->forProduct($productA)->create([
        'subtotal' => '80.00', 'tax_amount' => '20.00', 'discount_amount' => '10.00', 'created_at' => $atLocalStart,
    ]);
    OperationItem::factory()->forOperation($sale)->forProduct($productB)->create([
        'subtotal' => '40.00', 'tax_amount' => '0.00', 'discount_amount' => '0.00', 'created_at' => $atLocalStart,
    ]);
    OperationItem::factory()->forOperation($cancelled)->forProduct($productA)->create([
        'subtotal' => '900.00', 'tax_amount' => '0.00', 'discount_amount' => '0.00', 'created_at' => $atLocalStart,
    ]);

    $foreignSale = reportOperation($this->storeB, $this->userB, [
        'type' => 'sale', 'status' => 'confirmed', 'total' => '999.00', 'created_at' => $atLocalStart,
    ]);
    reportPayment($foreignSale, $this->methodB, ['amount' => '999.00', 'created_at' => $atLocalStart]);
    $foreignOrder = reportOperation($this->storeB, $this->userB, [
        'type' => 'order', 'status' => 'open', 'created_at' => CarbonImmutable::parse('2026-11-02 15:00:00', 'UTC'),
    ]);
    reportDelivery($this->storeB, $foreignOrder, '2026-11-02 15:00:00');

    summaryFor($this->userA, '2026-11-01', '2026-11-03')->assertOk()
        ->assertJsonPath('data.metrics.collected.amount', 50)
        ->assertJsonPath('data.metrics.collected.channels.pos_amount', 10)
        ->assertJsonPath('data.metrics.collected.channels.orders_amount', 40);

    analyticsFor($this->userA, '2026-11-01', '2026-11-03')->assertOk()
        ->assertJsonPath('data.period.timezone', 'America/Argentina/Mendoza')
        ->assertJsonPath('data.payments_series.0.date', '2026-11-01')
        ->assertJsonPath('data.payments_series.0.collected_amount', 50)
        ->assertJsonPath('data.payments_series.1.collected_amount', 0)
        ->assertJsonPath('data.orders_series.1.orders_taken_count', 1)
        ->assertJsonPath('data.orders_series.1.deliveries_count', 1)
        ->assertJsonPath('data.orders_series.2.deliveries_count', 1)
        ->assertJsonPath('data.payment_methods.0.name', 'Efectivo histórico')
        ->assertJsonPath('data.payment_methods.0.amount', 50)
        ->assertJsonPath('data.payment_methods.0.count', 2);
});

test('returns zero-filled analytics series without payment methods or pos products', function (): void {
    analyticsFor($this->userA, '2026-11-01', '2026-11-03')->assertOk()
        ->assertJsonCount(3, 'data.payments_series')
        ->assertJsonPath('data.payments_series.0.collected_amount', 0)
        ->assertJsonPath('data.orders_series.2.deliveries_count', 0)
        ->assertJsonCount(0, 'data.payment_methods');
});

test('validates dates and does not accept a global store report', function (): void {
    summaryFor($this->userA, '2026-11-30', '2026-11-01')->assertUnprocessable()
        ->assertJsonValidationErrors('date_to');

    $backoffice = User::factory()->create(['store_id' => null]);
    $backoffice->assignRole('STORE_ADMIN');
    summaryFor($backoffice)->assertForbidden()
        ->assertJsonPath('errors.store.0', 'Se requiere una tienda válida para consultar informes.');
});
