<?php

declare(strict_types=1);

use App\Models\CommercialOperation;
use App\Models\CommercialOperationEvent;
use App\Models\Customer;
use App\Models\DeliveryRoute;
use App\Models\ExtraSaleAllocation;
use App\Models\Feature;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\PriceList;
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
    $this->productA = Product::factory()->create([
        'store_id' => $this->store->id,
        'name' => 'Producto A',
        'price' => 100,
        'stock' => 100,
        'stock_reserved' => 10,
    ]);
    $this->productB = Product::factory()->create([
        'store_id' => $this->store->id,
        'name' => 'Producto B',
        'price' => 250,
        'stock' => 20,
        'stock_reserved' => 0,
    ]);
});

function editableOrder(Store $store, User $user, Customer $customer, array $attributes = []): CommercialOperation
{
    return CommercialOperation::factory()->create(array_merge([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'type' => 'order',
        'status' => 'open',
        'subtotal' => 0,
        'tax' => 0,
        'discount' => 0,
        'total' => 0,
        'requested_delivery_date' => now()->addDays(3)->toDateString(),
    ], $attributes));
}

function editableOrderLine(CommercialOperation $order, Product $product, int $quantity, float $price = 100, float $tax = 0, float $discount = 0): OperationItem
{
    return OperationItem::factory()->create([
        'operation_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => $quantity,
        'price' => $price,
        'subtotal' => $quantity * $price,
        'tax_amount' => $tax,
        'discount_amount' => $discount,
    ]);
}

function editableOrderRouteItem(
    Store $store,
    User $user,
    CommercialOperation $order,
    Product $product,
    string $routeStatus,
    string $stopStatus,
    int $planned,
    int $loaded,
    int $delivered = 0
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
        'quantity_loaded' => $loaded,
        'quantity_delivered' => $delivered,
    ]);

    return [$route, $stop, $item];
}

function updateEditableOrder(User $user, CommercialOperation $order, array $items): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($user, 'sanctum')
        ->putJson('/api/v1/store/orders/'.$order->id, ['items' => $items]);
}

function updateEditableOrderDate(User $user, CommercialOperation $order, array $data): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($user, 'sanctum')
        ->putJson('/api/v1/store/orders/'.$order->id, $data);
}

function orderPayloadItem(Product $product, int $quantity): array
{
    return ['product_id' => $product->id, 'quantity' => $quantity];
}

describe('PUT /api/v1/store/orders/{order} — item editing', function () {
    test('allows reducing a historical non-sellable item but blocks increasing it', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        editableOrderLine($order, $this->productA, 10);
        $this->productA->update(['is_sellable' => false]);

        updateEditableOrder($this->user, $order, [orderPayloadItem($this->productA, 9)])
            ->assertOk();

        updateEditableOrder($this->user, $order->fresh(), [orderPayloadItem($this->productA, 10)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items']);

        expect((int) OperationItem::where('operation_id', $order->id)->sum('quantity'))->toBe(9);
    });

    test('changes delivery date without changing stock, reservations or items', function (string $status) {
        $order = editableOrder($this->store, $this->user, $this->customer, ['status' => $status]);
        editableOrderLine($order, $this->productA, 10);
        $newDate = now()->addDays(7)->toDateString();

        updateEditableOrderDate($this->user, $order, [
            'requested_delivery_date' => $newDate,
            'reason' => 'customer_requested_reschedule',
        ])->assertOk()->assertJsonPath('data.requested_delivery_date', $newDate);

        expect($order->fresh()->requested_delivery_date->format('Y-m-d'))->toBe($newDate)
            ->and($this->productA->fresh()->stock_reserved)->toBe('10.0000')
            ->and((int) OperationItem::where('operation_id', $order->id)->sum('quantity'))->toBe(10);
        $event = CommercialOperationEvent::where('operation_id', $order->id)->sole();
        expect($event->event_type)->toBe('order_edited')
            ->and($event->previous_date->format('Y-m-d'))->not->toBe($newDate)
            ->and($event->new_date->format('Y-m-d'))->toBe($newDate);
    })->with(['open', 'confirmed', 'partially_delivered']);

    test('blocks delivery date changes for active route commitments', function (string $routeStatus, int $planned, int $loaded) {
        $order = editableOrder($this->store, $this->user, $this->customer);
        editableOrderLine($order, $this->productA, 10);
        editableOrderRouteItem($this->store, $this->user, $order, $this->productA, $routeStatus, 'pending', $planned, $loaded);

        updateEditableOrderDate($this->user, $order, [
            'requested_delivery_date' => now()->addDays(7)->toDateString(),
            'reason' => 'customer_requested_reschedule',
        ])->assertStatus(422)->assertJsonValidationErrors('requested_delivery_date');
    })->with([
        ['draft', 2, 0], ['planned', 2, 0], ['loaded', 10, 6], ['dispatched', 10, 6], ['awaiting_reconciliation', 10, 6],
    ]);

    test('allows a delivery date change with completed route history only and rejects same date', function () {
        $order = editableOrder($this->store, $this->user, $this->customer, ['status' => 'partially_delivered']);
        editableOrderLine($order, $this->productA, 10);
        editableOrderRouteItem($this->store, $this->user, $order, $this->productA, 'completed', 'completed', 4, 4, 4);

        updateEditableOrderDate($this->user, $order, [
            'requested_delivery_date' => now()->addDays(7)->toDateString(),
            'reason' => 'customer_requested_reschedule',
        ])->assertOk();

        updateEditableOrderDate($this->user, $order->fresh(), [
            'requested_delivery_date' => now()->addDays(7)->toDateString(),
            'reason' => 'customer_requested_reschedule',
        ])->assertStatus(422)->assertJsonValidationErrors('requested_delivery_date');
    });

    test('updates items and date atomically and rolls both back when the date is blocked', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        editableOrderLine($order, $this->productA, 10);
        editableOrderRouteItem($this->store, $this->user, $order, $this->productA, 'planned', 'pending', 2, 0);
        $originalDate = $order->requested_delivery_date->format('Y-m-d');

        updateEditableOrderDate($this->user, $order, [
            'items' => [orderPayloadItem($this->productA, 9)],
            'requested_delivery_date' => now()->addDays(7)->toDateString(),
            'reason' => 'customer_requested_reschedule',
        ])->assertStatus(422)->assertJsonValidationErrors('requested_delivery_date');

        expect((int) OperationItem::where('operation_id', $order->id)->sum('quantity'))->toBe(10)
            ->and($order->fresh()->requested_delivery_date->format('Y-m-d'))->toBe($originalDate);
    });

    test('requires a valid new date and reason, and applies valid combined edits', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        editableOrderLine($order, $this->productA, 10);
        $newDate = now()->addDays(7)->toDateString();

        updateEditableOrderDate($this->user, $order, ['requested_delivery_date' => 'not-a-date'])
            ->assertStatus(422)->assertJsonValidationErrors('requested_delivery_date');
        updateEditableOrderDate($this->user, $order, ['requested_delivery_date' => $newDate])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        updateEditableOrderDate($this->user, $order, [
            'items' => [orderPayloadItem($this->productA, 8)],
            'requested_delivery_date' => $newDate,
            'reason' => 'customer_requested_reschedule',
        ])->assertOk();

        expect((int) OperationItem::where('operation_id', $order->id)->sum('quantity'))->toBe(8)
            ->and($order->fresh()->requested_delivery_date->format('Y-m-d'))->toBe($newDate)
            ->and(CommercialOperationEvent::where('operation_id', $order->id)->count())->toBe(1);
    });
    test('increases an existing product, reserves only the delta and records history', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        editableOrderLine($order, $this->productA, 10, 120, 10, 5);

        $response = updateEditableOrder($this->user, $order, [orderPayloadItem($this->productA, 12)])
            ->assertOk();

        expect((float) $response->json('data.total'))->toBe(1446.0);
        expect($response->json('data.items'))->toHaveCount(1)
            ->and($response->json('data.items.0.quantity'))->toBe('12.0000')
            ->and((float) $response->json('data.items.0.subtotal'))->toBe(1440.0);

        expect($this->productA->fresh()->stock_reserved)->toBe('12.0000');
        $lines = OperationItem::where('operation_id', $order->id)->orderBy('created_at')->get();
        expect($lines)->toHaveCount(1)
            ->and($lines->sole()->quantity)->toBe('12.0000')
            ->and((float) $lines->sole()->price)->toBe(120.0)
            ->and((float) $lines->sole()->tax_amount)->toBe(12.0)
            ->and((float) $lines->sole()->discount_amount)->toBe(6.0);
        expect(CommercialOperationEvent::where('operation_id', $order->id)->where('event_type', 'order_edited')->exists())->toBeTrue();
    });

    test('adds and changes a price-list line through its stable operation item identity', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        $wholesale = PriceList::factory()->create(['store_id' => $this->store->id, 'name' => 'Mayorista', 'discount_percentage' => '10.00']);
        $common = editableOrderLine($order, $this->productA, 1, 100);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/store/orders/{$order->id}/items", [
                'product_id' => $this->productA->id,
                'quantity' => '1',
                'price_list_id' => $wholesale->id,
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.items');

        $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/store/orders/{$order->id}/items/{$common->id}/price-list", [
                'price_list_id' => $wholesale->id,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $line = OperationItem::where('operation_id', $order->id)->sole();
        expect($line->quantity)->toBe('2.0000')
            ->and($line->price_list_id)->toBe($wholesale->id)
            ->and($line->discount_amount)->toBe('20.00');
    });

    test('PATCH quantity mutates only the selected operation item and preserves pricing snapshots', function () {
        $this->productA->update(['price' => '1000.00']);
        $order = editableOrder($this->store, $this->user, $this->customer);
        $wholesale = PriceList::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Mayorista',
            'discount_percentage' => '10.00',
        ]);

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/orders/{$order->id}/items", [
            'product_id' => $this->productA->id,
            'quantity' => '2',
            'price_list_id' => null,
        ])->assertOk();
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/orders/{$order->id}/items", [
            'product_id' => $this->productA->id,
            'quantity' => '2',
            'price_list_id' => $wholesale->id,
        ])->assertOk();

        $common = $order->items()->whereNull('price_list_id')->sole();
        $discounted = $order->items()->where('price_list_id', $wholesale->id)->sole();
        $this->productA->update(['price' => '1200.00']);
        $wholesale->update(['name' => 'Mayorista actualizado', 'discount_percentage' => '15.00']);

        $this->actingAs($this->user, 'sanctum')->patchJson("/api/v1/store/orders/{$order->id}/items/{$common->id}", [
            'quantity' => '3',
        ])->assertOk()->assertJsonPath('data.subtotal', 5000)
            ->assertJsonPath('data.discount', 200)
            ->assertJsonPath('data.total', 4800);

        expect($common->fresh()->quantity)->toBe('3.0000')
            ->and($common->fresh()->price)->toBe('1000.00')
            ->and($common->fresh()->discount_amount)->toBe('0.00')
            ->and($discounted->fresh()->quantity)->toBe('2.0000')
            ->and($discounted->fresh()->price)->toBe('1000.00')
            ->and($discounted->fresh()->price_list_id)->toBe($wholesale->id)
            ->and($discounted->fresh()->price_list_name)->toBe('Mayorista')
            ->and($discounted->fresh()->discount_percentage)->toBe('10.00')
            ->and($discounted->fresh()->discount_amount)->toBe('200.00');

        $this->actingAs($this->user, 'sanctum')->patchJson("/api/v1/store/orders/{$order->id}/items/{$discounted->id}", [
            'quantity' => '3',
        ])->assertOk()->assertJsonPath('data.subtotal', 6000)
            ->assertJsonPath('data.discount', 300)
            ->assertJsonPath('data.total', 5700);

        expect($discounted->fresh()->quantity)->toBe('3.0000')
            ->and($discounted->fresh()->price_list_name)->toBe('Mayorista')
            ->and($discounted->fresh()->discount_percentage)->toBe('10.00')
            ->and($discounted->fresh()->discount_amount)->toBe('300.00')
            ->and($common->fresh()->quantity)->toBe('3.0000');
    });

    test('PATCH quantity rejects increases on inactive lists but permits decreases and common increases', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        $list = PriceList::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Mayorista',
            'discount_percentage' => '10.00',
        ]);
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/orders/{$order->id}/items", [
            'product_id' => $this->productA->id, 'quantity' => '2', 'price_list_id' => $list->id,
        ])->assertOk();
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/orders/{$order->id}/items", [
            'product_id' => $this->productA->id, 'quantity' => '2', 'price_list_id' => null,
        ])->assertOk();
        $discounted = $order->items()->where('price_list_id', $list->id)->sole();
        $common = $order->items()->whereNull('price_list_id')->sole();
        $list->update(['is_active' => false]);

        $this->actingAs($this->user, 'sanctum')->patchJson("/api/v1/store/orders/{$order->id}/items/{$discounted->id}", [
            'quantity' => '3',
        ])->assertUnprocessable()->assertJsonValidationErrors('price_list_id');

        $this->actingAs($this->user, 'sanctum')->patchJson("/api/v1/store/orders/{$order->id}/items/{$discounted->id}", [
            'quantity' => '1',
        ])->assertOk();
        $this->actingAs($this->user, 'sanctum')->patchJson("/api/v1/store/orders/{$order->id}/items/{$common->id}", [
            'quantity' => '3',
        ])->assertOk();

        expect($discounted->fresh()->quantity)->toBe('1.0000')
            ->and($discounted->fresh()->price_list_name)->toBe('Mayorista')
            ->and($discounted->fresh()->discount_percentage)->toBe('10.00')
            ->and($discounted->fresh()->discount_amount)->toBe('10.00')
            ->and($common->fresh()->quantity)->toBe('3.0000')
            ->and($order->fresh()->discount)->toBe('10.00');
    });

    test('DELETE removes only the selected commercial line and recalculates totals', function () {
        $this->productA->update(['price' => '1000.00']);
        $order = editableOrder($this->store, $this->user, $this->customer);
        $list = PriceList::factory()->create([
            'store_id' => $this->store->id,
            'name' => 'Mayorista',
            'discount_percentage' => '10.00',
        ]);
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/orders/{$order->id}/items", [
            'product_id' => $this->productA->id, 'quantity' => '1', 'price_list_id' => null,
        ])->assertOk();
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/orders/{$order->id}/items", [
            'product_id' => $this->productA->id, 'quantity' => '2', 'price_list_id' => $list->id,
        ])->assertOk();
        $common = $order->items()->whereNull('price_list_id')->sole();
        $discounted = $order->items()->where('price_list_id', $list->id)->sole();

        $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/store/orders/{$order->id}/items/{$common->id}")
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.subtotal', 2000)
            ->assertJsonPath('data.discount', 200)
            ->assertJsonPath('data.total', 1800);

        expect(OperationItem::whereKey($common->id)->exists())->toBeFalse()
            ->and($discounted->fresh()->quantity)->toBe('2.0000')
            ->and($discounted->fresh()->price_list_id)->toBe($list->id)
            ->and($this->productA->fresh()->stock_reserved)->toBe('12.0000');
    });

    test('item endpoints reject cross-order, cross-store, missing and non-editable targets', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        $line = editableOrderLine($order, $this->productA, 2);
        $anotherOrder = editableOrder($this->store, $this->user, $this->customer);
        $anotherLine = editableOrderLine($anotherOrder, $this->productB, 1, 250);
        $foreignStore = Store::factory()->create();
        $foreignUser = User::factory()->create(['store_id' => $foreignStore->id]);
        $foreignOrder = editableOrder(
            $foreignStore,
            $foreignUser,
            Customer::factory()->create(['store_id' => $foreignStore->id])
        );
        $foreignProduct = Product::factory()->create(['store_id' => $foreignStore->id]);
        $foreignLine = editableOrderLine($foreignOrder, $foreignProduct, 1);
        $this->actingAs($this->user, 'sanctum');

        $this->patchJson("/api/v1/store/orders/{$order->id}/items/{$anotherLine->id}", ['quantity' => '3'])->assertNotFound();
        $this->deleteJson("/api/v1/store/orders/{$order->id}/items/{$foreignLine->id}")->assertNotFound();
        $this->patchJson("/api/v1/store/orders/{$foreignOrder->id}/items/{$foreignLine->id}", ['quantity' => '2'])->assertNotFound();
        $this->patchJson("/api/v1/store/orders/{$order->id}/items/00000000-0000-4000-8000-000000000000", ['quantity' => '3'])->assertNotFound();
        $this->deleteJson('/api/v1/store/orders/00000000-0000-4000-8000-000000000000/items/'.$line->id)->assertNotFound();

        $sale = CommercialOperation::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'type' => 'sale',
            'status' => 'confirmed',
        ]);
        $saleLine = editableOrderLine($sale, $this->productA, 1);
        $this->patchJson("/api/v1/store/orders/{$sale->id}/items/{$saleLine->id}", ['quantity' => '2'])->assertNotFound();
        $this->deleteJson("/api/v1/store/orders/{$sale->id}/items/{$saleLine->id}")->assertNotFound();

        $closedOrder = editableOrder($this->store, $this->user, $this->customer, ['status' => 'closed']);
        $closedLine = editableOrderLine($closedOrder, $this->productB, 1, 250);
        $this->patchJson("/api/v1/store/orders/{$closedOrder->id}/items/{$closedLine->id}", ['quantity' => '2'])
            ->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->deleteJson("/api/v1/store/orders/{$closedOrder->id}/items/{$closedLine->id}")
            ->assertUnprocessable()->assertJsonValidationErrors('order');
    });

    test('quantity endpoint preserves product-level delivery minimum across separate price-list lines', function () {
        $order = editableOrder($this->store, $this->user, $this->customer, ['status' => 'partially_delivered']);
        $wholesale = PriceList::factory()->create(['store_id' => $this->store->id, 'discount_percentage' => '10.00']);
        $common = editableOrderLine($order, $this->productA, 2);
        $common->update(['price_list_id' => null, 'discount_percentage' => '0.00']);
        $discounted = editableOrderLine($order, $this->productA, 2);
        $discounted->update([
            'price_list_id' => $wholesale->id,
            'price_list_name' => $wholesale->name,
            'discount_percentage' => '10.00',
            'discount_amount' => '20.00',
        ]);
        $this->productA->update(['stock_reserved' => '4.0000']);
        editableOrderRouteItem($this->store, $this->user, $order, $this->productA, 'completed', 'completed', 2, 2, 2);

        $this->actingAs($this->user, 'sanctum')->patchJson("/api/v1/store/orders/{$order->id}/items/{$common->id}", [
            'quantity' => '1',
        ])->assertOk();

        $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/store/orders/{$order->id}/items/{$common->id}")
            ->assertOk();

        expect(OperationItem::whereKey($common->id)->exists())->toBeFalse()
            ->and($discounted->fresh()->quantity)->toBe('2.0000')
            ->and(OperationItem::where('operation_id', $order->id)->sum('quantity'))->toBe(2);
    });

    test('quantity endpoint rejects values outside the product sale quantity step', function () {
        $this->productA->update(['sale_quantity_step' => '0.5000']);
        $order = editableOrder($this->store, $this->user, $this->customer);
        $line = editableOrderLine($order, $this->productA, 2);

        $this->actingAs($this->user, 'sanctum')->patchJson("/api/v1/store/orders/{$order->id}/items/{$line->id}", [
            'quantity' => '2.2500',
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        expect($line->fresh()->quantity)->toBe('2.0000');
    });

    test('reduces an editable quantity and releases the matching reservation', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        editableOrderLine($order, $this->productA, 10);

        $response = updateEditableOrder($this->user, $order, [orderPayloadItem($this->productA, 6)])->assertOk();

        expect($this->productA->fresh()->stock_reserved)->toBe('6.0000')
            ->and((int) OperationItem::where('operation_id', $order->id)->sum('quantity'))->toBe(6)
            ->and((float) $order->fresh()->total)->toBe(600.0);
        expect($response->json('data.items'))->toHaveCount(1)
            ->and($response->json('data.items.0.quantity'))->toBe('6.0000')
            ->and((int) $response->json('data.delivery_summary.items.0.ordered_quantity'))->toBe(6);
    });

    test('allows editing a confirmed order', function () {
        $order = editableOrder($this->store, $this->user, $this->customer, ['status' => 'confirmed']);
        editableOrderLine($order, $this->productA, 10);

        updateEditableOrder($this->user, $order, [orderPayloadItem($this->productA, 11)])->assertOk();

        expect($this->productA->fresh()->stock_reserved)->toBe('11.0000')
            ->and((int) OperationItem::where('operation_id', $order->id)->sum('quantity'))->toBe(11);
    });

    test('uses LIFO reductions across multiple operation items', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        $first = editableOrderLine($order, $this->productA, 4, 100);
        $second = editableOrderLine($order, $this->productA, 6, 120);
        $first->update(['created_at' => now()->subMinute()]);
        $second->update(['created_at' => now()]);

        updateEditableOrder($this->user, $order, [orderPayloadItem($this->productA, 7)])->assertOk();

        expect($first->fresh()->quantity)->toBe('4.0000')
            ->and($second->fresh()->quantity)->toBe('3.0000')
            ->and($this->productA->fresh()->stock_reserved)->toBe('7.0000');
    });

    test('allows the exact minimum and rejects quantities below active commitments', function () {
        $order = editableOrder($this->store, $this->user, $this->customer, ['status' => 'partially_delivered']);
        editableOrderLine($order, $this->productA, 10);
        editableOrderRouteItem($this->store, $this->user, $order, $this->productA, 'completed', 'completed', 4, 4, 4);
        editableOrderRouteItem($this->store, $this->user, $order, $this->productA, 'dispatched', 'pending', 6, 3, 3);

        updateEditableOrder($this->user, $order, [orderPayloadItem($this->productA, 7)])->assertOk();
        expect($this->productA->fresh()->stock_reserved)->toBe('7.0000');

        updateEditableOrder($this->user, $order->fresh(), [orderPayloadItem($this->productA, 6)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
        expect((int) OperationItem::where('operation_id', $order->id)->sum('quantity'))->toBe(7);
    });

    test('removes an absent product only when its minimum is zero and keeps another item', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        editableOrderLine($order, $this->productA, 10);
        editableOrderLine($order, $this->productB, 2, 250);
        $this->productB->update(['stock_reserved' => 2]);

        updateEditableOrder($this->user, $order, [orderPayloadItem($this->productB, 2)])->assertOk();

        expect($this->productA->fresh()->stock_reserved)->toBe('0.0000')
            ->and((int) OperationItem::where('operation_id', $order->id)->where('product_id', $this->productA->id)->sum('quantity'))->toBe(0)
            ->and((int) OperationItem::where('operation_id', $order->id)->sum('quantity'))->toBe(2);
    });

    test('rejects removal below the minimum and an empty payload', function () {
        $order = editableOrder($this->store, $this->user, $this->customer, ['status' => 'partially_delivered']);
        editableOrderLine($order, $this->productA, 10);
        editableOrderLine($order, $this->productB, 1, 250);
        editableOrderRouteItem($this->store, $this->user, $order, $this->productA, 'planned', 'pending', 3, 0);

        updateEditableOrder($this->user, $order, [orderPayloadItem($this->productB, 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        updateEditableOrder($this->user, $order, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
    });

    test('adds a new product with its current price and reserves it', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        editableOrderLine($order, $this->productA, 10);

        updateEditableOrder($this->user, $order, [
            orderPayloadItem($this->productA, 10),
            orderPayloadItem($this->productB, 2),
        ])->assertOk();

        $newLine = OperationItem::where('operation_id', $order->id)->where('product_id', $this->productB->id)->firstOrFail();
        expect($newLine->quantity)->toBe('2.0000')
            ->and((float) $newLine->price)->toBe(250.0)
            ->and($this->productB->fresh()->stock_reserved)->toBe('2.0000');
    });

    test('rolls back every change when stock is insufficient or one product is invalid', function () {
        $this->productB->update(['stock' => 2, 'stock_reserved' => 0]);
        $order = editableOrder($this->store, $this->user, $this->customer);
        editableOrderLine($order, $this->productA, 10);

        updateEditableOrder($this->user, $order, [
            orderPayloadItem($this->productA, 12),
            orderPayloadItem($this->productB, 3),
        ])->assertStatus(422)->assertJsonValidationErrors('items');

        expect($this->productA->fresh()->stock_reserved)->toBe('10.0000')
            ->and((int) OperationItem::where('operation_id', $order->id)->sum('quantity'))->toBe(10);
    });

    test('requires the new total to remain at least the paid amount and rolls back otherwise', function () {
        $order = editableOrder($this->store, $this->user, $this->customer, ['total' => 1000]);
        editableOrderLine($order, $this->productA, 10);
        $paymentMethod = PaymentMethod::factory()->create();
        $storePaymentMethod = StorePaymentMethod::factory()->create([
            'store_id' => $this->store->id,
            'payment_method_id' => $paymentMethod->id,
        ]);
        OperationPayment::factory()->create([
            'operation_id' => $order->id,
            'store_payment_method_id' => $storePaymentMethod->id,
            'amount' => 600,
        ]);

        updateEditableOrder($this->user, $order, [orderPayloadItem($this->productA, 5)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');
        expect($this->productA->fresh()->stock_reserved)->toBe('10.0000')
            ->and((int) OperationItem::where('operation_id', $order->id)->sum('quantity'))->toBe(10);

        updateEditableOrder($this->user, $order, [orderPayloadItem($this->productA, 6)])->assertOk();
        expect((float) $order->fresh()->total)->toBe(600.0);
    });

    test('blocks terminal statuses and active extra sales but not completed allocations', function (string $status) {
        $order = editableOrder($this->store, $this->user, $this->customer, ['status' => $status]);
        editableOrderLine($order, $this->productA, 10);

        updateEditableOrder($this->user, $order, [orderPayloadItem($this->productA, 9)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');
    })->with(['delivered', 'cancelled', 'closed']);

    test('blocks active extra sales and permits historical allocations', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        editableOrderLine($order, $this->productA, 10);
        [$activeRoute, , $sourceItem] = editableOrderRouteItem($this->store, $this->user, $order, $this->productA, 'dispatched', 'completed', 2, 2);
        $destinationOrder = editableOrder($this->store, $this->user, $this->customer);
        [, $destinationStop, $destinationItem] = editableOrderRouteItem($this->store, $this->user, $destinationOrder, $this->productA, 'dispatched', 'pending', 1, 1);
        ExtraSaleAllocation::create([
            'store_id' => $this->store->id,
            'route_id' => $activeRoute->id,
            'source_stop_item_id' => $sourceItem->id,
            'destination_stop_id' => $destinationStop->id,
            'destination_stop_item_id' => $destinationItem->id,
            'quantity' => 1,
        ]);

        updateEditableOrder($this->user, $order, [orderPayloadItem($this->productA, 9)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');

        $activeRoute->update(['status' => 'completed']);
        updateEditableOrder($this->user, $order, [orderPayloadItem($this->productA, 9)])->assertOk();
    });

    test('rejects duplicated products, another store and non-order operations', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        editableOrderLine($order, $this->productA, 10);

        updateEditableOrder($this->user, $order, [
            orderPayloadItem($this->productA, 5),
            orderPayloadItem($this->productA, 5),
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');

        $sale = CommercialOperation::factory()->create([
            'store_id' => $this->store->id,
            'user_id' => $this->user->id,
            'type' => 'sale',
            'status' => 'confirmed',
        ]);
        updateEditableOrder($this->user, $sale, [orderPayloadItem($this->productA, 1)])->assertNotFound();

        $otherStore = Store::factory()->create();
        $otherOrder = editableOrder(
            $otherStore,
            User::factory()->create(['store_id' => $otherStore->id]),
            Customer::factory()->create(['store_id' => $otherStore->id])
        );
        updateEditableOrder($this->user, $otherOrder, [orderPayloadItem($this->productA, 1)])->assertNotFound();
    });

    test('requires the existing orders edit permission', function () {
        $order = editableOrder($this->store, $this->user, $this->customer);
        editableOrderLine($order, $this->productA, 10);
        $unauthorizedUser = User::factory()->create(['store_id' => $this->store->id]);
        $unauthorizedUser->assignRole('STORE_USER');

        updateEditableOrder($unauthorizedUser, $order, [orderPayloadItem($this->productA, 11)])
            ->assertForbidden();
    });
});
