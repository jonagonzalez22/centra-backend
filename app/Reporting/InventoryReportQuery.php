<?php

namespace App\Reporting;

use App\Models\Product;
use App\Support\QuantityMath;

final class InventoryReportQuery
{
    public function alerts(ReportContext $context): array
    {
        $products = Product::query()
            ->forStore($context->storeId)
            ->where('is_active', true);

        return [
            'low_stock_count' => (clone $products)
                ->where('stock', '>', 0)
                ->where('stock_min', '>', 0)
                ->whereColumn('stock', '<=', 'stock_min')
                ->count(),
            'out_of_stock_count' => (clone $products)
                ->with('stockSourceProduct')
                ->get()
                ->filter(fn (Product $product) => QuantityMath::compare($product->commercial_available_quantity, '0') <= 0)
                ->count(),
        ];
    }
}
