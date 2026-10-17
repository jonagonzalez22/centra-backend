<?php

namespace App\Services;

final readonly class OperationItemStockConsumption
{
    public function __construct(
        public string $stockProductId,
        public string $consumptionFactor,
    ) {}
}
