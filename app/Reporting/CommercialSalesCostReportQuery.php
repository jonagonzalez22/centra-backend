<?php

namespace App\Reporting;

use App\Support\MoneyMath;
use App\Support\QuantityMath;

/** Composes the commercial historical cost of realized POS sales and deliveries. */
final readonly class CommercialSalesCostReportQuery
{
    public function __construct(
        private CommercialPosCostReportQuery $pos,
        private CommercialDeliveryCostReportQuery $delivery,
    ) {}

    /** @return array{known_cost_amount: string, known_cost_items: int, missing_cost_quantity: string, missing_cost_items: int} */
    public function summary(ReportContext $context): array
    {
        $pos = $this->pos->summary($context);
        $delivery = $this->delivery->summary($context);

        return [
            'known_cost_amount' => MoneyMath::add($pos['known_cost_amount'], $delivery['known_cost_amount']),
            'known_cost_items' => $pos['known_cost_items'] + $delivery['known_cost_items'],
            'missing_cost_quantity' => QuantityMath::add($pos['missing_cost_quantity'], $delivery['missing_cost_quantity']),
            'missing_cost_items' => $pos['missing_cost_items'] + $delivery['missing_cost_items'],
        ];
    }
}
