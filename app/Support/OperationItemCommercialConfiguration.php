<?php

namespace App\Support;

use App\Models\OperationItem;

/**
 * Resolves the commercial measurement configuration shown for an operation line.
 *
 * A complete line snapshot is authoritative. Legacy and partially populated
 * snapshots intentionally fall back as a whole to the current product config,
 * so code, symbol, and step can never come from different configurations.
 */
final class OperationItemCommercialConfiguration
{
    /**
     * @return array{stock_measurement_unit: array{code: string, symbol: string}|null, sale_quantity_step: string|null}
     */
    public static function resolve(OperationItem $item): array
    {
        if (self::hasCompleteSnapshot($item)) {
            return [
                'stock_measurement_unit' => [
                    'code' => $item->measurement_unit_code,
                    'symbol' => $item->measurement_unit_symbol,
                ],
                'sale_quantity_step' => QuantityMath::normalize($item->sale_quantity_step),
            ];
        }

        $item->loadMissing('product.stockMeasurementUnit');
        $measurementUnit = $item->product?->stockMeasurementUnit;

        return [
            'stock_measurement_unit' => $measurementUnit ? [
                'code' => $measurementUnit->code,
                'symbol' => $measurementUnit->symbol,
            ] : null,
            'sale_quantity_step' => $item->product
                ? QuantityMath::normalize($item->product->sale_quantity_step ?? '1.0000')
                : null,
        ];
    }

    private static function hasCompleteSnapshot(OperationItem $item): bool
    {
        return $item->measurement_unit_code !== null
            && $item->measurement_unit_symbol !== null
            && $item->sale_quantity_step !== null;
    }
}
