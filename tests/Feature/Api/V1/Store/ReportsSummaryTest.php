<?php

use App\Models\CommercialOperation;
use App\Models\DeliveryRoute;
use App\Models\DeliverySaleAllocation;
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

function reportSaleAllocation(
    Store $store,
    CommercialOperation $operation,
    Product $product,
    string $occurredAt,
    string $amount,
    bool $isExtra = false,
    string $quantityDelivered = '1.0000',
    ?string $unitCostSnapshot = null,
): DeliverySaleAllocation {
    $route = DeliveryRoute::factory()->forStore($store)->create();
    $stop = RouteStop::factory()->create(['route_id' => $route->id, 'order_id' => $operation->id]);
    $stopItem = RouteStopItem::factory()->create(['route_stop_id' => $stop->id, 'product_id' => $product->id]);
    $line = OperationItem::factory()->forOperation($operation)->forProduct($product)->create();

    return DeliverySaleAllocation::create([
        'store_id' => $store->id,
        'route_id' => $route->id,
        'route_stop_id' => $stop->id,
        'route_stop_item_id' => $stopItem->id,
        'operation_id' => $operation->id,
        'operation_item_id' => $line->id,
        'product_id' => $product->id,
        'quantity_delivered' => $quantityDelivered,
        'unit_price_snapshot' => $amount,
        'unit_cost_snapshot' => $unitCostSnapshot,
        'gross_amount' => $amount,
        'discount_amount' => '0.00',
        'tax_amount' => '0.00',
        'net_amount' => $amount,
        'is_extra' => $isExtra,
        'occurred_at' => CarbonImmutable::parse($occurredAt, 'UTC'),
    ]);
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

test('reports realized sales from POS plus immutable delivered allocations', function (): void {
    $posProduct = reportProduct($this->storeA, ['name' => 'Producto POS']);
    $orderProduct = reportProduct($this->storeA, ['name' => 'Producto pedido']);
    $sale = reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '100.00']);
    OperationItem::factory()->forOperation($sale)->forProduct($posProduct)->create([
        'subtotal' => '100.00', 'tax_amount' => '0.00', 'discount_amount' => '0.00',
    ]);
    $order = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'open']);
    reportSaleAllocation($this->storeA, $order, $orderProduct, '2026-11-15 15:00:00', '50.00');
    reportSaleAllocation($this->storeA, $order, $orderProduct, '2026-11-16 15:00:00', '25.00');

    $foreignOrder = reportOperation($this->storeB, $this->userB, ['type' => 'order', 'status' => 'open']);
    reportSaleAllocation($this->storeB, $foreignOrder, reportProduct($this->storeB), '2026-11-15 15:00:00', '999.00');

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.realized_sales.amount', 175)
        ->assertJsonPath('data.metrics.realized_sales.channels.pos.amount', 100)
        ->assertJsonPath('data.metrics.realized_sales.channels.orders.amount', 75)
        ->assertJsonPath('data.metrics.realized_sales.channels.pos.percentage', 57)
        ->assertJsonPath('data.metrics.realized_sales.channels.orders.percentage', 43);

    analyticsFor($this->userA, '2026-11-15', '2026-11-16')->assertOk()
        ->assertJsonPath('data.realized_sales_series.0.total_amount', 150)
        ->assertJsonPath('data.realized_sales_series.1.orders_amount', 25)
        ->assertJsonPath('data.top_products.0.name', 'Producto POS')
        ->assertJsonPath('data.top_products.0.operations_count', 1)
        ->assertJsonPath('data.top_products.1.name', 'Producto pedido')
        ->assertJsonPath('data.top_products.1.operations_count', 1);
});

test('reports POS gross profit from the frozen commercial SKU snapshot, not physical shared-stock valuation', function (): void {
    $source = reportProduct($this->storeA, ['name' => 'Fuente física', 'cost' => '30000.00']);
    $child = reportProduct($this->storeA, [
        'name' => 'SKU comercial', 'price' => '2000.00', 'cost' => '1000.00',
        'stock_source_product_id' => $source->id, 'stock_consumption_quantity' => '5.0000', 'stock' => '0.0000',
    ]);
    $sale = reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '2000.00']);
    OperationItem::factory()->forOperation($sale)->forProduct($child)->create([
        'quantity' => '1.0000', 'unit_cost_snapshot' => '1000.00',
        'stock_source_product_id' => $source->id, 'stock_consumption_quantity' => '5.0000',
    ]);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.gross_profit.known_cost_amount', 1000)
        ->assertJsonPath('data.metrics.gross_profit.amount', 1000)
        ->assertJsonPath('data.metrics.gross_profit.margin_percentage', 50)
        ->assertJsonPath('data.metrics.gross_profit.status', 'complete');
});

test('uses POS line snapshots after product cost changes, including zero cost and multiple lines', function (): void {
    $product = reportProduct($this->storeA, ['cost' => '200.00']);
    $sale = reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '700.00']);
    OperationItem::factory()->forOperation($sale)->forProduct($product)->create([
        'quantity' => '2.0000', 'unit_cost_snapshot' => '200.00',
    ]);
    OperationItem::factory()->forOperation($sale)->forProduct($product)->create([
        'quantity' => '1.0000', 'unit_cost_snapshot' => '0.00',
    ]);
    $product->update(['cost' => '9999.00']);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.gross_profit.known_cost_amount', 400)
        ->assertJsonPath('data.metrics.gross_profit.amount', 300)
        ->assertJsonPath('data.metrics.gross_profit.status', 'complete');
});

test('returns a complete zero gross profit for a completely empty period', function (): void {
    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.realized_sales.amount', 0)
        ->assertJsonPath('data.metrics.gross_profit.sales_amount', 0)
        ->assertJsonPath('data.metrics.gross_profit.known_cost_amount', 0)
        ->assertJsonPath('data.metrics.gross_profit.amount', 0)
        ->assertJsonPath('data.metrics.gross_profit.margin_percentage', null)
        ->assertJsonPath('data.metrics.gross_profit.status', 'complete');
});

test('calculates delivery-only gross profit without POS sales', function (): void {
    $product = reportProduct($this->storeA);
    $order = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'open']);
    reportSaleAllocation($this->storeA, $order, $product, '2026-11-15 15:00:00', '100.00', unitCostSnapshot: '40.00');

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.realized_sales.amount', 100)
        ->assertJsonPath('data.metrics.gross_profit.known_cost_amount', 40)
        ->assertJsonPath('data.metrics.gross_profit.amount', 60)
        ->assertJsonPath('data.metrics.gross_profit.status', 'complete');
});

test('marks a realized POS sale with a null commercial snapshot as unavailable instead of zero cost', function (): void {
    $product = reportProduct($this->storeA);
    $sale = reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '100.00']);
    OperationItem::factory()->forOperation($sale)->forProduct($product)->create(['quantity' => '1.0000', 'unit_cost_snapshot' => null]);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.gross_profit.known_cost_amount', 0)
        ->assertJsonPath('data.metrics.gross_profit.amount', null)
        ->assertJsonPath('data.metrics.gross_profit.status', 'unavailable')
        ->assertJsonPath('data.metrics.gross_profit.has_missing_costs', true);
});

test('excludes a cancelled POS sale and its commercial snapshot from gross profit', function (): void {
    $product = reportProduct($this->storeA);
    $confirmed = reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '100.00']);
    $cancelled = reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'cancelled', 'total' => '900.00']);
    OperationItem::factory()->forOperation($confirmed)->forProduct($product)->create(['quantity' => '1.0000', 'unit_cost_snapshot' => '40.00']);
    OperationItem::factory()->forOperation($cancelled)->forProduct($product)->create(['quantity' => '1.0000', 'unit_cost_snapshot' => '900.00']);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.realized_sales.amount', 100)
        ->assertJsonPath('data.metrics.gross_profit.known_cost_amount', 40)
        ->assertJsonPath('data.metrics.gross_profit.amount', 60);
});

test('marks gross profit partial or unavailable when POS commercial cost is unknown', function (): void {
    $product = reportProduct($this->storeA);
    $mixed = reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '100.00']);
    OperationItem::factory()->forOperation($mixed)->forProduct($product)->create(['quantity' => '1.0000', 'unit_cost_snapshot' => '40.00']);
    OperationItem::factory()->forOperation($mixed)->forProduct($product)->create(['quantity' => '1.0000', 'unit_cost_snapshot' => null]);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.gross_profit.amount', 60)
        ->assertJsonPath('data.metrics.gross_profit.status', 'partial')
        ->assertJsonPath('data.metrics.gross_profit.has_missing_costs', true);

    $mixed->update(['status' => 'cancelled']);
    $unknown = reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '50.00']);
    OperationItem::factory()->forOperation($unknown)->forProduct($product)->create(['quantity' => '1.0000', 'unit_cost_snapshot' => null]);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.gross_profit.amount', null)
        ->assertJsonPath('data.metrics.gross_profit.margin_percentage', null)
        ->assertJsonPath('data.metrics.gross_profit.status', 'unavailable');
});

test('uses each delivery allocation commercial snapshot for partial delivery and re-delivery', function (): void {
    $source = reportProduct($this->storeA, ['name' => 'Fuente física']);
    $commercial = reportProduct($this->storeA, [
        'name' => 'Producto comercial', 'stock_source_product_id' => $source->id, 'stock_consumption_quantity' => '0.5000', 'stock' => '0.0000',
    ]);
    $order = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'open']);
    reportSaleAllocation($this->storeA, $order, $commercial, '2026-11-15 15:00:00', '60.00', quantityDelivered: '6.0000', unitCostSnapshot: '10.00');
    reportSaleAllocation($this->storeA, $order, $commercial, '2026-11-16 15:00:00', '40.00', quantityDelivered: '4.0000', unitCostSnapshot: '20.00');

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.realized_sales.amount', 100)
        ->assertJsonPath('data.metrics.gross_profit.known_cost_amount', 140)
        ->assertJsonPath('data.metrics.gross_profit.amount', -40)
        ->assertJsonPath('data.metrics.gross_profit.status', 'complete');
});

test('uses delivery commercial snapshots for shared stock, extra sales, null, and zero cost', function (): void {
    $source = reportProduct($this->storeA, ['cost' => '30000.00']);
    $child = reportProduct($this->storeA, [
        'price' => '2000.00', 'cost' => '1000.00', 'stock' => '0.0000',
        'stock_source_product_id' => $source->id, 'stock_consumption_quantity' => '5.0000',
    ]);
    $order = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'open']);
    reportSaleAllocation($this->storeA, $order, $child, '2026-11-15 15:00:00', '2000.00', unitCostSnapshot: '1000.00');
    reportSaleAllocation($this->storeA, $order, $child, '2026-11-15 15:00:00', '100.00', true, unitCostSnapshot: '0.00');
    reportSaleAllocation($this->storeA, $order, $child, '2026-11-15 15:00:00', '50.00', unitCostSnapshot: null);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.realized_sales.amount', 2150)
        ->assertJsonPath('data.metrics.gross_profit.known_cost_amount', 1000)
        ->assertJsonPath('data.metrics.gross_profit.amount', 1150)
        ->assertJsonPath('data.metrics.gross_profit.status', 'partial')
        ->assertJsonPath('data.metrics.gross_profit.has_missing_costs', true);
});

test('keeps gross profit tenant scoped and supports negative margins and no sales', function (): void {
    $product = reportProduct($this->storeA);
    $sale = reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '10.00']);
    OperationItem::factory()->forOperation($sale)->forProduct($product)->create(['quantity' => '1.0000', 'unit_cost_snapshot' => '12.00']);
    $foreignProduct = reportProduct($this->storeB);
    $foreign = reportOperation($this->storeB, $this->userB, ['type' => 'sale', 'status' => 'confirmed', 'total' => '999.00']);
    OperationItem::factory()->forOperation($foreign)->forProduct($foreignProduct)->create(['quantity' => '1.0000', 'unit_cost_snapshot' => '1.00']);

    summaryFor($this->userA)->assertOk()
        ->assertJsonPath('data.metrics.gross_profit.amount', -2)
        ->assertJsonPath('data.metrics.gross_profit.margin_percentage', -20);
    summaryFor($this->userB, '2026-10-01', '2026-10-31')->assertOk()
        ->assertJsonPath('data.metrics.gross_profit.amount', 0)
        ->assertJsonPath('data.metrics.gross_profit.margin_percentage', null)
        ->assertJsonPath('data.metrics.gross_profit.status', 'complete');
});

test('ranks products by distinct realized operations without counting repeated lines or re-deliveries twice', function (): void {
    $frequent = reportProduct($this->storeA, ['name' => 'Frecuente']);
    $extra = reportProduct($this->storeA, ['name' => 'Extra']);
    $rare = reportProduct($this->storeA, ['name' => 'Raro']);

    $firstSale = reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed', 'total' => '9999.00']);
    // Two lines and 30 physical units still represent one choice in one POS sale.
    OperationItem::factory()->forOperation($firstSale)->forProduct($frequent)->create(['quantity' => '30.0000']);
    OperationItem::factory()->forOperation($firstSale)->forProduct($frequent)->create(['quantity' => '1.0000']);
    foreach (range(1, 2) as $index) {
        $sale = reportOperation($this->storeA, $this->userA, [
            'type' => 'sale', 'status' => 'confirmed', 'total' => '10.00',
            'operation_number' => "F-{$index}",
        ]);
        OperationItem::factory()->forOperation($sale)->forProduct($frequent)->create();
    }

    $order = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'open']);
    // Partial delivery plus re-delivery is one product choice for this order.
    reportSaleAllocation($this->storeA, $order, $frequent, '2026-11-02 15:00:00', '10.00');
    reportSaleAllocation($this->storeA, $order, $frequent, '2026-11-05 15:00:00', '10.00');
    // A new product added as an extra sale counts naturally in the same order.
    reportSaleAllocation($this->storeA, $order, $extra, '2026-11-05 15:00:00', '10.00', true);

    $outsidePeriodOrder = reportOperation($this->storeA, $this->userA, ['type' => 'order', 'status' => 'open']);
    reportSaleAllocation($this->storeA, $outsidePeriodOrder, $rare, '2026-10-31 02:59:59', '10.00');
    $foreignSale = reportOperation($this->storeB, $this->userB, ['type' => 'sale', 'status' => 'confirmed']);
    OperationItem::factory()->forOperation($foreignSale)->forProduct(reportProduct($this->storeB, ['name' => 'Extranjero']))->create();

    analyticsFor($this->userA, '2026-11-01', '2026-11-30')->assertOk()
        ->assertJsonPath('data.top_products.0.name', 'Frecuente')
        ->assertJsonPath('data.top_products.0.operations_count', 4)
        ->assertJsonPath('data.top_products.1.name', 'Extra')
        ->assertJsonPath('data.top_products.1.operations_count', 1)
        ->assertJsonMissing(['name' => 'Extranjero'])
        ->assertJsonMissing(['name' => 'Raro']);
});

test('limits most chosen products to five with a deterministic name tie-breaker', function (): void {
    foreach (['Zulu', 'Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'] as $name) {
        $product = reportProduct($this->storeA, ['name' => $name]);
        $sale = reportOperation($this->storeA, $this->userA, ['type' => 'sale', 'status' => 'confirmed']);
        OperationItem::factory()->forOperation($sale)->forProduct($product)->create();
    }

    analyticsFor($this->userA)->assertOk()
        ->assertJsonCount(5, 'data.top_products')
        ->assertJsonPath('data.top_products.0.name', 'Alpha')
        ->assertJsonPath('data.top_products.4.name', 'Echo')
        ->assertJsonMissing(['name' => 'Zulu']);
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
