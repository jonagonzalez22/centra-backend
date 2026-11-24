<?php

namespace App\Services;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\Store;
use App\Support\MoneyMath;
use App\Support\QuantityMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class PriceListExportDataService
{
    /** The document is deliberately unpaginated, but bounded to protect response size. */
    private const MAX_PRODUCTS = 5000;

    public function __construct(private readonly ProductStockConsumptionResolver $stockConsumptionResolver) {}

    /**
     * @param  array{pricing_mode?: 'general'|'price_list', price_list_id?: string, selection_mode: 'filtered'|'selected', product_ids?: array<int, string>, filters?: array{search?: string|null, category_id?: string|null, brand_id?: string|null, include_out_of_stock?: bool}}  $data
     */
    public function build(array $data, string $storeId): array
    {
        $pricingMode = $data['pricing_mode'] ?? 'price_list';
        $priceList = $pricingMode === 'price_list'
            ? PriceList::forStore($storeId)->where('is_active', true)->find($data['price_list_id'])
            : null;
        if ($pricingMode === 'price_list' && ! $priceList) {
            throw ValidationException::withMessages([
                'price_list_id' => ['La lista de precios no existe, no está activa o no pertenece a tu tienda.'],
            ]);
        }

        $filters = $data['filters'] ?? [];
        $includeOutOfStock = (bool) ($filters['include_out_of_stock'] ?? false);
        $requestedIds = $data['selection_mode'] === 'selected' ? $data['product_ids'] : [];

        $products = $this->productQuery($storeId, $filters, $requestedIds)
            ->limit(self::MAX_PRODUCTS + 1)
            ->get();
        if ($products->count() > self::MAX_PRODUCTS) {
            throw ValidationException::withMessages([
                'selection_mode' => ['La exportación supera el máximo de '.self::MAX_PRODUCTS.' productos. Refiná los filtros o seleccioná productos explícitamente.'],
            ]);
        }

        $eligibleProducts = $products
            ->filter(fn (Product $product): bool => $includeOutOfStock || QuantityMath::isPositive($product->commercial_available_quantity))
            ->values();

        $returnedIds = $eligibleProducts->pluck('id')->all();
        $excludedProductIds = $data['selection_mode'] === 'selected'
            ? collect($requestedIds)->reject(fn (string $id): bool => in_array($id, $returnedIds, true))->values()->all()
            : [];

        $store = Store::query()->findOrFail($storeId);
        $generatedAt = now()->setTimezone($store->timezone ?: config('app.timezone', 'UTC'));

        return [
            'store' => [
                'name' => $store->name,
                'logo_url' => $store->url_logo,
                'phone' => $store->phone,
                'address' => $store->address,
            ],
            'pricing_mode' => $pricingMode,
            'price_list' => $priceList ? [
                'id' => $priceList->id,
                'name' => $priceList->name,
                'discount_percentage' => MoneyMath::normalize($priceList->discount_percentage),
            ] : null,
            'generated_at' => $generatedAt->toIso8601String(),
            'products' => $eligibleProducts->map(fn (Product $product): array => $this->productData($product, $priceList))->all(),
            'summary' => [
                'product_count' => $eligibleProducts->count(),
                'excluded_product_count' => count($excludedProductIds),
                'include_out_of_stock' => $includeOutOfStock,
            ],
            // IDs only: a foreign or no-longer-eligible product must not leak catalogue details.
            'excluded_product_ids' => $excludedProductIds,
        ];
    }

    /** @param array<string, mixed> $filters @param array<int, string> $requestedIds */
    private function productQuery(string $storeId, array $filters, array $requestedIds): Builder
    {
        return Product::forStore($storeId)
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->when($requestedIds !== [], fn (Builder $query) => $query->whereIn('id', $requestedIds))
            ->when(isset($filters['category_id']), fn (Builder $query) => $query->where('category_id', $filters['category_id']))
            ->when(isset($filters['brand_id']), fn (Builder $query) => $query->where('brand_id', $filters['brand_id']))
            ->when(isset($filters['search']) && trim((string) $filters['search']) !== '', function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['search']);
                $query->where(fn (Builder $searchQuery) => $searchQuery
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%"));
            })
            ->with([
                'category:id,name',
                'brand:id,name',
                'stockMeasurementUnit:id,code,name,symbol',
                'stockSourceProduct:id,stock,stock_reserved',
            ])
            ->orderBy('name')
            ->orderBy('id');
    }

    private function productData(Product $product, ?PriceList $priceList): array
    {
        $listPrice = MoneyMath::normalize($product->price);
        $discountPercentage = $priceList?->discount_percentage ?? '0';
        $discountAmount = MoneyMath::percentage($listPrice, $discountPercentage);

        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'category' => $product->category ? ['id' => $product->category->id, 'name' => $product->category->name] : null,
            'brand' => $product->brand ? ['id' => $product->brand->id, 'name' => $product->brand->name] : null,
            'measurement_unit' => [
                'code' => $product->stockMeasurementUnit->code,
                'name' => $product->stockMeasurementUnit->name,
                'symbol' => $product->stockMeasurementUnit->symbol,
            ],
            'list_price' => $listPrice,
            'discount_percentage' => MoneyMath::normalize($discountPercentage),
            'discount_amount' => $discountAmount,
            'final_price' => MoneyMath::subtract($listPrice, $discountAmount),
            'commercial_available_quantity' => QuantityMath::normalize($product->commercial_available_quantity),
        ];
    }
}
