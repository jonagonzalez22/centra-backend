<?php

use App\Models\Category;
use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\CancelSaleService;
use App\Services\CommercialOperationService;
use App\Services\OrderItemEditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
    $this->category = Category::factory()->create(['store_id' => $this->store->id]);
});

function associatedCoreProduct(object $test, array $attributes = []): Product
{
    return Product::factory()->create([
        'store_id' => $test->store->id,
        'category_id' => $test->category->id,
        'stock' => '0.0000',
        'stock_reserved' => '0.0000',
        'stock_min' => '0.0000',
        'sale_quantity_step' => '1.0000',
        ...$attributes,
    ]);
}

function associatedCoreChild(object $test, Product $source, string $factor, array $attributes = []): Product
{
    return associatedCoreProduct($test, [
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => $factor,
        ...$attributes,
    ]);
}

function createAssociatedCoreOperation(object $test, string $type, array $items): CommercialOperation
{
    return app(CommercialOperationService::class)->create([
        'type' => $type,
        'customer_id' => $test->customer->id,
        'requested_delivery_date' => $type === 'order' ? now()->addDay()->toDateString() : null,
        'items' => $items,
        'payments' => [],
    ], $test->store->id, $test->user->id);
}

test('a sale keeps the child commercial line and aggregates physical consumption with its direct source', function () {
    $source = associatedCoreProduct($this, ['stock' => '20.0000', 'price' => '10.00']);
    $child = associatedCoreChild($this, $source, '5.0000', ['price' => '2000.00']);

    $sale = createAssociatedCoreOperation($this, 'sale', [
        ['product_id' => $child->id, 'quantity' => '2.0000', 'price' => '2000.00'],
        ['product_id' => $source->id, 'quantity' => '4.0000', 'price' => '10.00'],
    ]);

    expect($source->fresh()->stock)->toBe('6.0000')
        ->and($child->fresh()->stock)->toBe('0.0000')
        ->and($child->fresh()->stock_reserved)->toBe('0.0000');

    $childLine = $sale->items()->where('product_id', $child->id)->sole();
    $sourceLine = $sale->items()->where('product_id', $source->id)->sole();

    expect($childLine->product_id)->toBe($child->id)
        ->and($childLine->stock_source_product_id)->toBe($source->id)
        ->and($childLine->stock_consumption_quantity)->toBe('5.0000')
        ->and($sourceLine->stock_source_product_id)->toBeNull()
        ->and($sourceLine->stock_consumption_quantity)->toBeNull();
});

test('order cancellation releases a normal product reservation without restoring physical stock', function () {
    $product = associatedCoreProduct($this, ['stock' => '3.0000']);

    $order = createAssociatedCoreOperation($this, 'order', [
        ['product_id' => $product->id, 'quantity' => '2.0000', 'price' => '10.00'],
    ]);

    expect($product->fresh()->stock)->toBe('3.0000')
        ->and($product->fresh()->stock_reserved)->toBe('2.0000');

    app(CommercialOperationService::class)->cancel(
        $order,
        'customer_cancelled',
        null,
        $this->user,
    );

    expect($product->fresh()->stock)->toBe('3.0000')
        ->and($product->fresh()->stock_reserved)->toBe('0.0000')
        ->and(InventoryMovement::query()->where('product_id', $product->id)->count())->toBe(0);
});

test('rejects a sale when children sharing one source exceed it only in aggregate', function () {
    $source = associatedCoreProduct($this, ['stock' => '20.0000']);
    $firstChild = associatedCoreChild($this, $source, '5.0000');
    $secondChild = associatedCoreChild($this, $source, '2.0000');

    expect(fn () => createAssociatedCoreOperation($this, 'sale', [
        ['product_id' => $firstChild->id, 'quantity' => '2.0000', 'price' => '10.00'],
        ['product_id' => $secondChild->id, 'quantity' => '6.0000', 'price' => '10.00'],
    ]))->toThrow(ValidationException::class);

    expect($source->fresh()->stock)->toBe('20.0000')
        ->and($source->fresh()->stock_reserved)->toBe('0.0000');
});

test('an order reserves the source once for multiple associated children', function () {
    $source = associatedCoreProduct($this, ['stock' => '30.0000']);
    $firstChild = associatedCoreChild($this, $source, '5.0000');
    $secondChild = associatedCoreChild($this, $source, '2.0000');

    createAssociatedCoreOperation($this, 'order', [
        ['product_id' => $firstChild->id, 'quantity' => '2.0000', 'price' => '10.00'],
        ['product_id' => $secondChild->id, 'quantity' => '3.0000', 'price' => '10.00'],
    ]);

    expect($source->fresh()->stock_reserved)->toBe('16.0000')
        ->and($firstChild->fresh()->stock_reserved)->toBe('0.0000')
        ->and($secondChild->fresh()->stock_reserved)->toBe('0.0000');
});

test('order reduction and cancellation release the historical source factor', function () {
    $source = associatedCoreProduct($this, ['stock' => '30.0000']);
    $child = associatedCoreChild($this, $source, '5.0000');
    $order = createAssociatedCoreOperation($this, 'order', [
        ['product_id' => $child->id, 'quantity' => '2.0000', 'price' => '10.00'],
    ]);

    $child->update(['stock_consumption_quantity' => '4.0000']);
    app(OrderItemEditService::class)->update($order, [
        ['product_id' => $child->id, 'quantity' => '1.0000'],
    ], null, null, null, $this->user);

    expect($source->fresh()->stock_reserved)->toBe('5.0000');

    app(CommercialOperationService::class)->cancel($order->fresh(), 'customer_request', null, $this->user);

    expect($source->fresh()->stock_reserved)->toBe('0.0000');
});

test('an order increase captures the current factor in its new line while preserving earlier snapshots', function () {
    $source = associatedCoreProduct($this, ['stock' => '30.0000']);
    $child = associatedCoreChild($this, $source, '5.0000');
    $order = createAssociatedCoreOperation($this, 'order', [
        ['product_id' => $child->id, 'quantity' => '1.0000', 'price' => '10.00'],
    ]);

    $child->update(['stock_consumption_quantity' => '4.0000']);
    app(OrderItemEditService::class)->update($order, [
        ['product_id' => $child->id, 'quantity' => '2.0000'],
    ], null, null, null, $this->user);

    $lines = $order->items()->orderBy('created_at')->orderBy('id')->get();

    expect($source->fresh()->stock_reserved)->toBe('9.0000')
        ->and($lines)->toHaveCount(2)
        ->and($lines->first()->stock_consumption_quantity)->toBe('5.0000')
        ->and($lines->last()->stock_consumption_quantity)->toBe('4.0000');
});

test('order edits reject aggregate increases from children sharing a source without partial reservations', function () {
    $source = associatedCoreProduct($this, ['stock' => '20.0000']);
    $firstChild = associatedCoreChild($this, $source, '5.0000');
    $secondChild = associatedCoreChild($this, $source, '2.0000');
    $order = createAssociatedCoreOperation($this, 'order', [
        ['product_id' => $firstChild->id, 'quantity' => '1.0000', 'price' => '10.00'],
        ['product_id' => $secondChild->id, 'quantity' => '1.0000', 'price' => '10.00'],
    ]);

    expect(fn () => app(OrderItemEditService::class)->update($order, [
        ['product_id' => $firstChild->id, 'quantity' => '2.0000'],
        ['product_id' => $secondChild->id, 'quantity' => '6.0000'],
    ], null, null, null, $this->user))->toThrow(ValidationException::class);

    expect($source->fresh()->stock_reserved)->toBe('7.0000');
});

test('a decimal associated sale consumes its source exactly', function () {
    $source = associatedCoreProduct($this, ['stock' => '10.0000']);
    $child = associatedCoreChild($this, $source, '1.5000', [
        'sale_quantity_step' => '0.5000',
    ]);

    createAssociatedCoreOperation($this, 'sale', [
        ['product_id' => $child->id, 'quantity' => '1.5000', 'price' => '10.00'],
    ]);

    expect($source->fresh()->stock)->toBe('7.7500')
        ->and($child->fresh()->stock)->toBe('0.0000');
});

test('sale cancellation restores the source selected by the immutable line snapshot', function () {
    $source = associatedCoreProduct($this, ['stock' => '10.0000']);
    $child = associatedCoreChild($this, $source, '5.0000');
    $sale = CommercialOperation::factory()->create([
        'store_id' => $this->store->id,
        'user_id' => $this->user->id,
        'type' => 'sale',
        'status' => 'confirmed',
    ]);
    OperationItem::factory()->create([
        'operation_id' => $sale->id,
        'product_id' => $child->id,
        'product_name' => $child->name,
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '5.0000',
        'quantity' => '2.0000',
    ]);
    $child->update(['stock_consumption_quantity' => '4.0000']);

    app(CancelSaleService::class)->cancel($sale, 'customer_cancelled', null, $this->user);

    expect($source->fresh()->stock)->toBe('20.0000')
        ->and($child->fresh()->stock)->toBe('0.0000');
});

test('order cancellation releases an associated child reservation from its immutable snapshot', function () {
    $originalSource = associatedCoreProduct($this, ['stock' => '20.0000']);
    $newSource = associatedCoreProduct($this, ['stock' => '20.0000']);
    $child = associatedCoreChild($this, $originalSource, '5.0000');

    $order = createAssociatedCoreOperation($this, 'order', [
        ['product_id' => $child->id, 'quantity' => '2.0000', 'price' => '10.00'],
    ]);

    expect($originalSource->fresh()->stock_reserved)->toBe('10.0000')
        ->and($child->fresh()->stock_reserved)->toBe('0.0000');

    // Simulate a later administrative change. The historical order must still
    // release the physical source and factor captured on its operation item.
    $child->update([
        'stock_source_product_id' => $newSource->id,
        'stock_consumption_quantity' => '4.0000',
    ]);

    app(CommercialOperationService::class)->cancel(
        $order,
        'customer_cancelled',
        null,
        $this->user,
    );

    expect($originalSource->fresh()->stock)->toBe('20.0000')
        ->and($originalSource->fresh()->stock_reserved)->toBe('0.0000')
        ->and($newSource->fresh()->stock_reserved)->toBe('0.0000')
        ->and($child->fresh()->stock)->toBe('0.0000')
        ->and($child->fresh()->stock_reserved)->toBe('0.0000');
});

test('order cancellation aggregates reservations from direct and associated products sharing a source', function () {
    $source = associatedCoreProduct($this, ['stock' => '20.0000']);
    $firstChild = associatedCoreChild($this, $source, '5.0000');
    $secondChild = associatedCoreChild($this, $source, '1.5000');

    $order = createAssociatedCoreOperation($this, 'order', [
        ['product_id' => $source->id, 'quantity' => '2.0000', 'price' => '10.00'],
        ['product_id' => $firstChild->id, 'quantity' => '1.0000', 'price' => '10.00'],
        ['product_id' => $secondChild->id, 'quantity' => '2.0000', 'price' => '10.00'],
    ]);

    // 2 direct + (1 × 5) + (2 × 1.5) = 10 physical units reserved on source.
    expect($source->fresh()->stock_reserved)->toBe('10.0000');

    app(CommercialOperationService::class)->cancel(
        $order,
        'customer_cancelled',
        null,
        $this->user,
    );

    expect($source->fresh()->stock)->toBe('20.0000')
        ->and($source->fresh()->stock_reserved)->toBe('0.0000')
        ->and($firstChild->fresh()->stock_reserved)->toBe('0.0000')
        ->and($secondChild->fresh()->stock_reserved)->toBe('0.0000');
});
