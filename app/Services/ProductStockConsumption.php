<?php

namespace App\Services;

use App\Models\Product;

final readonly class ProductStockConsumption
{
    public function __construct(
        public Product $commercialProduct,
        public Product $stockProduct,
        public string $consumptionFactor,
    ) {}

    public function isAssociated(): bool
    {
        return $this->commercialProduct->id !== $this->stockProduct->id;
    }
}
