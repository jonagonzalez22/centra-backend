<?php

use App\Models\Category;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'purchases.view', 'guard_name' => 'web']);

    $feature = Feature::factory()->create(['code' => 'purchases']);
    $plan = Plan::factory()->create();
    $plan->features()->attach($feature->id);
    $this->store = Store::factory()->create(['plan_id' => $plan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo('purchases.view');
    $this->category = Category::factory()->belongsToStore($this->store)->create();
    $this->product = Product::factory()->forStore($this->store)->create(['category_id' => $this->category->id]);
});

function recordPurchasePrice(
    Store $store,
    Supplier $supplier,
    User $user,
    Product $product,
    string $number,
    string $type,
    string $status,
    string $price,
    string $createdAt,
    ?string $orderedAt = null,
    ?string $receiptAt = null,
): Purchase {
    $purchase = Purchase::query()->create([
        'store_id' => $store->id,
        'supplier_id' => $supplier->id,
        'purchase_number' => $number,
        'purchase_type' => $type,
        'status' => $status,
        'supplier_business_name' => $supplier->business_name,
        'total' => $price,
        'ordered_at' => $orderedAt,
        'created_by' => $user->id,
    ]);
    $purchase->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

    PurchaseItem::query()->create([
        'purchase_id' => $purchase->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'product_sku' => $product->sku,
        'measurement_unit_code' => 'unit',
        'measurement_unit_symbol' => 'u',
        'quantity_ordered' => '1.0000',
        'quantity_received' => '0.0000',
        'unit_price' => $price,
        'subtotal' => $price,
    ]);

    if ($receiptAt !== null) {
        PurchaseReceipt::query()->create([
            'store_id' => $store->id,
            'purchase_id' => $purchase->id,
            'receipt_number' => 'REC-'.$number,
            'received_at' => $receiptAt,
            'created_by' => $user->id,
        ]);
    }

    return $purchase;
}

test('returns the five latest prices per historical supplier using frozen purchase data', function () {
    $supplierA = Supplier::query()->create(['store_id' => $this->store->id, 'business_name' => 'Proveedor histórico A']);
    $supplierB = Supplier::query()->create(['store_id' => $this->store->id, 'business_name' => 'Proveedor histórico B']);

    foreach (range(1, 6) as $day) {
        recordPurchasePrice(
            $this->store,
            $supplierA,
            $this->user,
            $this->product,
            'OC-A-0'.$day,
            'order',
            'ordered',
            (string) (100 + $day),
            "2026-10-0{$day} 09:00:00",
            "2026-10-0{$day} 10:00:00",
        );
    }
    recordPurchasePrice($this->store, $supplierA, $this->user, $this->product, 'OC-DRAFT', 'order', 'draft', '999.00', '2026-10-20 09:00:00');
    recordPurchasePrice($this->store, $supplierA, $this->user, $this->product, 'OC-CANCELLED', 'order', 'cancelled', '999.00', '2026-10-21 09:00:00');

    recordPurchasePrice($this->store, $supplierB, $this->user, $this->product, 'OC-B-CLOSED', 'direct', 'closed', '82.00', '2026-10-01 09:00:00', null, '2026-10-07 10:00:00');
    recordPurchasePrice($this->store, $supplierB, $this->user, $this->product, 'OC-B-PARTIAL', 'direct', 'partially_received', '86.00', '2026-10-02 09:00:00', null, '2026-10-08 10:00:00');
    recordPurchasePrice($this->store, $supplierB, $this->user, $this->product, 'OC-B-RECEIVED', 'direct', 'received', '88.00', '2026-10-03 09:00:00', null, '2026-10-09 10:00:00');

    $otherProduct = Product::factory()->forStore($this->store)->create(['category_id' => $this->category->id]);
    recordPurchasePrice($this->store, $supplierA, $this->user, $otherProduct, 'OC-OTHER-PRODUCT', 'order', 'ordered', '777.00', '2026-10-22 09:00:00', '2026-10-22 10:00:00');

    $response = $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/products/{$this->product->id}/purchase-price-history")
        ->assertOk()
        ->assertJsonPath('data.0.supplier.business_name', 'Proveedor histórico B')
        ->assertJsonCount(3, 'data.0.prices')
        ->assertJsonPath('data.0.prices.0.purchase_number', 'OC-B-RECEIVED')
        ->assertJsonPath('data.0.prices.0.date', '2026-10-09 10:00:00')
        ->assertJsonPath('data.0.prices.0.unit_price', '88.00')
        ->assertJsonPath('data.1.supplier.business_name', 'Proveedor histórico A')
        ->assertJsonCount(5, 'data.1.prices');

    expect(collect($response->json('data.1.prices'))->pluck('purchase_number')->all())
        ->toBe(['OC-A-06', 'OC-A-05', 'OC-A-04', 'OC-A-03', 'OC-A-02'])
        ->not->toContain('OC-A-01', 'OC-DRAFT', 'OC-CANCELLED', 'OC-OTHER-PRODUCT');
});

test('keeps history store-scoped and returns an empty collection when there are no confirmed prices', function () {
    $supplier = Supplier::query()->create(['store_id' => $this->store->id, 'business_name' => 'Proveedor local']);
    $otherPlan = Plan::factory()->create();
    $otherPlan->features()->attach(Feature::query()->where('code', 'purchases')->firstOrFail()->id);
    $otherStore = Store::factory()->create(['plan_id' => $otherPlan->id]);
    $otherCategory = Category::factory()->belongsToStore($otherStore)->create();
    $otherUser = User::factory()->create(['store_id' => $otherStore->id]);
    $otherSupplier = Supplier::query()->create(['store_id' => $otherStore->id, 'business_name' => 'Proveedor ajeno']);

    recordPurchasePrice($otherStore, $otherSupplier, $otherUser, $this->product, 'OC-FOREIGN', 'order', 'ordered', '999.00', '2026-10-20 09:00:00', '2026-10-20 10:00:00');
    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/products/{$this->product->id}/purchase-price-history")
        ->assertOk()
        ->assertJsonPath('data', []);

    $foreignProduct = Product::factory()->forStore($otherStore)->create(['category_id' => $otherCategory->id]);
    $this->actingAs($this->user, 'sanctum')
        ->getJson("/api/v1/store/products/{$foreignProduct->id}/purchase-price-history")
        ->assertNotFound();

    $withoutPurchasePermission = User::factory()->create(['store_id' => $this->store->id]);
    $withoutPurchasePermission->assignRole('STORE_ADMIN');
    $this->actingAs($withoutPurchasePermission, 'sanctum')
        ->getJson("/api/v1/store/products/{$this->product->id}/purchase-price-history")
        ->assertForbidden();
});
