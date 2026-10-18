<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;

class CommercialProductCatalogService
{
    public function findActiveForStore(string $storeId, string $productId): ?Product
    {
        return Product::forStore($storeId)
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->with(['stockMeasurementUnit', 'stockSourceProduct.stockMeasurementUnit'])
            ->find($productId);
    }

    /**
     * @return Collection<int, Product>
     */
    public function search(string $storeId, ?string $query = null, ?string $barcode = null): Collection
    {
        return Product::forStore($storeId)
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->with(['stockMeasurementUnit', 'stockSourceProduct.stockMeasurementUnit'])
            ->when($barcode !== null, fn ($products) => $products->where('barcode', $barcode))
            ->when($query !== null, function ($products) use ($query) {
                $products->where(function ($search) use ($query) {
                    $search->where('name', 'like', "%{$query}%")
                        ->orWhere('sku', 'like', "%{$query}%");
                });
            })
            ->limit(10)
            ->get([
                'id',
                'name',
                'sku',
                'barcode',
                'stock',
                'stock_reserved',
                'stock_measurement_unit_id',
                'stock_source_product_id',
                'stock_consumption_quantity',
                'sale_quantity_step',
            ]);
    }

    /**
     * Active stock sources are an administrative inventory catalog, not a
     * commercial catalog: a source may intentionally be non-sellable.
     *
     * @return Collection<int, Product>
     */
    public function searchStockSources(string $storeId, string $query): Collection
    {
        return Product::forStore($storeId)
            ->where('is_active', true)
            ->whereNull('stock_source_product_id')
            ->with('stockMeasurementUnit')
            ->where(function ($search) use ($query) {
                $search->where('name', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get([
                'id',
                'name',
                'sku',
                'stock',
                'stock_reserved',
                'stock_measurement_unit_id',
                'stock_source_product_id',
                'stock_consumption_quantity',
                'sale_quantity_step',
            ]);
    }
}
