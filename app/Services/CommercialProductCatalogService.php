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
            ->find($productId);
    }

    /**
     * @return Collection<int, Product>
     */
    public function search(string $storeId, ?string $query = null, ?string $barcode = null): Collection
    {
        return Product::forStore($storeId)
            ->where('is_active', true)
            ->when($barcode !== null, fn ($products) => $products->where('barcode', $barcode))
            ->when($query !== null, function ($products) use ($query) {
                $products->where(function ($search) use ($query) {
                    $search->where('name', 'like', "%{$query}%")
                        ->orWhere('sku', 'like', "%{$query}%");
                });
            })
            ->limit(10)
            ->get(['id', 'name', 'sku', 'barcode']);
    }
}
