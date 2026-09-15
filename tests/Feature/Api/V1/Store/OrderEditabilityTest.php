<?php

declare(strict_types=1);

use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\DeliveryRoute;
use App\Models\ExtraSaleAllocation;
use App\Models\Feature;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    Role::create(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Role::create(['name' => 'STORE_USER', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'orders.edit', 'guard_name' => 'web']);

    $this->store = Store::factory()->create();
    $plan = Plan::factory()->create();
    $this->store->update(['plan_id' => $plan->id]);
    $posFeature = Feature::firstOrCreate(['code' => 'pos', 'name' => 'Punto de Venta']);
    $plan->features()->syncWithoutDetaching([$posFeature->id => ['limit_value' => null]]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo('orders.edit');
    $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
    $this->product = Product::factory()->create([
        'store_id' => $this->store->id,
        'name' => 'Producto A',
        'stock' => 100,
        'stock_reserved' => 10,
    ]);
});

function orderEditabilityOrder(Store $store, User $user, Customer $customer, array $attributes = []): CommercialOperation
{
    return CommercialOperation::factory()->create(array_merge([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'type' => 'order',
        'status' => 'open',
        'requested_delivery_date' => now()->addDays(3)->toDateString(),
    ], $attributes));
}

function orderEditabilityItem(CommercialOperation $order, Product $product, int $quantity): OperationItem
{
    return OperationItem::factory()->create([
        'operation_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'price' => 100,
        'subtotal' => $quantity * 100,
    ]);
}

function orderEditabilityRouteItem(
    Store $store,
    User $user,
    CommercialOperation $order,
    Product $product,
    string $routeStatus,
    string $stopStatus,
    int $planned,
    int $delivered = 0,
    ?int $loaded = null
): array {
    $route = DeliveryRoute::create([
        'store_id' => $store->id,
        'vehicle_id' => Vehicle::factory()->forStore($store)->create()->id,
        'driver_id' => $user->id,
        'operational_date' => now()->toDateString(),
        'status' => $routeStatus,
        'created_by' => $user->id,
    ]);
    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => $stopStatus,
    ]);
    $item = RouteStopItem::create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
        'quantity_planned' => $planned,
        'quantity_loaded' => $loaded ?? $planned,
        'quantity_delivered' => $delivered,
    ]);

    return [$route, $stop, $item];
}

function getOrderEditability(User $user, string $orderId): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($user, 'sanctum')
        ->getJson('/api/v1/store/orders/'.$orderId.'/editability');
}

describe('GET /api/v1/store/orders/{order}/editability', function () {
    test('returns an editable snapshot for an open order without routes', function () {
        $order = orderEditabilityOrder($this->store, $this->user, $this->customer);
        orderEditabilityItem($order, $this->product, 10);

        $response = getOrderEditability($this->user, $order->id)->assertOk();

        $response
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.block_reason', null)
            ->assertJsonPath('data.delivery_date_editable', true)
            ->assertJsonPath('data.delivery_date_block_reason', null)
            ->assertJsonPath('data.delivery_date_block_message', null)
            ->assertJsonPath('data.items.0.product_id', $this->product->id)
            ->assertJsonPath('data.items.0.current_quantity', 10)
            ->assertJsonPath('data.items.0.delivered_quantity', 0)
            ->assertJsonPath('data.items.0.active_committed_quantity', 0)
            ->assertJsonPath('data.items.0.minimum_quantity', 0)
            ->assertJsonPath('data.items.0.editable_quantity', 10);

        expect($this->product->fresh()->stock_reserved)->toBe(10);
    });

    test('aggregates multiple order lines and separates completed delivery from active commitments', function () {
        $order = orderEditabilityOrder($this->store, $this->user, $this->customer, ['status' => 'partially_delivered']);
        orderEditabilityItem($order, $this->product, 4);
        orderEditabilityItem($order, $this->product, 6);

        orderEditabilityRouteItem($this->store, $this->user, $order, $this->product, 'completed', 'completed', 4, 4);
        orderEditabilityRouteItem($this->store, $this->user, $order, $this->product, 'planned', 'pending', 2);
        orderEditabilityRouteItem($this->store, $this->user, $order, $this->product, 'awaiting_reconciliation', 'completed', 6, 3, 3);
        orderEditabilityRouteItem($this->store, $this->user, $order, $this->product, 'loaded', 'cancelled', 9);

        $response = getOrderEditability($this->user, $order->id)->assertOk();

        $response
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.delivery_date_editable', false)
            ->assertJsonPath('data.delivery_date_block_reason', 'active_route_commitment')
            ->assertJsonPath('data.delivery_date_block_message', 'La fecha de entrega no puede modificarse porque el pedido tiene mercadería comprometida en una ruta activa.')
            ->assertJsonPath('data.items.0.current_quantity', 10)
            ->assertJsonPath('data.items.0.delivered_quantity', 4)
            ->assertJsonPath('data.items.0.active_committed_quantity', 5)
            ->assertJsonPath('data.items.0.minimum_quantity', 9)
            ->assertJsonPath('data.items.0.editable_quantity', 1);
    });

    test('uses the route lifecycle to calculate active committed quantity', function (string $routeStatus, int $planned, int $loaded, int $delivered, int $expectedCommitted) {
        $order = orderEditabilityOrder($this->store, $this->user, $this->customer);
        orderEditabilityItem($order, $this->product, 10);
        orderEditabilityRouteItem(
            $this->store,
            $this->user,
            $order,
            $this->product,
            $routeStatus,
            $routeStatus === 'awaiting_reconciliation' ? 'completed' : 'pending',
            $planned,
            $delivered,
            $loaded
        );

        getOrderEditability($this->user, $order->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.delivered_quantity', 0)
            ->assertJsonPath('data.items.0.active_committed_quantity', $expectedCommitted)
            ->assertJsonPath('data.items.0.minimum_quantity', $expectedCommitted);
    })->with([
        'draft uses planned quantity' => ['draft', 6, 3, 0, 6],
        'planned uses planned quantity' => ['planned', 6, 3, 0, 6],
        'loaded uses loaded quantity' => ['loaded', 6, 3, 0, 3],
        'dispatched uses loaded quantity' => ['dispatched', 6, 3, 0, 3],
        'awaiting reconciliation uses loaded quantity' => ['awaiting_reconciliation', 6, 3, 3, 3],
    ]);

    test('counts completed delivery as historical instead of active commitment', function () {
        $order = orderEditabilityOrder($this->store, $this->user, $this->customer, ['status' => 'partially_delivered']);
        orderEditabilityItem($order, $this->product, 10);
        orderEditabilityRouteItem($this->store, $this->user, $order, $this->product, 'completed', 'completed', 6, 4, 6);

        getOrderEditability($this->user, $order->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.delivered_quantity', 4)
            ->assertJsonPath('data.items.0.active_committed_quantity', 0)
            ->assertJsonPath('data.items.0.minimum_quantity', 4)
            ->assertJsonPath('data.items.0.editable_quantity', 6);
    });

    test('calculates the editable minimum for completed and partially loaded routes', function () {
        $order = orderEditabilityOrder($this->store, $this->user, $this->customer, ['status' => 'partially_delivered']);
        orderEditabilityItem($order, $this->product, 10);
        orderEditabilityRouteItem($this->store, $this->user, $order, $this->product, 'completed', 'completed', 4, 4, 4);
        orderEditabilityRouteItem($this->store, $this->user, $order, $this->product, 'dispatched', 'pending', 6, 3, 3);

        getOrderEditability($this->user, $order->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.delivered_quantity', 4)
            ->assertJsonPath('data.items.0.active_committed_quantity', 3)
            ->assertJsonPath('data.items.0.minimum_quantity', 7)
            ->assertJsonPath('data.items.0.editable_quantity', 3);
    });

    test('allows a partially delivered order without active commitments and keeps its historical minimum', function () {
        $order = orderEditabilityOrder($this->store, $this->user, $this->customer, ['status' => 'partially_delivered']);
        orderEditabilityItem($order, $this->product, 10);
        orderEditabilityRouteItem($this->store, $this->user, $order, $this->product, 'completed', 'completed', 4, 4);

        getOrderEditability($this->user, $order->id)
            ->assertOk()
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.delivery_date_editable', true)
            ->assertJsonPath('data.items.0.minimum_quantity', 4)
            ->assertJsonPath('data.items.0.editable_quantity', 6);
    });

    test('treats confirmed orders as editable', function () {
        $order = orderEditabilityOrder($this->store, $this->user, $this->customer, ['status' => 'confirmed']);
        orderEditabilityItem($order, $this->product, 10);

        getOrderEditability($this->user, $order->id)
            ->assertOk()
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.block_reason', null);
    });

    test('blocks terminal order statuses', function (string $status) {
        $order = orderEditabilityOrder($this->store, $this->user, $this->customer, ['status' => $status]);
        orderEditabilityItem($order, $this->product, 10);

        getOrderEditability($this->user, $order->id)
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.block_reason', 'terminal_status')
            ->assertJsonPath('data.delivery_date_editable', false)
            ->assertJsonPath('data.delivery_date_block_reason', 'terminal_status')
            ->assertJsonPath('data.delivery_date_block_message', 'El pedido no puede editarse en su estado actual.');
    })->with(['delivered', 'cancelled', 'closed']);

    test('blocks the whole order when an active extra sale allocation touches one of its route stop items', function () {
        $order = orderEditabilityOrder($this->store, $this->user, $this->customer);
        orderEditabilityItem($order, $this->product, 10);
        [$route, , $sourceItem] = orderEditabilityRouteItem($this->store, $this->user, $order, $this->product, 'dispatched', 'completed', 2, 0);

        $destinationOrder = orderEditabilityOrder($this->store, $this->user, $this->customer);
        [, $destinationStop, $destinationItem] = orderEditabilityRouteItem($this->store, $this->user, $destinationOrder, $this->product, 'dispatched', 'pending', 1);
        ExtraSaleAllocation::create([
            'store_id' => $this->store->id,
            'route_id' => $route->id,
            'source_stop_item_id' => $sourceItem->id,
            'destination_stop_id' => $destinationStop->id,
            'destination_stop_item_id' => $destinationItem->id,
            'quantity' => 1,
        ]);

        getOrderEditability($this->user, $order->id)
            ->assertOk()
            ->assertJsonPath('data.editable', false)
            ->assertJsonPath('data.block_reason', 'active_extra_sale')
            ->assertJsonPath('data.delivery_date_editable', false)
            ->assertJsonPath('data.delivery_date_block_reason', 'active_extra_sale')
            ->assertJsonPath('data.delivery_date_block_message', 'El pedido tiene una venta extra activa en una ruta operativa.');
    });

    test('does not block a historical extra sale allocation from a completed route', function () {
        $order = orderEditabilityOrder($this->store, $this->user, $this->customer);
        orderEditabilityItem($order, $this->product, 10);
        [$route, , $sourceItem] = orderEditabilityRouteItem($this->store, $this->user, $order, $this->product, 'completed', 'completed', 2, 2);

        $destinationOrder = orderEditabilityOrder($this->store, $this->user, $this->customer);
        [, $destinationStop, $destinationItem] = orderEditabilityRouteItem($this->store, $this->user, $destinationOrder, $this->product, 'completed', 'completed', 1, 1);
        ExtraSaleAllocation::create([
            'store_id' => $this->store->id,
            'route_id' => $route->id,
            'source_stop_item_id' => $sourceItem->id,
            'destination_stop_id' => $destinationStop->id,
            'destination_stop_item_id' => $destinationItem->id,
            'quantity' => 1,
        ]);

        getOrderEditability($this->user, $order->id)
            ->assertOk()
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.block_reason', null);
    });

    test('returns the sum of the order payments', function () {
        $order = orderEditabilityOrder($this->store, $this->user, $this->customer);
        orderEditabilityItem($order, $this->product, 10);
        $paymentMethod = PaymentMethod::factory()->create();
        $storePaymentMethod = StorePaymentMethod::factory()->create([
            'store_id' => $this->store->id,
            'payment_method_id' => $paymentMethod->id,
        ]);
        OperationPayment::factory()->create([
            'operation_id' => $order->id,
            'store_payment_method_id' => $storePaymentMethod->id,
            'amount' => 6000,
        ]);

        $response = getOrderEditability($this->user, $order->id)->assertOk();

        expect((float) $response->json('data.paid_amount'))->toBe(6000.0);
    });

    test('requires orders.edit and isolates orders by store and type', function () {
        $order = orderEditabilityOrder($this->store, $this->user, $this->customer);
        $withoutPermission = User::factory()->create(['store_id' => $this->store->id]);
        $withoutPermission->assignRole('STORE_USER');

        getOrderEditability($withoutPermission, $order->id)->assertForbidden();

        $otherStore = Store::factory()->create();
        $otherOrder = orderEditabilityOrder(
            $otherStore,
            User::factory()->create(['store_id' => $otherStore->id]),
            Customer::factory()->create(['store_id' => $otherStore->id])
        );
        getOrderEditability($this->user, $otherOrder->id)->assertNotFound();

        $sale = CommercialOperation::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'type' => 'sale',
            'status' => 'confirmed',
        ]);
        getOrderEditability($this->user, $sale->id)->assertNotFound();
    });
});
