<?php

use App\Models\Category;
use App\Models\Feature;
use App\Models\InventoryMovement;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDocument;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    foreach (['purchases.view', 'purchases.create', 'purchases.edit', 'purchases.cancel'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $feature = Feature::factory()->create(['code' => 'purchases']);
    $plan = Plan::factory()->create();
    $plan->features()->attach($feature->id);
    $this->store = \App\Models\Store::factory()->create(['plan_id' => $plan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['purchases.view', 'purchases.create', 'purchases.edit', 'purchases.cancel']);
    $this->category = Category::factory()->belongsToStore($this->store)->create();
    $this->supplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Química Mendoza', 'tax_id' => '30-123']);
});

function purchasableProduct($store, $category, $supplier, array $attributes = []): Product
{
    $product = Product::factory()->forStore($store)->create(['category_id' => $category->id, ...$attributes]);
    SupplierProduct::create(['store_id' => $store->id, 'supplier_id' => $supplier->id, 'product_id' => $product->id, 'supplier_code' => 'EXT-'.$product->sku, 'supplier_product_name' => 'Nombre proveedor']);

    return $product;
}

function purchasePayload($supplier, $product, array $overrides = []): array
{
    return [...['supplier_id' => $supplier->id, 'purchase_type' => 'order', 'notes' => '  Compra inicial  ', 'items' => [['product_id' => $product->id, 'quantity' => '2.5000', 'unit_price' => '10.20']]], ...$overrides];
}

test('creates draft purchases with exact snapshots, totals, and no stock mutation', function () {
    $product = purchasableProduct($this->store, $this->category, $this->supplier, ['name' => 'Cloro granel', 'sku' => 'CL-001', 'stock' => '7.0000', 'is_sellable' => false]);
    $stock = $product->stock;

    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product))
        ->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.purchase_type', 'order')
        ->assertJsonPath('data.purchase_number', 'OC-000001')->assertJsonPath('data.supplier.business_name', 'Química Mendoza')
        ->assertJsonPath('data.items.0.product_name', 'Cloro granel')->assertJsonPath('data.items.0.product_sku', 'CL-001')
        ->assertJsonPath('data.items.0.supplier_code', 'EXT-CL-001')->assertJsonPath('data.items.0.quantity_ordered', '2.5000')
        ->assertJsonPath('data.items.0.quantity_received', '0.0000')->assertJsonPath('data.items.0.quantity_pending', '2.5000')
        ->assertJsonPath('data.items.0.subtotal', '25.50')->assertJsonPath('data.total', '25.50');

    expect($product->fresh()->stock)->toBe($stock)->and(InventoryMovement::count())->toBe(0);
});

test('allows direct drafts and multiple items without requiring a preferred supplier', function () {
    $first = purchasableProduct($this->store, $this->category, $this->supplier, ['is_sellable' => false]);
    $second = purchasableProduct($this->store, $this->category, $this->supplier);
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $first, ['purchase_type' => 'direct', 'items' => [['product_id' => $first->id, 'quantity' => '1', 'unit_price' => '5'], ['product_id' => $second->id, 'quantity' => '3', 'unit_price' => '2.50']]]))
        ->assertCreated()->assertJsonPath('data.purchase_type', 'direct')->assertJsonPath('data.total', '12.50')->assertJsonCount(2, 'data.items');
});

test('rejects invalid products, duplicate lines, injected state and receipt quantity', function () {
    $product = purchasableProduct($this->store, $this->category, $this->supplier);
    $child = Product::factory()->forStore($this->store)->create(['category_id' => $this->category->id, 'stock_source_product_id' => $product->id, 'stock_consumption_quantity' => '1.0000']);
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $child->id]);
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product, ['status' => 'received']))->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product, ['items' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1', 'quantity_received' => '1']]]))->assertUnprocessable()->assertJsonValidationErrors('items.0.quantity_received');
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product, ['items' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1'], ['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '1']]]))->assertUnprocessable()->assertJsonValidationErrors('items.1.product_id');
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $child))->assertUnprocessable();
});

test('updates only drafts by replacing lines and snapshots', function () {
    $first = purchasableProduct($this->store, $this->category, $this->supplier);
    $second = purchasableProduct($this->store, $this->category, $this->supplier, ['name' => 'Detergente']);
    $purchase = Purchase::query()->create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'purchase_number' => 'OC-000099', 'purchase_type' => 'order', 'status' => 'draft', 'supplier_business_name' => $this->supplier->business_name, 'total' => '0', 'created_by' => $this->user->id]);
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/purchases/{$purchase->id}", purchasePayload($this->supplier, $first, ['purchase_type' => 'direct', 'items' => [['product_id' => $second->id, 'quantity' => '4', 'unit_price' => '2.25']]]))
        ->assertOk()->assertJsonPath('data.purchase_type', 'direct')->assertJsonPath('data.total', '9.00')->assertJsonPath('data.items.0.product_name', 'Detergente');
    $purchase->update(['status' => 'ordered']);
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/purchases/{$purchase->id}", purchasePayload($this->supplier, $first))->assertUnprocessable();
});

test('lists and scopes purchases with permissions and feature', function () {
    $product = purchasableProduct($this->store, $this->category, $this->supplier);
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product))->assertCreated();
    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/purchases?search=OC-000001&purchase_type=order')->assertOk()->assertJsonCount(1, 'data.items');
    $viewOnly = User::factory()->create(['store_id' => $this->store->id]);
    $viewOnly->assignRole('STORE_ADMIN');
    $viewOnly->givePermissionTo('purchases.view');
    $this->actingAs($viewOnly, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product))->assertForbidden();
});

test('lists document counts and filters confirmed purchases by document presence', function () {
    $makePurchase = function (string $number, string $status): Purchase {
        return Purchase::query()->create([
            'store_id' => $this->store->id,
            'supplier_id' => $this->supplier->id,
            'purchase_number' => $number,
            'purchase_type' => 'order',
            'status' => $status,
            'supplier_business_name' => $this->supplier->business_name,
            'total' => '10.00',
            'created_by' => $this->user->id,
        ]);
    };
    $draft = $makePurchase('OC-DRAFT', 'draft');
    $withoutDocuments = $makePurchase('OC-WITHOUT', 'ordered');
    $withOneDocument = $makePurchase('OC-ONE', 'received');
    $cancelledWithDocuments = $makePurchase('OC-CANCELLED-WITH', 'cancelled');
    $cancelledWithoutDocuments = $makePurchase('OC-CANCELLED-WITHOUT', 'cancelled');

    foreach ([$withOneDocument, $cancelledWithDocuments, $cancelledWithDocuments] as $index => $purchase) {
        PurchaseDocument::query()->create([
            'store_id' => $this->store->id,
            'purchase_id' => $purchase->id,
            'type' => 'invoice',
            'number' => 'DOC-'.($index + 1),
            'issued_at' => '2026-10-24',
            'total_amount' => '1.00',
            'created_by' => $this->user->id,
        ]);
    }

    $items = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/purchases')
        ->assertOk()->json('data.items');
    expect(collect($items)->firstWhere('id', $draft->id)['documents_count'])->toBe(0)
        ->and(collect($items)->firstWhere('id', $withOneDocument->id)['documents_count'])->toBe(1)
        ->and(collect($items)->firstWhere('id', $cancelledWithDocuments->id)['documents_count'])->toBe(2);

    $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/purchases?documents=with_documents')
        ->assertOk()->assertJsonCount(2, 'data.items');
    $without = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/store/purchases?documents=without_documents')
        ->assertOk()->json('data.items');
    expect(collect($without)->pluck('id')->all())->toEqualCanonicalizing([$withoutDocuments->id, $cancelledWithoutDocuments->id]);
});

test('rejects inactive and foreign suppliers or products and keeps purchases store scoped', function () {
    $product = purchasableProduct($this->store, $this->category, $this->supplier);
    $this->supplier->update(['is_active' => false]);
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product))
        ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    $this->supplier->update(['is_active' => true]);

    $otherPlan = Plan::factory()->create();
    $otherPlan->features()->attach(Feature::query()->where('code', 'purchases')->firstOrFail()->id);
    $otherStore = \App\Models\Store::factory()->create(['plan_id' => $otherPlan->id]);
    $otherCategory = Category::factory()->belongsToStore($otherStore)->create();
    $otherSupplier = Supplier::create(['store_id' => $otherStore->id, 'business_name' => 'Ajeno']);
    $foreignProduct = purchasableProduct($otherStore, $otherCategory, $otherSupplier);
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($otherSupplier, $product))->assertUnprocessable();
    $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $foreignProduct))->assertUnprocessable();
});

test('blocks the purchases endpoints when the feature is disabled', function () {
    $disabledStore = \App\Models\Store::factory()->create(['plan_id' => Plan::factory()->create()->id]);
    $disabledUser = User::factory()->create(['store_id' => $disabledStore->id]);
    $disabledUser->assignRole('STORE_ADMIN');
    $disabledUser->givePermissionTo(['purchases.view', 'purchases.create', 'purchases.edit', 'purchases.cancel']);
    $disabledCategory = Category::factory()->belongsToStore($disabledStore)->create();
    $disabledSupplier = Supplier::create(['store_id' => $disabledStore->id, 'business_name' => 'Sin feature']);
    $disabledProduct = purchasableProduct($disabledStore, $disabledCategory, $disabledSupplier);
    $this->actingAs($disabledUser, 'sanctum')->getJson('/api/v1/store/purchases')->assertForbidden();
    $this->actingAs($disabledUser, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($disabledSupplier, $disabledProduct))->assertForbidden();
});

test('orders only planned drafts, refreshes snapshots, and never mutates stock', function () {
    $product = purchasableProduct($this->store, $this->category, $this->supplier, ['name' => 'Nombre draft', 'sku' => 'DRAFT-1', 'stock' => '9.0000', 'is_sellable' => false]);
    $created = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product))->json('data.id');
    $product->update(['name' => 'Nombre ordenado', 'sku' => 'ORD-1']);
    SupplierProduct::query()->where('supplier_id', $this->supplier->id)->where('product_id', $product->id)->update(['supplier_code' => 'ORD-CODE', 'supplier_product_name' => 'Nombre ordenado proveedor']);
    $stock = $product->fresh()->stock;

    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$created}/order")
        ->assertOk()->assertJsonPath('data.status', 'ordered')
        ->assertJsonPath('data.items.0.product_name', 'Nombre ordenado')->assertJsonPath('data.items.0.product_sku', 'ORD-1')
        ->assertJsonPath('data.items.0.supplier_code', 'ORD-CODE')->assertJsonPath('data.items.0.quantity_ordered', '2.5000')
        ->assertJsonPath('data.items.0.unit_price', '10.20')->assertJsonPath('data.total', '25.50');
    expect(Purchase::findOrFail($created)->ordered_at)->not->toBeNull()
        ->and($product->fresh()->stock)->toBe($stock)
        ->and(InventoryMovement::count())->toBe(0);
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/purchases/{$created}", purchasePayload($this->supplier, $product))->assertUnprocessable();
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$created}/order")->assertUnprocessable();
});

test('blocks order for direct drafts and stale supplier, product, or association', function () {
    $product = purchasableProduct($this->store, $this->category, $this->supplier);
    $direct = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product, ['purchase_type' => 'direct']))->json('data.id');
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$direct}/order")->assertUnprocessable();
    $draft = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product))->json('data.id');
    SupplierProduct::query()->where('supplier_id', $this->supplier->id)->where('product_id', $product->id)->delete();
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$draft}/order")->assertUnprocessable();
    SupplierProduct::create(['store_id' => $this->store->id, 'supplier_id' => $this->supplier->id, 'product_id' => $product->id]);
    $product->update(['is_active' => false]);
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$draft}/order")->assertUnprocessable();
});

test('cancels drafts and orders without deleting history or mutating stock', function () {
    $product = purchasableProduct($this->store, $this->category, $this->supplier, ['stock' => '11.0000']);
    $draft = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product))->json('data.id');
    $number = Purchase::findOrFail($draft)->purchase_number;
    $stock = $product->stock;
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$draft}/cancel")
        ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.purchase_number', $number)
        ->assertJsonPath('data.items.0.quantity_received', '0.0000');
    expect(Purchase::findOrFail($draft)->cancelled_at)->not->toBeNull()
        ->and($product->fresh()->stock)->toBe($stock)
        ->and(InventoryMovement::count())->toBe(0);
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$draft}/cancel")->assertUnprocessable();

    $ordered = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product))->json('data.id');
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$ordered}/order")->assertOk();
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$ordered}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
});

test('revalidates active supplier and protects lifecycle actions by permission and feature', function () {
    $product = purchasableProduct($this->store, $this->category, $this->supplier);
    $draft = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/store/purchases', purchasePayload($this->supplier, $product))->json('data.id');
    $this->supplier->update(['is_active' => false]);
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$draft}/order")->assertUnprocessable();
    $withoutCancel = User::factory()->create(['store_id' => $this->store->id]);
    $withoutCancel->assignRole('STORE_ADMIN');
    $withoutCancel->givePermissionTo('purchases.edit');
    $this->actingAs($withoutCancel, 'sanctum')->postJson("/api/v1/store/purchases/{$draft}/cancel")->assertForbidden();

    $disabledStore = \App\Models\Store::factory()->create(['plan_id' => Plan::factory()->create()->id]);
    $disabledUser = User::factory()->create(['store_id' => $disabledStore->id]);
    $disabledUser->assignRole('STORE_ADMIN');
    $disabledUser->givePermissionTo(['purchases.edit', 'purchases.cancel']);
    $disabledPurchase = Purchase::create(['store_id' => $disabledStore->id, 'supplier_id' => Supplier::create(['store_id' => $disabledStore->id, 'business_name' => 'Sin feature'])->id, 'purchase_number' => 'OC-000001', 'purchase_type' => 'order', 'status' => 'draft', 'supplier_business_name' => 'Sin feature', 'total' => '0', 'created_by' => $disabledUser->id]);
    $this->actingAs($disabledUser, 'sanctum')->postJson("/api/v1/store/purchases/{$disabledPurchase->id}/cancel")->assertForbidden();
});
