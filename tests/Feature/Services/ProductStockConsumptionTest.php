<?php

use App\Http\Resources\ProductResource;
use App\Models\MeasurementUnit;
use App\Models\Product;
use App\Models\Store;
use App\Services\ProductStockAssociationValidator;
use App\Services\ProductStockConsumptionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function stockConsumptionProduct(Store $store, array $attributes = []): Product
{
    return Product::factory()->forStore($store)->create([
        'stock' => '0.0000',
        'stock_reserved' => '0.0000',
        'stock_min' => '0.0000',
        ...$attributes,
    ]);
}

function stockConsumptionChild(Product $source, array $attributes = []): Product
{
    return stockConsumptionProduct($source->store, [
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '1.0000',
        ...$attributes,
    ]);
}

test('a normal product resolves itself as its stock owner with factor one', function () {
    $product = stockConsumptionProduct(Store::factory()->create(), ['stock' => '12.0000']);

    $consumption = app(ProductStockConsumptionResolver::class)->resolve($product);

    expect($consumption->commercialProduct->id)->toBe($product->id)
        ->and($consumption->stockProduct->id)->toBe($product->id)
        ->and($consumption->consumptionFactor)->toBe('1.0000')
        ->and($consumption->isAssociated())->toBeFalse();
});

test('an associated product resolves its source and exact consumption factor', function () {
    $source = stockConsumptionProduct(Store::factory()->create(), ['stock' => '20.0000']);
    $child = stockConsumptionChild($source, ['stock_consumption_quantity' => '5.0000']);

    $consumption = app(ProductStockConsumptionResolver::class)->resolve($child);

    expect($consumption->commercialProduct->id)->toBe($child->id)
        ->and($consumption->stockProduct->id)->toBe($source->id)
        ->and($consumption->consumptionFactor)->toBe('5.0000')
        ->and($consumption->isAssociated())->toBeTrue();
});

test('stock association validator rejects self source, cross-store source and inactive source', function () {
    $store = Store::factory()->create();
    $child = stockConsumptionProduct($store);
    $otherStoreSource = stockConsumptionProduct(Store::factory()->create());
    $inactiveSource = stockConsumptionProduct($store, ['is_active' => false]);
    $validator = app(ProductStockAssociationValidator::class);

    expect(fn () => $validator->validate($child, $child, '1.0000'))
        ->toThrow(\InvalidArgumentException::class, 'propia fuente')
        ->and(fn () => $validator->validate($child, $otherStoreSource, '1.0000'))
        ->toThrow(\InvalidArgumentException::class, 'misma tienda')
        ->and(fn () => $validator->validate($child, $inactiveSource, '1.0000'))
        ->toThrow(\InvalidArgumentException::class, 'debe estar activo');
});

test('stock association validator allows multiple children for a normal source and rejects a source that depends on another product', function () {
    $store = Store::factory()->create();
    $root = stockConsumptionProduct($store);
    $associatedSource = stockConsumptionChild($root);
    $firstChild = stockConsumptionProduct($store);
    $secondChild = stockConsumptionProduct($store);
    $thirdChild = stockConsumptionProduct($store);
    $validator = app(ProductStockAssociationValidator::class);

    $validator->validate($firstChild, $root, '1.0000');
    stockConsumptionChild($root);

    $validator->validate($secondChild, $root, '1.0000');
    stockConsumptionChild($root);

    $validator->validate($thirdChild, $root, '1.0000');

    expect(fn () => $validator->validate($thirdChild, $associatedSource, '1.0000'))
        ->toThrow(\InvalidArgumentException::class, 'no puede depender');
});

test('stock association validator rejects invalid factors and non-zero child physical fields', function () {
    $store = Store::factory()->create();
    $source = stockConsumptionProduct($store);
    $child = stockConsumptionProduct($store);
    $validator = app(ProductStockAssociationValidator::class);

    expect(fn () => $validator->validate($child, $source, '0.0000'))
        ->toThrow(\InvalidArgumentException::class, 'mayor a cero')
        ->and(fn () => $validator->validate($child, $source, '-1.0000'))
        ->toThrow(\InvalidArgumentException::class, 'mayor a cero');

    $child->stock_min = '1.0000';

    expect(fn () => $validator->validate($child, $source, '1.0000'))
        ->toThrow(\InvalidArgumentException::class, 'stock mínimo en cero');
});

test('stock association validator rejects factors whose product with the commercial step exceeds precision', function () {
    $store = Store::factory()->create();
    $source = stockConsumptionProduct($store);
    $child = stockConsumptionProduct($store, ['sale_quantity_step' => '0.3333']);

    expect(fn () => app(ProductStockAssociationValidator::class)->validate($child, $source, '0.3333'))
        ->toThrow(\InvalidArgumentException::class, 'exceden la precisión');
});

test('normal product commercial availability remains unchanged', function () {
    $product = stockConsumptionProduct(Store::factory()->create(), [
        'stock' => '20.0000',
        'stock_reserved' => '5.0000',
        'sale_quantity_step' => '0.2500',
    ]);

    expect($product->commercial_available_quantity)->toBe('15.0000');
});

test('associated product commercial availability derives from source stock and reservation', function () {
    $unit = MeasurementUnit::query()->where('code', 'unit')->firstOrFail();
    $source = stockConsumptionProduct(Store::factory()->create(), [
        'stock' => '20.0000',
        'stock_reserved' => '0.0000',
    ]);
    $child = stockConsumptionChild($source, [
        'stock_measurement_unit_id' => $unit->id,
        'stock_consumption_quantity' => '5.0000',
        'sale_quantity_step' => '1.0000',
    ]);

    expect($child->commercial_available_quantity)->toBe('4.0000');

    $source->update(['stock_reserved' => '5.0000']);
    $child->unsetRelation('stockSourceProduct');

    expect($child->commercial_available_quantity)->toBe('3.0000');
});

test('associated product availability supports a decimal commercial step exactly', function () {
    $source = stockConsumptionProduct(Store::factory()->create(), ['stock' => '20.0000']);
    $child = stockConsumptionChild($source, [
        'stock_consumption_quantity' => '2.0000',
        'sale_quantity_step' => '0.2500',
    ]);

    expect($child->commercial_available_quantity)->toBe('10.0000');
});

test('multiple children and their directly sellable source derive availability from the same physical stock', function () {
    $source = stockConsumptionProduct(Store::factory()->create(), [
        'stock' => '20.0000',
        'stock_reserved' => '5.0000',
        'sale_quantity_step' => '1.0000',
    ]);
    $firstChild = stockConsumptionChild($source, [
        'stock_consumption_quantity' => '5.0000',
        'sale_quantity_step' => '1.0000',
    ]);
    $secondChild = stockConsumptionChild($source, [
        'stock_consumption_quantity' => '2.0000',
        'sale_quantity_step' => '0.5000',
    ]);

    expect($source->commercial_available_quantity)->toBe('15.0000')
        ->and($firstChild->commercial_available_quantity)->toBe('3.0000')
        ->and($secondChild->commercial_available_quantity)->toBe('7.5000');
});

test('stock consumption snapshots distinguish normal and associated products', function () {
    $source = stockConsumptionProduct(Store::factory()->create());
    $child = stockConsumptionChild($source, ['stock_consumption_quantity' => '5.0000']);
    $resolver = app(ProductStockConsumptionResolver::class);

    expect($resolver->operationItemSnapshot($source))->toBe([
        'stock_source_product_id' => null,
        'stock_consumption_quantity' => null,
    ])->and($resolver->operationItemSnapshot($child))->toBe([
        'stock_source_product_id' => $source->id,
        'stock_consumption_quantity' => '5.0000',
    ]);
});

test('product resource exposes an associated stock source without lazy-loading it', function () {
    $sourceUnit = MeasurementUnit::query()->where('code', 'l')->firstOrFail();
    $source = stockConsumptionProduct(Store::factory()->create(), [
        'name' => 'Ariel granel',
        'sku' => 'ARIEL-L',
        'stock_measurement_unit_id' => $sourceUnit->id,
        'stock' => '20.0000',
    ]);
    $child = stockConsumptionChild($source, [
        'stock_consumption_quantity' => '5.0000',
    ]);
    $child->load('stockMeasurementUnit', 'stockSourceProduct.stockMeasurementUnit');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $data = ProductResource::make($child)->resolve();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(0)
        ->and($data['stock_source_product'])->toBe([
            'id' => $source->id,
            'name' => 'Ariel granel',
            'sku' => 'ARIEL-L',
            'stock_measurement_unit' => [
                'code' => 'l',
                'symbol' => 'L',
            ],
        ])
        ->and($data['stock_consumption_quantity'])->toBe('5.0000');
});
