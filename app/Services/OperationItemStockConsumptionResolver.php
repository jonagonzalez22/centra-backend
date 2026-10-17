<?php

namespace App\Services;

use App\Models\OperationItem;
use App\Support\QuantityMath;

/**
 * Resolves the immutable physical-stock configuration recorded on an
 * operation line. Legacy and normal lines intentionally consume their own
 * commercial product at a factor of one.
 */
class OperationItemStockConsumptionResolver
{
    public function resolve(OperationItem $item): OperationItemStockConsumption
    {
        if ($item->stock_source_product_id === null) {
            if ($item->stock_consumption_quantity !== null) {
                throw new \LogicException('El snapshot de consumo de stock de la línea es inconsistente.');
            }

            return new OperationItemStockConsumption($item->product_id, '1.0000');
        }

        if ($item->stock_consumption_quantity === null) {
            throw new \LogicException('El snapshot de consumo de stock asociado está incompleto.');
        }

        return new OperationItemStockConsumption(
            $item->stock_source_product_id,
            QuantityMath::normalize($item->stock_consumption_quantity),
        );
    }

    public function physicalQuantity(OperationItem $item, int|string $commercialQuantity): string
    {
        return QuantityMath::multiply(
            QuantityMath::normalize($commercialQuantity),
            $this->resolve($item)->consumptionFactor,
        );
    }
}
