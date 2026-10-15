<?php

declare(strict_types=1);

use App\Http\Resources\CommercialOperationResource;
use App\Http\Resources\OperationItemResource;
use App\Models\CommercialOperation;
use App\Models\Customer;
use App\Models\MeasurementUnit;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\DeliverySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(Tests\TestCase::class);
uses(RefreshDatabase::class);

test('OperationItemResource uses a complete commercial snapshot', function () {
    [, $product, $item] = operationItemSnapshotFixture([
        'measurement_unit_code' => 'kg',
        'measurement_unit_symbol' => 'kg',
        'sale_quantity_step' => '0.2500',
    ]);
    $product->update(['sale_quantity_step' => '0.5000']);

    $data = OperationItemResource::make($item->fresh())->toArray(new Request);

    expect($data['stock_measurement_unit'])->toBe(['code' => 'kg', 'symbol' => 'kg'])
        ->and($data['sale_quantity_step'])->toBe('0.2500');
});

test('OperationItemResource falls back completely for a historical null snapshot', function () {
    [, $product, $item] = operationItemSnapshotFixture();
    $product->update(['sale_quantity_step' => '1.0000']);

    $data = OperationItemResource::make($item->fresh())->toArray(new Request);

    expect($data['stock_measurement_unit'])->toBe(['code' => 'l', 'symbol' => 'L'])
        ->and($data['sale_quantity_step'])->toBe('1.0000');
});

test('a partial snapshot falls back completely to the current product configuration', function () {
    [, , $item] = operationItemSnapshotFixture([
        'measurement_unit_code' => 'kg',
        'measurement_unit_symbol' => null,
        'sale_quantity_step' => '0.2500',
    ]);

    $data = OperationItemResource::make($item->fresh())->toArray(new Request);

    expect($data['stock_measurement_unit'])->toBe(['code' => 'l', 'symbol' => 'L'])
        ->and($data['sale_quantity_step'])->toBe('0.5000');
});

test('CommercialOperationResource uses a complete snapshot after the product configuration changes', function () {
    [$operation, $product] = operationItemSnapshotFixture([
        'measurement_unit_code' => 'kg',
        'measurement_unit_symbol' => 'kg',
        'sale_quantity_step' => '0.2500',
    ]);
    $product->update(['sale_quantity_step' => '0.5000']);

    $data = CommercialOperationResource::make($operation->fresh()->load('items.product.stockMeasurementUnit'))
        ->toArray(new Request);

    expect($data['items'][0]['stock_measurement_unit'])->toBe(['code' => 'kg', 'symbol' => 'kg'])
        ->and($data['items'][0]['sale_quantity_step'])->toBe('0.2500');
});

test('CommercialOperationResource uses the product configuration for historical null snapshots', function () {
    [$operation] = operationItemSnapshotFixture();

    $data = CommercialOperationResource::make($operation->fresh()->load('items.product.stockMeasurementUnit'))
        ->toArray(new Request);

    expect($data['items'][0]['stock_measurement_unit'])->toBe(['code' => 'l', 'symbol' => 'L'])
        ->and($data['items'][0]['sale_quantity_step'])->toBe('0.5000');
});

test('CommercialOperationResource does not group lines with different snapshots', function (array $difference) {
    [$operation, , $firstItem] = operationItemSnapshotFixture([
        'measurement_unit_code' => 'kg',
        'measurement_unit_symbol' => 'kg',
        'sale_quantity_step' => '0.2500',
    ]);
    $secondItem = $firstItem->replicate();
    $secondItem->id = null;
    $secondItem->fill($difference);
    $secondItem->save();

    $data = CommercialOperationResource::make($operation->fresh()->load('items.product.stockMeasurementUnit'))
        ->toArray(new Request);

    expect($data['items'])->toHaveCount(2)
        ->and(collect($data['items'])->pluck('quantity')->all())->toBe(['2.0000', '2.0000']);
})->with([
    'measurement unit code' => [['measurement_unit_code' => 'g']],
    'measurement unit symbol' => [['measurement_unit_symbol' => 'K']],
    'sale quantity step' => [['sale_quantity_step' => '0.5000']],
]);

test('DeliverySummaryService uses the complete operation item snapshot', function () {
    [$operation] = operationItemSnapshotFixture([
        'measurement_unit_code' => 'kg',
        'measurement_unit_symbol' => 'kg',
        'sale_quantity_step' => '0.2500',
    ]);

    $item = app(DeliverySummaryService::class)->summarize($operation->fresh())['items'][0];

    expect($item['stock_measurement_unit'])->toBe(['code' => 'kg', 'symbol' => 'kg'])
        ->and($item['sale_quantity_step'])->toBe('0.2500');
});

/** @return array{CommercialOperation, Product, OperationItem} */
function operationItemSnapshotFixture(array $snapshot = []): array
{
    $store = Store::factory()->create();
    $user = User::factory()->create(['store_id' => $store->id]);
    $customer = Customer::factory()->create(['store_id' => $store->id]);
    $liter = MeasurementUnit::query()->where('code', 'l')->firstOrFail();
    $product = Product::factory()->create([
        'store_id' => $store->id,
        'stock_measurement_unit_id' => $liter->id,
        'sale_quantity_step' => '0.5000',
    ]);
    $operation = CommercialOperation::factory()->create([
        'store_id' => $store->id,
        'user_id' => $user->id,
        'customer_id' => $customer->id,
        'type' => 'order',
        'status' => 'open',
        'subtotal' => 20,
        'total' => 20,
    ]);
    $item = OperationItem::factory()->create(array_merge([
        'operation_id' => $operation->id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => '2.0000',
        'price' => 10,
        'subtotal' => 20,
        'tax_amount' => 0,
        'discount_amount' => 0,
        'measurement_unit_code' => null,
        'measurement_unit_symbol' => null,
        'sale_quantity_step' => null,
    ], $snapshot));

    return [$operation, $product, $item];
}
