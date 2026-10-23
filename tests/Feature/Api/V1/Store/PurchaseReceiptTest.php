<?php

use App\Models\Category;
use App\Models\Feature;
use App\Models\InventoryMovement;
use App\Models\MeasurementUnit;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptItem;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'STORE_ADMIN', 'guard_name' => 'web']);
    foreach (['purchases.view', 'purchases.create', 'purchases.edit', 'purchases.receive', 'purchases.close', 'products.edit'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }
    $feature = Feature::factory()->create(['code' => 'purchases']);
    $plan = Plan::factory()->create();
    $plan->features()->attach($feature->id);
    $this->store = \App\Models\Store::factory()->create(['plan_id' => $plan->id]);
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->user->assignRole('STORE_ADMIN');
    $this->user->givePermissionTo(['purchases.view', 'purchases.create', 'purchases.edit', 'purchases.receive', 'purchases.close', 'products.edit']);
    $this->category = Category::factory()->belongsToStore($this->store)->create();
    $this->supplier = Supplier::create(['store_id' => $this->store->id, 'business_name' => 'Proveedor recepción']);
});

function receiptProduct($store, $category, $supplier, array $attributes = []): Product
{
    $product = Product::factory()->forStore($store)->create(['category_id' => $category->id, 'stock' => '10.0000', ...$attributes]);
    SupplierProduct::create([
        'store_id' => $store->id,
        'supplier_id' => $supplier->id,
        'product_id' => $product->id,
        'supplier_code' => 'SUP-'.$product->sku,
        'supplier_product_name' => 'Nombre proveedor '.$product->name,
    ]);

    return $product;
}

function receiptPurchase($store, $supplier, $user, array $products, string $type = 'order', string $status = 'ordered'): Purchase
{
    $purchase = Purchase::create([
        'store_id' => $store->id,
        'supplier_id' => $supplier->id,
        'purchase_number' => 'OC-'.str_pad((string) (Purchase::count() + 1), 6, '0', STR_PAD_LEFT),
        'purchase_type' => $type,
        'status' => $status,
        'supplier_business_name' => $supplier->business_name,
        'total' => '0.00',
        'created_by' => $user->id,
    ]);
    foreach ($products as [$product, $quantity]) {
        $product->load('stockMeasurementUnit');
        PurchaseItem::create([
            'purchase_id' => $purchase->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'supplier_code' => 'SUP-'.$product->sku,
            'supplier_product_name' => 'Nombre proveedor '.$product->name,
            'measurement_unit_code' => $product->stockMeasurementUnit->code,
            'measurement_unit_symbol' => $product->stockMeasurementUnit->symbol,
            'quantity_ordered' => $quantity,
            'quantity_received' => '0.0000',
            'unit_price' => '10.00',
            'subtotal' => '10.00',
        ]);
    }

    return $purchase->fresh('items');
}

function receiptPayload(PurchaseItem $item, string $quantity, array $overrides = []): array
{
    return [...['items' => [['purchase_item_id' => $item->id, 'quantity' => $quantity]], 'notes' => 'Recepción parcial'], ...$overrides];
}

test('receives ordered purchases partially and fully with exact stock and movement traceability', function () {
    $first = receiptProduct($this->store, $this->category, $this->supplier, ['name' => 'Harina', 'stock' => '10.0000']);
    $second = receiptProduct($this->store, $this->category, $this->supplier, ['name' => 'Azúcar', 'stock' => '3.0000']);
    $purchase = receiptPurchase($this->store, $this->supplier, $this->user, [[$first, '5.0000'], [$second, '2.5000']]);
    $firstItem = $purchase->items->firstWhere('product_id', $first->id);
    $secondItem = $purchase->items->firstWhere('product_id', $second->id);

    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$purchase->id}/receipts", [
        'items' => [
            ['purchase_item_id' => $firstItem->id, 'quantity' => '2.5000'],
            ['purchase_item_id' => $secondItem->id, 'quantity' => '2.5000'],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.receipt_number', 'REC-000001')
        ->assertJsonPath('data.items.0.quantity_received', '2.5000');

    expect($purchase->fresh()->status)->toBe('partially_received')
        ->and($firstItem->fresh()->quantity_received)->toBe('2.5000')
        ->and($secondItem->fresh()->quantity_received)->toBe('2.5000')
        ->and($first->fresh()->stock)->toBe('12.5000')
        ->and($first->fresh()->stock_reserved)->toBe('0.0000')
        ->and($second->fresh()->stock)->toBe('5.5000');

    $movement = InventoryMovement::query()->where('product_id', $first->id)->firstOrFail();
    expect($movement->type)->toBe('input')
        ->and($movement->quantity)->toBe('2.5000')
        ->and($movement->previous_stock)->toBe('10.0000')
        ->and($movement->current_stock)->toBe('12.5000')
        ->and($movement->reference_type)->toBe('purchase_receipt')
        ->and($movement->reference_id)->toBe(PurchaseReceipt::firstOrFail()->id);

    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$purchase->id}/receipts", receiptPayload($firstItem->fresh(), '2.5000'))
        ->assertCreated()->assertJsonPath('data.receipt_number', 'REC-000002');

    expect($purchase->fresh()->status)->toBe('received')
        ->and($firstItem->fresh()->quantity_received)->toBe('5.0000')
        ->and(InventoryMovement::query()->where('product_id', $first->id)->count())->toBe(2);
});

test('rejects non receivable states, over receipts, foreign items, and rolls back the entire receipt', function () {
    $first = receiptProduct($this->store, $this->category, $this->supplier, ['name' => 'A']);
    $second = receiptProduct($this->store, $this->category, $this->supplier, ['name' => 'B']);
    $draft = receiptPurchase($this->store, $this->supplier, $this->user, [[$first, '2.0000']], 'order', 'draft');
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$draft->id}/receipts", receiptPayload($draft->items->first(), '1'))->assertUnprocessable();

    $purchase = receiptPurchase($this->store, $this->supplier, $this->user, [[$first, '2.0000'], [$second, '1.0000']]);
    $firstItem = $purchase->items->firstWhere('product_id', $first->id);
    $secondItem = $purchase->items->firstWhere('product_id', $second->id);
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$purchase->id}/receipts", [
        'items' => [
            ['purchase_item_id' => $firstItem->id, 'quantity' => '1.0000'],
            ['purchase_item_id' => $secondItem->id, 'quantity' => '1.0001'],
        ],
    ])->assertUnprocessable();

    expect(PurchaseReceipt::count())->toBe(0)
        ->and(InventoryMovement::count())->toBe(0)
        ->and($first->fresh()->stock)->toBe('10.0000')
        ->and($second->fresh()->stock)->toBe('10.0000')
        ->and($firstItem->fresh()->quantity_received)->toBe('0.0000')
        ->and($purchase->fresh()->status)->toBe('ordered');

    $foreignPurchase = receiptPurchase($this->store, $this->supplier, $this->user, [[$first, '1.0000']]);
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$purchase->id}/receipts", receiptPayload($foreignPurchase->items->first(), '1'))->assertUnprocessable();
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$purchase->id}/receipts", receiptPayload($firstItem, '0'))->assertUnprocessable();
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$purchase->id}/receipts", receiptPayload($firstItem, '-0.0001'))->assertUnprocessable();
});

test('first direct receipt refreshes snapshots then freezes the purchase for later receipts', function () {
    $product = receiptProduct($this->store, $this->category, $this->supplier, ['name' => 'Nombre draft', 'sku' => 'DIR-OLD']);
    $purchase = receiptPurchase($this->store, $this->supplier, $this->user, [[$product, '3.0000']], 'direct', 'draft');
    $item = $purchase->items->first();
    $product->update(['name' => 'Nombre congelado', 'sku' => 'DIR-NEW']);
    SupplierProduct::query()->where('supplier_id', $this->supplier->id)->where('product_id', $product->id)->update(['supplier_code' => 'DIR-CODE', 'supplier_product_name' => 'Nombre proveedor congelado']);

    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$purchase->id}/receipts", receiptPayload($item, '1.0000'))
        ->assertCreated()->assertJsonPath('data.items.0.product_name', 'Nombre congelado')
        ->assertJsonPath('data.items.0.product_sku', 'DIR-NEW');

    expect($purchase->fresh()->status)->toBe('partially_received')
        ->and($item->fresh()->product_name)->toBe('Nombre congelado')
        ->and($item->fresh()->supplier_code)->toBe('DIR-CODE');
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/purchases/{$purchase->id}", [])->assertUnprocessable();
    $product->update(['is_active' => false]);
    SupplierProduct::query()->where('supplier_id', $this->supplier->id)->where('product_id', $product->id)->delete();
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$purchase->id}/receipts", receiptPayload($item->fresh(), '2.0000'))->assertCreated();
    expect($purchase->fresh()->status)->toBe('received')->and($product->fresh()->stock)->toBe('13.0000');
});

test('purchase detail includes immutable receipt history and no receipt mutation routes are exposed', function () {
    $product = receiptProduct($this->store, $this->category, $this->supplier);
    $purchase = receiptPurchase($this->store, $this->supplier, $this->user, [[$product, '1.0000']]);
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$purchase->id}/receipts", receiptPayload($purchase->items->first(), '1'))->assertCreated();

    $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/store/purchases/{$purchase->id}")
        ->assertOk()->assertJsonPath('data.receipts.0.receipt_number', 'REC-000001')
        ->assertJsonPath('data.receipts.0.items.0.product_id', $product->id);
    $receipt = PurchaseReceipt::firstOrFail();
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/purchases/{$purchase->id}/receipts/{$receipt->id}", [])->assertNotFound();
    $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/store/purchases/{$purchase->id}/receipts/{$receipt->id}")->assertNotFound();
});

test('receipts require purchases.receive and reject cancelled, received, and closed purchases', function () {
    $product = receiptProduct($this->store, $this->category, $this->supplier);
    foreach (['cancelled', 'received', 'closed'] as $status) {
        $purchase = receiptPurchase($this->store, $this->supplier, $this->user, [[$product, '1.0000']], 'order', $status);
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/store/purchases/{$purchase->id}/receipts", receiptPayload($purchase->items->first(), '1'))
            ->assertUnprocessable();
    }

    $purchase = receiptPurchase($this->store, $this->supplier, $this->user, [[$product, '1.0000']]);
    $withoutReceive = User::factory()->create(['store_id' => $this->store->id]);
    $withoutReceive->assignRole('STORE_ADMIN');
    $withoutReceive->givePermissionTo('purchases.view');
    $this->actingAs($withoutReceive, 'sanctum')
        ->postJson("/api/v1/store/purchases/{$purchase->id}/receipts", receiptPayload($purchase->items->first(), '1'))
        ->assertForbidden();
});

test('closes partially received order and direct purchases without physical side effects', function () {
    foreach (['order', 'direct'] as $type) {
        $product = receiptProduct($this->store, $this->category, $this->supplier, ['stock' => '10.0000']);
        $purchase = receiptPurchase($this->store, $this->supplier, $this->user, [[$product, '10.0000']], $type, 'partially_received');
        $item = $purchase->items->first();
        $item->update(['quantity_received' => '8.0000']);
        $receiptCount = PurchaseReceipt::count();
        $receiptItemCount = PurchaseReceiptItem::count();
        $movementCount = InventoryMovement::count();

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$purchase->id}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.items.0.quantity_received', '8.0000')
            ->assertJsonPath('data.items.0.quantity_pending', '2.0000');

        expect($purchase->fresh()->status)->toBe('closed')
            ->and($purchase->fresh()->closed_at)->not->toBeNull()
            ->and($purchase->fresh()->closed_by)->toBe($this->user->id)
            ->and($item->fresh()->quantity_ordered)->toBe('10.0000')
            ->and($item->fresh()->quantity_received)->toBe('8.0000')
            ->and($product->fresh()->stock)->toBe('10.0000')
            ->and($product->fresh()->stock_reserved)->toBe('0.0000')
            ->and(PurchaseReceipt::count())->toBe($receiptCount)
            ->and(PurchaseReceiptItem::count())->toBe($receiptItemCount)
            ->and(InventoryMovement::count())->toBe($movementCount);

        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$purchase->id}/receipts", receiptPayload($item, '2.0000'))
            ->assertUnprocessable();
        expect($product->fresh()->stock)->toBe('10.0000')->and(InventoryMovement::count())->toBe($movementCount);
    }
});

test('only partially received purchases with a historical pending quantity can close', function () {
    $product = receiptProduct($this->store, $this->category, $this->supplier);
    foreach (['draft', 'ordered', 'received', 'closed', 'cancelled'] as $status) {
        $purchase = receiptPurchase($this->store, $this->supplier, $this->user, [[$product, '2.0000']], 'order', $status);
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$purchase->id}/close")->assertUnprocessable();
    }
    $complete = receiptPurchase($this->store, $this->supplier, $this->user, [[$product, '2.0000']], 'order', 'partially_received');
    $complete->items->first()->update(['quantity_received' => '2.0000']);
    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/store/purchases/{$complete->id}/close")
        ->assertUnprocessable()
        ->assertJsonPath('errors.purchase.0', 'No se puede cerrar una compra sin cantidades pendientes de recibir.');

    $withoutClose = User::factory()->create(['store_id' => $this->store->id]);
    $withoutClose->assignRole('STORE_ADMIN');
    $withoutClose->givePermissionTo('purchases.view');
    $partial = receiptPurchase($this->store, $this->supplier, $this->user, [[$product, '2.0000']], 'order', 'partially_received');
    $partial->items->first()->update(['quantity_received' => '1.0000']);
    $this->actingAs($withoutClose, 'sanctum')->postJson("/api/v1/store/purchases/{$partial->id}/close")->assertForbidden();
});

test('pending ordered and partially received purchases block UOM changes while draft and received purchases do not', function () {
    $unit = MeasurementUnit::query()->where('code', 'kg')->firstOrFail();
    $orderedProduct = receiptProduct($this->store, $this->category, $this->supplier, ['stock' => '0.0000']);
    receiptPurchase($this->store, $this->supplier, $this->user, [[$orderedProduct, '2.0000']], 'order', 'ordered');
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/products/{$orderedProduct->id}", [
        'stock_measurement_unit_id' => $unit->id,
        'sale_quantity_step' => '0.2500',
    ])->assertUnprocessable()->assertJsonPath('errors.stock_measurement_unit_id.0', 'No se puede cambiar la unidad de medida porque el producto tiene cantidades pendientes de recibir en una compra.');

    $partialProduct = receiptProduct($this->store, $this->category, $this->supplier, ['stock' => '0.0000']);
    $partialPurchase = receiptPurchase($this->store, $this->supplier, $this->user, [[$partialProduct, '2.0000']], 'direct', 'partially_received');
    $partialPurchase->items->first()->update(['quantity_received' => '1.0000']);
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/products/{$partialProduct->id}", [
        'stock_measurement_unit_id' => $unit->id,
        'sale_quantity_step' => '0.2500',
    ])->assertUnprocessable()->assertJsonValidationErrors('stock_measurement_unit_id');

    $draftProduct = receiptProduct($this->store, $this->category, $this->supplier, ['stock' => '0.0000']);
    receiptPurchase($this->store, $this->supplier, $this->user, [[$draftProduct, '2.0000']], 'direct', 'draft');
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/products/{$draftProduct->id}", [
        'stock_measurement_unit_id' => $unit->id,
        'sale_quantity_step' => '0.2500',
    ])->assertOk();

    $receivedProduct = receiptProduct($this->store, $this->category, $this->supplier, ['stock' => '0.0000']);
    $receivedPurchase = receiptPurchase($this->store, $this->supplier, $this->user, [[$receivedProduct, '2.0000']], 'order', 'received');
    $receivedPurchase->items->first()->update(['quantity_received' => '2.0000']);
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/products/{$receivedProduct->id}", [
        'stock_measurement_unit_id' => $unit->id,
        'sale_quantity_step' => '0.2500',
    ])->assertOk();

    $closedProduct = receiptProduct($this->store, $this->category, $this->supplier, ['stock' => '0.0000']);
    $closedPurchase = receiptPurchase($this->store, $this->supplier, $this->user, [[$closedProduct, '2.0000']], 'order', 'closed');
    $closedPurchase->items->first()->update(['quantity_received' => '1.0000']);
    $this->actingAs($this->user, 'sanctum')->putJson("/api/v1/store/products/{$closedProduct->id}", [
        'stock_measurement_unit_id' => $unit->id,
        'sale_quantity_step' => '0.2500',
    ])->assertOk();
});
