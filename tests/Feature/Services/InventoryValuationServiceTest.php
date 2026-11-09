<?php

use App\Models\Category;
use App\Models\InventoryValuationBalance;
use App\Models\InventoryValuationEntry;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\InventoryValuationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function valuationSource(Store $store, string $stock = '0.0000'): Product
{
    return Product::factory()->create([
        'store_id' => $store->id,
        'category_id' => Category::factory()->belongsToStore($store)->create()->id,
        'stock' => $stock,
    ]);
}

test('keeps a historical weighted moving average across opening stock and receipts', function () {
    $store = Store::factory()->create();
    $user = User::factory()->create(['store_id' => $store->id]);
    $source = valuationSource($store, '10.0000');
    $service = app(InventoryValuationService::class);

    $service->recordInitialStock($source, '1000.00', $user);
    $source->update(['stock' => '20.0000']);
    $service->recordPurchaseReceipt($source->fresh(), (string) Str::uuid(), '10.0000', '1500.00', now(), $user);
    $source->update(['stock' => '30.0000']);
    $service->recordPurchaseReceipt($source->fresh(), (string) Str::uuid(), '10.0000', '1800.00', now(), $user);

    $balance = InventoryValuationBalance::sole();
    expect($balance->quantity)->toBe('30.0000')
        ->and($balance->total_value)->toBe('43000.000000')
        ->and($balance->average_unit_cost)->toBe('1433.333333')
        ->and($balance->cost_status)->toBe('known')
        ->and(InventoryValuationEntry::count())->toBe(3);
});

test('does not create a zero-quantity opening entry and product cost remains a manual reference', function () {
    $store = Store::factory()->create();
    $source = valuationSource($store);
    $service = app(InventoryValuationService::class);

    $service->recordInitialStock($source, '1000.00');
    $source->update(['cost' => '1500.00']);

    expect(InventoryValuationEntry::count())->toBe(0)
        ->and(InventoryValuationBalance::count())->toBe(0);
});

test('partial receipts create one immutable valuation entry per received quantity', function () {
    $store = Store::factory()->create();
    $source = valuationSource($store);
    $service = app(InventoryValuationService::class);
    $firstReceiptItem = (string) Str::uuid();
    $secondReceiptItem = (string) Str::uuid();

    $source->update(['stock' => '40.0000']);
    $service->recordPurchaseReceipt($source->fresh(), $firstReceiptItem, '40.0000', '1500.00', now(), null);
    $source->update(['stock' => '100.0000']);
    $service->recordPurchaseReceipt($source->fresh(), $secondReceiptItem, '60.0000', '1500.00', now(), null);
    // A retried receipt item is idempotent.
    $service->recordPurchaseReceipt($source->fresh(), $secondReceiptItem, '60.0000', '1500.00', now(), null);

    $balance = InventoryValuationBalance::sole();
    expect(InventoryValuationEntry::where('type', 'purchase_receipt')->count())->toBe(2)
        ->and($balance->quantity)->toBe('100.0000')
        ->and($balance->total_value)->toBe('150000.000000')
        ->and($balance->average_unit_cost)->toBe('1500.000000');
});

test('an output preserves the average and is idempotent by its physical reference', function () {
    $store = Store::factory()->create();
    $source = valuationSource($store, '20.0000');
    $service = app(InventoryValuationService::class);
    $service->recordInitialStock($source, '1250.00');
    $reference = (string) Str::uuid();
    $source->update(['stock' => '17.0000']);

    $service->recordOutput($source->fresh(), 'adjustment_output', 'inventory_movement', $reference, '3.0000', now(), null);
    $service->recordOutput($source->fresh(), 'adjustment_output', 'inventory_movement', $reference, '3.0000', now(), null);

    $balance = InventoryValuationBalance::sole();
    $output = InventoryValuationEntry::where('quantity', '<', 0)->sole();
    expect($balance->quantity)->toBe('17.0000')
        ->and($balance->total_value)->toBe('21250.000000')
        ->and($balance->average_unit_cost)->toBe('1250.000000')
        ->and($output->quantity)->toBe('-3.0000')
        ->and($output->total_cost)->toBe('-3750.000000');
});

test('unknown stock is carried explicitly and consumed before known valuation', function () {
    $store = Store::factory()->create();
    $source = valuationSource($store, '5.0000');
    $service = app(InventoryValuationService::class);
    $service->recordInitialStock($source, null);
    $source->update(['stock' => '10.0000']);
    $service->recordPurchaseReceipt($source->fresh(), (string) Str::uuid(), '5.0000', '100.00', now(), null);
    $source->update(['stock' => '4.0000']);
    $service->recordOutput($source->fresh(), 'sale_output', 'commercial_operation', (string) Str::uuid(), '6.0000', now(), null);

    $balance = InventoryValuationBalance::sole();
    expect($balance->quantity)->toBe('4.0000')
        ->and($balance->known_quantity)->toBe('4.0000')
        ->and($balance->unknown_quantity)->toBe('0.0000')
        ->and($balance->total_value)->toBe('400.000000')
        ->and($balance->cost_status)->toBe('known');
});

test('balances are isolated by store and source product', function () {
    $service = app(InventoryValuationService::class);
    $storeA = Store::factory()->create();
    $storeB = Store::factory()->create();
    $sourceA = valuationSource($storeA, '1.0000');
    $sourceB = valuationSource($storeB, '1.0000');

    $service->recordInitialStock($sourceA, '10.00');
    $service->recordInitialStock($sourceB, '20.00');

    expect(InventoryValuationBalance::where('store_id', $storeA->id)->sole()->total_value)->toBe('10.000000')
        ->and(InventoryValuationBalance::where('store_id', $storeB->id)->sole()->total_value)->toBe('20.000000');
});

test('preserves the original output cost across later receipts and its reversal', function () {
    $store = Store::factory()->create();
    $source = valuationSource($store, '10.0000');
    $service = app(InventoryValuationService::class);
    $saleId = (string) Str::uuid();

    $service->recordInitialStock($source, '1000.00');
    $source->update(['stock' => '8.0000']);
    $service->recordOutput($source->fresh(), 'sale_output', 'commercial_operation', $saleId, '2.0000', now(), null);
    $source->update(['stock' => '18.0000']);
    $service->recordPurchaseReceipt($source->fresh(), (string) Str::uuid(), '10.0000', '1500.00', now(), null);
    $source->update(['cost' => '2500.00']);
    $source->update(['stock' => '28.0000']);
    $service->recordPurchaseReceipt($source->fresh(), (string) Str::uuid(), '10.0000', '1800.00', now(), null);

    $beforeReversal = InventoryValuationBalance::sole();
    expect($beforeReversal->quantity)->toBe('28.0000')
        ->and($beforeReversal->total_value)->toBe('41000.000000')
        ->and($beforeReversal->average_unit_cost)->toBe('1464.285714');

    $source->update(['stock' => '30.0000']);
    $service->reverseOutput($source->fresh(), 'commercial_operation', $saleId, 'commercial_operation_cancellation', $saleId, '2.0000', now(), null);

    $balance = InventoryValuationBalance::sole();
    expect($balance->quantity)->toBe('30.0000')
        ->and($balance->total_value)->toBe('43000.000000')
        ->and($balance->average_unit_cost)->toBe('1433.333333')
        ->and($source->fresh()->cost)->toBe('2500.00');
});

test('shared-stock commercial children never receive their own valuation balance', function () {
    $store = Store::factory()->create();
    $source = valuationSource($store, '10.0000');
    $child = Product::factory()->create([
        'store_id' => $store->id,
        'category_id' => Category::factory()->belongsToStore($store)->create()->id,
        'stock' => '0.0000',
        'stock_reserved' => '0.0000',
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '5.0000',
    ]);
    $service = app(InventoryValuationService::class);
    $service->recordInitialStock($source, '1000.00');
    $source->update(['stock' => '5.0000']);

    // One commercial B consumes five physical units of A; valuation follows A.
    $service->recordOutput($source->fresh(), 'sale_output', 'commercial_operation', (string) Str::uuid(), '5.0000', now(), null);

    $balance = InventoryValuationBalance::where('stock_source_product_id', $source->id)->sole();
    expect(InventoryValuationBalance::where('stock_source_product_id', $child->id)->count())->toBe(0)
        ->and($balance->quantity)->toBe('5.0000')
        ->and($balance->total_value)->toBe('5000.000000')
        ->and($balance->average_unit_cost)->toBe('1000.000000');
});

test('delivery-time output remains frozen across an intervening receipt and later reconciliation', function () {
    $store = Store::factory()->create();
    $source = valuationSource($store, '10.0000');
    $service = app(InventoryValuationService::class);
    $service->recordInitialStock($source, '1000.00');

    // completeStop: physical consolidated stock is intentionally unchanged.
    $service->recordOutput($source->fresh(), 'delivery_output', 'route_stop_item', (string) Str::uuid(), '2.0000', now(), null, false);
    $afterDelivery = InventoryValuationBalance::sole();
    expect($afterDelivery->quantity)->toBe('8.0000')
        ->and($afterDelivery->total_value)->toBe('8000.000000')
        ->and($afterDelivery->average_unit_cost)->toBe('1000.000000');

    $source->update(['stock' => '20.0000']);
    $service->recordPurchaseReceipt($source->fresh(), (string) Str::uuid(), '10.0000', '2000.00', now(), null);
    $afterReceipt = InventoryValuationBalance::sole();
    expect($afterReceipt->quantity)->toBe('18.0000')
        ->and($afterReceipt->total_value)->toBe('28000.000000')
        ->and($afterReceipt->average_unit_cost)->toBe('1555.555555');

    // Reconciliation changes physical stock/reservations only; no second output.
    expect(InventoryValuationEntry::where('type', 'delivery_output')->count())->toBe(1);
});
