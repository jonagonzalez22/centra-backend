<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\CommercialOperationService;
use App\Services\OrderItemEditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = Store::factory()->create();
    $this->user = User::factory()->create(['store_id' => $this->store->id]);
    $this->customer = Customer::factory()->create(['store_id' => $this->store->id]);
    $this->product = Product::factory()->create([
        'store_id' => $this->store->id, 'price' => '10000.00',
        'stock' => '100.0000', 'stock_reserved' => '0.0000',
    ]);
});

function createPricedSale(object $test, array $items): \App\Models\CommercialOperation
{
    return app(CommercialOperationService::class)->create([
        'type' => 'sale', 'customer_id' => $test->customer->id, 'items' => $items, 'payments' => [],
    ], $test->store->id, $test->user->id);
}

function createPricedOrder(object $test, array $items): \App\Models\CommercialOperation
{
    return app(CommercialOperationService::class)->create([
        'type' => 'order', 'customer_id' => $test->customer->id,
        'requested_delivery_date' => now()->addDay()->toDateString(), 'items' => $items, 'payments' => [],
    ], $test->store->id, $test->user->id);
}

test('server resolves common and price list snapshots without trusting client money', function () {
    $wholesale = PriceList::factory()->create([
        'store_id' => $this->store->id, 'name' => 'Mayorista', 'discount_percentage' => '10.00',
    ]);
    $sale = createPricedSale($this, [
        ['product_id' => $this->product->id, 'quantity' => '2', 'price' => '1', 'discount_amount' => '9999'],
        ['product_id' => $this->product->id, 'quantity' => '1', 'price_list_id' => $wholesale->id],
    ]);
    $common = $sale->items()->whereNull('price_list_id')->sole();
    $discounted = $sale->items()->where('price_list_id', $wholesale->id)->sole();

    expect($common->price)->toBe('10000.00')
        ->and($common->discount_amount)->toBe('0.00')
        ->and($discounted->price_list_name)->toBe('Mayorista')
        ->and($discounted->discount_percentage)->toBe('10.00')
        ->and($discounted->discount_amount)->toBe('1000.00')
        ->and($sale->fresh()->discount)->toBe('1000.00');
});

test('same product and list consolidate while common and a real zero percent list differ', function () {
    $zero = PriceList::factory()->create(['store_id' => $this->store->id, 'discount_percentage' => '0.00']);
    $sale = createPricedSale($this, [
        ['product_id' => $this->product->id, 'quantity' => '1', 'price_list_id' => $zero->id],
        ['product_id' => $this->product->id, 'quantity' => '2', 'price_list_id' => $zero->id],
        ['product_id' => $this->product->id, 'quantity' => '1'],
    ]);

    expect($sale->items)->toHaveCount(2)
        ->and($sale->items()->where('price_list_id', $zero->id)->sole()->quantity)->toBe('3.0000')
        ->and($sale->items()->whereNull('price_list_id')->sole()->quantity)->toBe('1.0000');
});

test('inactive, foreign, and missing lists cannot price new items', function () {
    $inactive = PriceList::factory()->create(['store_id' => $this->store->id, 'is_active' => false]);
    $foreign = PriceList::factory()->create();
    foreach ([$inactive->id, $foreign->id, '00000000-0000-4000-8000-000000000000'] as $id) {
        expect(fn () => createPricedSale($this, [[
            'product_id' => $this->product->id, 'quantity' => '1', 'price_list_id' => $id,
        ]]))->toThrow(ValidationException::class);
    }
});

test('price list snapshots and one hundred percent discounts remain historical and bounded', function () {
    $list = PriceList::factory()->create(['store_id' => $this->store->id, 'name' => 'Liquidación', 'discount_percentage' => '100.00']);
    $sale = createPricedSale($this, [[
        'product_id' => $this->product->id, 'quantity' => '1', 'price_list_id' => $list->id,
    ]]);
    $line = $sale->items()->sole();
    $list->update(['name' => 'Ahora distinto', 'discount_percentage' => '15.00']);

    expect($line->discount_amount)->toBe('10000.00')
        ->and($sale->fresh()->total)->toBe('0.00')
        ->and($line->fresh()->price_list_name)->toBe('Liquidación')
        ->and($line->fresh()->discount_percentage)->toBe('100.00');
});

test('increasing an existing price-list line retains its snapshots after the live list changes', function () {
    $list = PriceList::factory()->create(['store_id' => $this->store->id, 'name' => 'Mayorista', 'discount_percentage' => '10.00']);
    $order = createPricedOrder($this, [[
        'product_id' => $this->product->id, 'quantity' => '1', 'price_list_id' => $list->id,
    ]]);
    $list->update(['name' => 'Mayorista nuevo', 'discount_percentage' => '15.00']);

    app(OrderItemEditService::class)->addItem($order, [
        'product_id' => $this->product->id, 'quantity' => '1', 'price_list_id' => $list->id,
    ], $this->user);

    $line = $order->items()->sole();
    expect($line->quantity)->toBe('2.0000')
        ->and($line->price)->toBe('10000.00')
        ->and($line->price_list_name)->toBe('Mayorista')
        ->and($line->discount_percentage)->toBe('10.00')
        ->and($line->discount_amount)->toBe('2000.00');

    $newProduct = Product::factory()->create(['store_id' => $this->store->id, 'price' => '10000.00', 'stock' => '100.0000']);
    app(OrderItemEditService::class)->addItem($order, [
        'product_id' => $newProduct->id, 'quantity' => '1', 'price_list_id' => $list->id,
    ], $this->user);

    expect($order->items()->where('product_id', $newProduct->id)->sole()->discount_percentage)->toBe('15.00');
});

test('a deactivated list remains historical but rejects new quantities', function () {
    $list = PriceList::factory()->create(['store_id' => $this->store->id, 'discount_percentage' => '10.00']);
    $order = createPricedOrder($this, [[
        'product_id' => $this->product->id, 'quantity' => '1', 'price_list_id' => $list->id,
    ]]);
    $list->update(['is_active' => false]);

    expect(fn () => app(OrderItemEditService::class)->addItem($order, [
        'product_id' => $this->product->id, 'quantity' => '1', 'price_list_id' => $list->id,
    ], $this->user))->toThrow(ValidationException::class)
        ->and($order->items()->sole()->discount_percentage)->toBe('10.00');
});

test('changing a concrete line price list merges a commercial identity', function () {
    $list = PriceList::factory()->create(['store_id' => $this->store->id, 'discount_percentage' => '10.00']);
    $order = createPricedOrder($this, [
        ['product_id' => $this->product->id, 'quantity' => '3', 'price_list_id' => $list->id],
        ['product_id' => $this->product->id, 'quantity' => '1'],
    ]);
    $common = $order->items()->whereNull('price_list_id')->sole();

    app(OrderItemEditService::class)->changePriceList($order, $common, $list->id, $this->user);

    $line = $order->items()->sole();
    expect($line->quantity)->toBe('4.0000')
        ->and($line->price_list_id)->toBe($list->id)
        ->and($line->discount_amount)->toBe('4000.00');
});
