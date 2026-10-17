<?php

namespace App\Services;

use App\Models\Product;
use App\Support\QuantityMath;

class ProductStockConsumptionResolver
{
    public function resolve(Product $product): ProductStockConsumption
    {
        if ($product->stock_source_product_id === null) {
            return new ProductStockConsumption(
                commercialProduct: $product,
                stockProduct: $product,
                consumptionFactor: '1.0000',
            );
        }

        $product->loadMissing('stockSourceProduct');
        $source = $product->stockSourceProduct;

        if (! $source || $product->stock_consumption_quantity === null) {
            throw new \LogicException('La configuración de consumo de stock del producto es inválida.');
        }

        return new ProductStockConsumption(
            commercialProduct: $product,
            stockProduct: $source,
            consumptionFactor: QuantityMath::normalize($product->stock_consumption_quantity),
        );
    }

    public function commercialAvailableQuantity(Product $product): string
    {
        $consumption = $this->resolve($product);
        $step = QuantityMath::normalize($product->sale_quantity_step ?? '1');
        $sourceAvailable = $consumption->stockProduct->available_stock;
        $sourcePerCommercialStep = QuantityMath::multiply($consumption->consumptionFactor, $step);
        $availableSteps = QuantityMath::floorDivide($sourceAvailable, $sourcePerCommercialStep);

        return QuantityMath::multiply($availableSteps, $step);
    }

    /**
     * @return array{stock_source_product_id: string|null, stock_consumption_quantity: string|null}
     */
    public function operationItemSnapshot(Product $product): array
    {
        $consumption = $this->resolve($product);

        return [
            'stock_source_product_id' => $consumption->isAssociated() ? $consumption->stockProduct->id : null,
            'stock_consumption_quantity' => $consumption->isAssociated() ? $consumption->consumptionFactor : null,
        ];
    }
}
