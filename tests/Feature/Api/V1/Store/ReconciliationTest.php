<?php

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryDiscrepancy;
use App\Models\DeliveryRoute;
use App\Models\DeliveryRouteEvent;
use App\Models\Feature;
use App\Models\Locality;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Province;
use App\Models\RouteStop;
use App\Models\RouteStopCollection;
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
    Role::create(['name' => 'STORE_DRIVER', 'guard_name' => 'web']);

    foreach ([
        'logistics.routes.view',
        'logistics.routes.manage',
        'logistics.routes.reconcile',
    ] as $perm) {
        Permission::create(['name' => $perm, 'guard_name' => 'web']);
    }

    $this->store = Store::factory()->create();

    $plan = Plan::factory()->create();
    $deliveriesFeature = Feature::create(['code' => 'deliveries', 'name' => 'Entregas']);
    $plan->features()->attach($deliveriesFeature->id);
    $this->store->update(['plan_id' => $plan->id]);

    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo([
        'logistics.routes.view',
        'logistics.routes.manage',
        'logistics.routes.reconcile',
    ]);
    $this->token = $this->user->createToken('test-token')->plainTextToken;
});

// ── Helpers ──────────────────────────────────────────────────────────

function recVehicle(Store $store): Vehicle
{
    return Vehicle::factory()->forStore($store)->create(['is_active' => true]);
}

function recDriver(Store $store): User
{
    $driver = User::factory()->create(['store_id' => $store->id]);
    $driver->assignRole('STORE_DRIVER');
    return $driver;
}

function recCustomerWithAddress(Store $store): array
{
    $province = Province::factory()->create();
    $locality = Locality::factory()->for($province)->create();

    $customer = Customer::factory()->create(['store_id' => $store->id]);
    CustomerAddress::factory()->forCustomer($customer)->for($locality)->asMain()->create([
        'latitude' => -34.6037,
        'longitude' => -58.3816,
    ]);

    return [$customer, $locality];
}

function recEligibleOrder(Store $store, Customer $customer, array $attrs = []): \App\Models\CommercialOperation
{
    return \App\Models\CommercialOperation::factory()
        ->forStore($store)
        ->for($store->users()->first() ?? User::factory()->create(['store_id' => $store->id]), 'user')
        ->forCustomer($customer)
        ->order()
        ->create(array_merge(['status' => 'confirmed'], $attrs));
}

function recPaymentMethod(): PaymentMethod
{
    return PaymentMethod::factory()->create(['is_active' => true]);
}

function recStorePaymentMethod(Store $store, ?PaymentMethod $pm = null): StorePaymentMethod
{
    return StorePaymentMethod::factory()
        ->forStore($store)
        ->forPaymentMethod($pm ?? recPaymentMethod())
        ->create();
}

/**
 * Create a route in awaiting_reconciliation with completed stops,
 * delivered quantities, and items.
 */
function recRouteForReconciliation(Store $store, array $opts = []): array
{
    $vehicle = $opts['vehicle'] ?? recVehicle($store);
    $driver = $opts['driver'] ?? recDriver($store);
    [$customer, $locality] = recCustomerWithAddress($store);
    $product = Product::factory()->create([
        'store_id' => $store->id,
        'stock' => 100,
        'stock_reserved' => 0,
    ]);

    $date = now()->addDay()->format('Y-m-d');

    $order = recEligibleOrder($store, $customer, ['requested_delivery_date' => $date]);
    OperationItem::factory()->create([
        'operation_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 10,
        'price' => 100.00,
    ]);

    $route = DeliveryRoute::create([
        'store_id' => $store->id,
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'operational_date' => $date,
        'status' => 'awaiting_reconciliation',
    ]);

    $stop = RouteStop::create([
        'route_id' => $route->id,
        'order_id' => $order->id,
        'sequence' => 1,
        'status' => 'completed',
    ]);

    $routeStopItem = RouteStopItem::create([
        'route_stop_id' => $stop->id,
        'product_id' => $product->id,
        'quantity_planned' => 10,
        'quantity_loaded' => 10,
        'quantity_delivered' => $opts['quantity_delivered'] ?? 10,
    ]);

    return [$route, $stop, $product, $routeStopItem, $order, $customer];
}

/**
 * Create a RouteStopCollection for a stop.
 */
function recCreateCollection(RouteStop $stop, Store $store, array $attrs = []): RouteStopCollection
{
    $spm = recStorePaymentMethod($store);

    return RouteStopCollection::create(array_merge([
        'store_id' => $store->id,
        'route_stop_id' => $stop->id,
        'commercial_operation_id' => $stop->order_id,
        'store_payment_method_id' => $spm->id,
        'amount' => 100.00,
        'reference' => 'REF-001',
        'declared_by' => auth()->id() ?? $store->users()->first()?->id,
        'declared_at' => now(),
        'status' => 'declared',
    ], $attrs));
}

// ── Tests ────────────────────────────────────────────────────────────

// 1. reconciliation summary for awaiting_reconciliation route
test('reconciliation summary for awaiting_reconciliation route', function () {
    [$route, $stop, $product, $item, $order] = recRouteForReconciliation($this->store);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}/reconciliation");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.route_id', $route->id)
        ->assertJsonPath('data.status', 'awaiting_reconciliation')
        ->assertJsonPath('data.totals.declared_amount', 0)
        ->assertJsonPath('data.can_close', true); // all delivered, no discrepancies, no collections
});

// 2. reconciliation rejects route from another store
test('reconciliation rejects route from another store', function () {
    [$route] = recRouteForReconciliation($this->store);

    $otherStore = Store::factory()->create();
    $otherPlan = Plan::factory()->create();
    $deliveriesFeature = Feature::where('code', 'deliveries')->first();
    $otherPlan->features()->attach($deliveriesFeature->id);
    $otherStore->update(['plan_id' => $otherPlan->id]);

    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    $otherUser->assignRole('STORE_ADMIN');
    $otherUser->givePermissionTo(['logistics.routes.view', 'logistics.routes.reconcile']);
    $otherToken = $otherUser->createToken('test-token')->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer $otherToken")
        ->getJson("/api/v1/store/routes/{$route->id}/reconciliation");

    $response->assertStatus(404);
});

// 3. verify collection creates operation_payment
test('verify collection creates operation_payment', function () {
    [$route, $stop, $product, $item, $order] = recRouteForReconciliation($this->store);
    $collection = recCreateCollection($stop, $this->store, ['declared_by' => $this->user->id]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/verify");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'verified');

    $collection->refresh();
    expect($collection->status)->toBe('verified');
    expect($collection->operation_payment_id)->not->toBeNull();

    $payment = OperationPayment::find($collection->operation_payment_id);
    expect($payment)->not->toBeNull();
    expect((float) $payment->amount)->toBe(100.00);
    expect($payment->operation_id)->toBe($order->id);
});

// 4. verify collection associates with operation_payment_id
test('verify collection associates with operation_payment_id', function () {
    [$route, $stop, $product, $item, $order] = recRouteForReconciliation($this->store);
    $collection = recCreateCollection($stop, $this->store, ['declared_by' => $this->user->id]);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/verify")
        ->assertStatus(200);

    $collection->refresh();
    expect($collection->operation_payment_id)->not->toBeNull();
    expect($collection->verified_by)->toBe($this->user->id);
    expect($collection->verified_at)->not->toBeNull();

    // Verify operation_payment has payment_details with collection info
    $payment = OperationPayment::find($collection->operation_payment_id);
    expect($payment->payment_details['route_stop_collection_id'])->toBe($collection->id);
});

// 5. reject collection with reason
test('reject collection with reason', function () {
    [$route, $stop, $product, $item, $order] = recRouteForReconciliation($this->store);
    $collection = recCreateCollection($stop, $this->store, ['declared_by' => $this->user->id]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/reject", [
            'reason' => 'Monto incorrecto',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Monto incorrecto');

    $collection->refresh();
    expect($collection->status)->toBe('rejected');
    expect($collection->rejection_reason)->toBe('Monto incorrecto');
    expect($collection->verified_by)->toBe($this->user->id);
});

// 6. reject collection does NOT create operation_payment
test('reject collection does not create operation_payment', function () {
    [$route, $stop, $product, $item, $order] = recRouteForReconciliation($this->store);
    $collection = recCreateCollection($stop, $this->store, ['declared_by' => $this->user->id]);

    $beforeCount = OperationPayment::count();

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/reject", [
            'reason' => 'Falta comprobante',
        ])
        ->assertStatus(200);

    expect(OperationPayment::count())->toBe($beforeCount);

    $collection->refresh();
    expect($collection->operation_payment_id)->toBeNull();
});

// 7. verify collection fails if already verified
test('verify collection fails if already verified', function () {
    [$route, $stop, $product, $item, $order] = recRouteForReconciliation($this->store);
    $collection = recCreateCollection($stop, $this->store, ['declared_by' => $this->user->id]);

    // First verification
    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/verify")
        ->assertStatus(200);

    // Second verification should fail
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/verify");

    $response->assertStatus(422);
});

// 8. verify collection fails if already rejected
test('verify collection fails if already rejected', function () {
    [$route, $stop, $product, $item, $order] = recRouteForReconciliation($this->store);
    $collection = recCreateCollection($stop, $this->store, ['declared_by' => $this->user->id]);

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/reject", [
            'reason' => 'Monto incorrecto',
        ])
        ->assertStatus(200);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/verify");

    $response->assertStatus(422);
});

// 9. verify collection fails if amount exceeds pending_balance
test('verify collection fails if amount exceeds pending_balance', function () {
    [$route, $stop, $product, $item, $order] = recRouteForReconciliation($this->store);
    // Collection of 5000 when order total is ~1000 (10 * 100)
    $collection = recCreateCollection($stop, $this->store, [
        'declared_by' => $this->user->id,
        'amount' => 5000.00,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/verify");

    $response->assertStatus(422);
});

// 10. reconcile shows loaded vs delivered quantities
test('reconcile shows loaded vs delivered quantities', function () {
    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}/reconciliation");

    $response->assertStatus(200)
        ->assertJsonPath('data.stops.0.items.0.quantity_loaded', 10)
        ->assertJsonPath('data.stops.0.items.0.quantity_delivered', 10)
        ->assertJsonPath('data.stops.0.items.0.difference', 0);
});

// 11. reconcile calculates correct differences
test('reconcile calculates correct differences', function () {
    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store, ['quantity_delivered' => 7]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}/reconciliation");

    $response->assertStatus(200)
        ->assertJsonPath('data.stops.0.items.0.quantity_loaded', 10)
        ->assertJsonPath('data.stops.0.items.0.quantity_delivered', 7)
        ->assertJsonPath('data.stops.0.items.0.difference', 3)
        ->assertJsonPath('data.can_close', false); // unresolved discrepancy
});

// 12. resolve discrepancy for positive difference
test('resolve discrepancy for positive difference', function () {
    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store, ['quantity_delivered' => 6]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/discrepancies", [
            'route_stop_item_id' => $item->id,
            'resolution_type' => 'returned',
            'quantity_to_resolve' => 4,
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.route_stop_item_id', $item->id)
        ->assertJsonPath('data.resolution_type', 'returned')
        ->assertJsonPath('data.difference_quantity', 4); // 10 loaded - 6 delivered

    $discrepancy = DeliveryDiscrepancy::where('route_stop_item_id', $item->id)->first();
    expect($discrepancy)->not->toBeNull();
    expect($discrepancy->resolution_type)->toBe('returned');
});

// 13. resolve discrepancy fails for zero difference
test('resolve discrepancy fails for zero difference', function () {
    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/discrepancies", [
            'route_stop_item_id' => $item->id,
            'resolution_type' => 'returned',
            'quantity_to_resolve' => 1,
        ]);

    $response->assertStatus(422);
});

// 14. resolve discrepancy fails for quantity > difference
test('resolve discrepancy fails for quantity greater than difference', function () {
    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store, ['quantity_delivered' => 8]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/discrepancies", [
            'route_stop_item_id' => $item->id,
            'resolution_type' => 'returned',
            'quantity_to_resolve' => 5,
        ]);

    // diff is 2, but asking for 5
    $response->assertStatus(422);
});

// 15. finalize fails with declared collections pending
test('finalize fails with declared collections pending', function () {
    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store);
    recCreateCollection($stop, $this->store, ['declared_by' => $this->user->id]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/finalize-reconciliation");

    $response->assertStatus(422);
});

// 16. finalize fails with unresolved discrepancies
test('finalize fails with unresolved discrepancies', function () {
    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store, ['quantity_delivered' => 5]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/finalize-reconciliation");

    $response->assertStatus(422);
});

// 17. finalize succeeds and transitions to completed
test('finalize succeeds and transitions to completed', function () {
    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store, ['quantity_delivered' => 6]);
    // Resolve the discrepancy first
    DeliveryDiscrepancy::create([
        'route_stop_item_id' => $item->id,
        'product_id' => $product->id,
        'quantity_loaded' => 10,
        'quantity_delivered' => 6,
        'difference_quantity' => 4,
        'resolution_type' => 'returned',
        'resolved_by' => $this->user->id,
        'resolved_at' => now(),
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/finalize-reconciliation");

    $response->assertStatus(200)
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'completed');

    $route->refresh();
    expect($route->status)->toBe('completed');
    expect($route->processed_at)->not->toBeNull();
    expect($route->processed_by)->toBe($this->user->id);
});

// 18. finalize fails if route not awaiting_reconciliation
test('finalize fails if route not awaiting_reconciliation', function () {
    [$route] = recRouteForReconciliation($this->store);
    $route->update(['status' => 'dispatched']);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/finalize-reconciliation");

    $response->assertStatus(422);
});

// 19. finalize is idempotent (rejects second call)
test('finalize is idempotent rejects second call', function () {
    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store);

    // First call
    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/finalize-reconciliation")
        ->assertStatus(200);

    // Second call
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/finalize-reconciliation");

    $response->assertStatus(409);
});

// 20. verify collection rolls back on insufficient pending balance
test('verify collection rolls back and preserves original status', function () {
    [$route, $stop, $product, $item, $order] = recRouteForReconciliation($this->store);

    // Create payment that fully pays the order first (10 * 100 = 1000)
    $spm = recStorePaymentMethod($this->store);
    OperationPayment::create([
        'operation_id' => $order->id,
        'store_payment_method_id' => $spm->id,
        'amount' => 1000.00,
        'reference' => 'FULL-PAY',
    ]);

    // Now try to verify a collection — pending_balance should be 0
    $collection = recCreateCollection($stop, $this->store, [
        'declared_by' => $this->user->id,
        'amount' => 100.00,
    ]);

    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/verify");

    $response->assertStatus(422);

    // Collection should NOT be verified (transaction rolled back)
    $collection->refresh();
    expect($collection->status)->toBe('declared');
    expect($collection->operation_payment_id)->toBeNull();

    // No additional payment was created
    expect(OperationPayment::where('operation_id', $order->id)->count())->toBe(1);
});

// 21. completed route cannot be reconciled again
test('completed route cannot be reconciled again', function () {
    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store);

    // Finalize
    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/finalize-reconciliation")
        ->assertStatus(200);

    // Try to get reconciliation summary
    $response = $this->withHeader('Authorization', "Bearer $this->token")
        ->getJson("/api/v1/store/routes/{$route->id}/reconciliation");

    $response->assertStatus(422);
});

// 22. multi-tenant isolation on all endpoints
test('multi-tenant isolation on all endpoints', function () {
    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store);
    $collection = recCreateCollection($stop, $this->store, ['declared_by' => $this->user->id]);

    $otherStore = Store::factory()->create();
    $otherPlan = Plan::factory()->create();
    $deliveriesFeature = Feature::where('code', 'deliveries')->first();
    $otherPlan->features()->attach($deliveriesFeature->id);
    $otherStore->update(['plan_id' => $otherPlan->id]);

    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    $otherUser->assignRole('STORE_ADMIN');
    $otherUser->givePermissionTo(['logistics.routes.view', 'logistics.routes.reconcile']);
    $otherToken = $otherUser->createToken('test-token')->plainTextToken;

    // Verify collection
    $response = $this->withHeader('Authorization', "Bearer $otherToken")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/verify");
    $response->assertStatus(404); // collection scoped to store

    // Finalize
    $response = $this->withHeader('Authorization', "Bearer $otherToken")
        ->postJson("/api/v1/store/routes/{$route->id}/finalize-reconciliation");
    $response->assertStatus(404); // route scoped to store
});

// 23. no inventory movements created for discrepancies (resolution only)
test('no inventory movements created for discrepancies', function () {
    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store, ['quantity_delivered' => 6]);

    $beforeCount = \App\Models\InventoryMovement::count();

    $this->withHeader('Authorization', "Bearer $this->token")
        ->postJson("/api/v1/store/routes/{$route->id}/discrepancies", [
            'route_stop_item_id' => $item->id,
            'resolution_type' => 'returned',
            'quantity_to_resolve' => 4,
        ])
        ->assertStatus(200);

    expect(\App\Models\InventoryMovement::count())->toBe($beforeCount);
});

// 24. permission check for logistics.routes.reconcile
test('permission check for logistics routes reconcile', function () {
    // Create a user without the reconcile permission
    $userNoReconcile = User::factory()->create(['store_id' => $this->store->id]);
    $userNoReconcile->assignRole('STORE_ADMIN');
    $userNoReconcile->givePermissionTo(['logistics.routes.view', 'logistics.routes.manage']);
    $tokenNoReconcile = $userNoReconcile->createToken('test-token')->plainTextToken;

    [$route, $stop, $product, $item] = recRouteForReconciliation($this->store);
    $collection = recCreateCollection($stop, $this->store, ['declared_by' => $this->user->id]);

    // Verify collection should be denied (403)
    $this->withHeader('Authorization', "Bearer $tokenNoReconcile")
        ->postJson("/api/v1/store/routes/{$route->id}/collections/{$collection->id}/verify")
        ->assertStatus(403);

    // Finalize should be denied
    $this->withHeader('Authorization', "Bearer $tokenNoReconcile")
        ->postJson("/api/v1/store/routes/{$route->id}/finalize-reconciliation")
        ->assertStatus(403);

    // Resolve discrepancy should be denied
    $this->withHeader('Authorization', "Bearer $tokenNoReconcile")
        ->postJson("/api/v1/store/routes/{$route->id}/discrepancies", [
            'route_stop_item_id' => $item->id,
            'resolution_type' => 'returned',
            'quantity_to_resolve' => 1,
        ])
        ->assertStatus(403);
});
