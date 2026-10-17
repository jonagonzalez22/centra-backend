<?php

namespace App\Services;

use App\Models\OperationItem;
use App\Models\Product;
use App\Models\RouteStopItem;
use App\Support\QuantityMath;

/**
 * Resolves and captures the physical-stock configuration of a route item.
 *
 * New route items receive an immutable copy from their originating operation
 * line. Rows created before this snapshot existed deliberately fall back to
 * the product's current configuration; those legacy rows are not certified
 * historical snapshots.
 */
class RouteStopItemStockConsumptionResolver
{
    public function __construct(
        private readonly OperationItemStockConsumptionResolver $operationItemResolver,
        private readonly ProductStockConsumptionResolver $productResolver,
    ) {}

    /**
     * @return array{stock_source_product_id: string|null, stock_consumption_quantity: string|null, stock_source_measurement_unit_code: string|null, stock_source_measurement_unit_symbol: string|null}
     */
    public function snapshotForOperationItem(OperationItem $item): array
    {
        $consumption = $this->operationItemResolver->resolve($item);

        if ($item->stock_source_product_id === null) {
            $product = $item->relationLoaded('product') ? $item->product : $item->product()->first();
            if (! $product) {
                throw new \LogicException('El producto comercial del item no existe.');
            }

            // Legacy operation lines predate their physical snapshot. Resolve
            // their current association only as an explicit compatibility
            // fallback; new normal lines still resolve to self/one below.
            $legacyConsumption = $this->productResolver->resolve($product);
            if (! $legacyConsumption->isAssociated()) {
                return $this->normalSnapshot();
            }

            return $this->associatedSnapshot(
                $legacyConsumption->stockProduct,
                $legacyConsumption->consumptionFactor,
            );
        }

        $source = $item->relationLoaded('stockSourceProduct')
            ? $item->stockSourceProduct
            : Product::query()->with('stockMeasurementUnit')->find($consumption->stockProductId);

        if (! $source) {
            throw new \LogicException('La fuente física del item comercial no existe.');
        }

        return $this->associatedSnapshot($source, $consumption->consumptionFactor);
    }

    public function resolve(RouteStopItem $item): OperationItemStockConsumption
    {
        if ($item->stock_source_product_id !== null || $item->stock_consumption_quantity !== null) {
            if ($item->stock_source_product_id === null || $item->stock_consumption_quantity === null) {
                throw new \LogicException('El snapshot de consumo físico del item de ruta está incompleto.');
            }

            return new OperationItemStockConsumption(
                $item->stock_source_product_id,
                QuantityMath::normalize($item->stock_consumption_quantity),
            );
        }

        $product = $item->relationLoaded('product') ? $item->product : $item->product()->first();
        if (! $product) {
            throw new \LogicException('El producto comercial del item de ruta no existe.');
        }

        $consumption = $this->productResolver->resolve($product);

        return new OperationItemStockConsumption(
            $consumption->stockProduct->id,
            $consumption->consumptionFactor,
        );
    }

    /**
     * Returns the immutable physical snapshot stored on a route item. Legacy
     * rows deliberately retain the existing current-product compatibility
     * fallback, because they predate route-item snapshots.
     *
     * @return array{stock_source_product_id: string|null, stock_consumption_quantity: string|null, stock_source_measurement_unit_code: string|null, stock_source_measurement_unit_symbol: string|null}
     */
    public function snapshotForRouteStopItem(RouteStopItem $item): array
    {
        if ($item->stock_source_product_id !== null || $item->stock_consumption_quantity !== null) {
            if ($item->stock_source_product_id === null || $item->stock_consumption_quantity === null) {
                throw new \LogicException('El snapshot de consumo físico del item de ruta está incompleto.');
            }

            if ($item->stock_source_measurement_unit_code === null || $item->stock_source_measurement_unit_symbol === null) {
                throw new \LogicException('El snapshot de unidad física del item de ruta está incompleto.');
            }

            return [
                'stock_source_product_id' => $item->stock_source_product_id,
                'stock_consumption_quantity' => QuantityMath::normalize($item->stock_consumption_quantity),
                'stock_source_measurement_unit_code' => $item->stock_source_measurement_unit_code,
                'stock_source_measurement_unit_symbol' => $item->stock_source_measurement_unit_symbol,
            ];
        }

        $product = $item->relationLoaded('product') ? $item->product : $item->product()->first();
        if (! $product) {
            throw new \LogicException('El producto comercial del item de ruta no existe.');
        }

        $consumption = $this->productResolver->resolve($product);

        return $consumption->isAssociated()
            ? $this->associatedSnapshot($consumption->stockProduct, $consumption->consumptionFactor)
            : $this->normalSnapshot();
    }

    /**
     * @return array{stock_source_product_id: string|null, stock_consumption_quantity: string|null, stock_source_measurement_unit_code: string|null, stock_source_measurement_unit_symbol: string|null}
     */
    public function normalSnapshot(): array
    {
        return [
            'stock_source_product_id' => null,
            'stock_consumption_quantity' => null,
            'stock_source_measurement_unit_code' => null,
            'stock_source_measurement_unit_symbol' => null,
        ];
    }

    /**
     * @return array{stock_source_product_id: string, stock_consumption_quantity: string, stock_source_measurement_unit_code: string, stock_source_measurement_unit_symbol: string}
     */
    private function associatedSnapshot(Product $source, string $consumptionFactor): array
    {
        $source->loadMissing('stockMeasurementUnit');
        if (! $source->stockMeasurementUnit) {
            throw new \LogicException('La fuente física del item comercial no tiene unidad de medida.');
        }

        return [
            'stock_source_product_id' => $source->id,
            'stock_consumption_quantity' => QuantityMath::normalize($consumptionFactor),
            'stock_source_measurement_unit_code' => $source->stockMeasurementUnit->code,
            'stock_source_measurement_unit_symbol' => $source->stockMeasurementUnit->symbol,
        ];
    }

    /**
     * @param  array{stock_source_product_id: string|null, stock_consumption_quantity: string|null, stock_source_measurement_unit_code: string|null, stock_source_measurement_unit_symbol: string|null}  $snapshot
     */
    public function snapshotKey(array $snapshot): string
    {
        return implode('|', [
            $snapshot['stock_source_product_id'] ?? 'self',
            $snapshot['stock_consumption_quantity'] ?? '1.0000',
            $snapshot['stock_source_measurement_unit_code'] ?? 'self',
            $snapshot['stock_source_measurement_unit_symbol'] ?? 'self',
        ]);
    }
}
