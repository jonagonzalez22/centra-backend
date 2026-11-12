<?php

namespace App\Reporting;

use App\Models\DeliverySaleAllocation;
use App\Support\MoneyMath;
use App\Support\QuantityMath;

/** Commercial historical cost of immutable, effectively delivered quantities. */
final class CommercialDeliveryCostReportQuery
{
    /** @return array{known_cost_amount: string, known_cost_items: int, missing_cost_quantity: string, missing_cost_items: int} */
    public function summary(ReportContext $context): array
    {
        $result = DeliverySaleAllocation::query()
            ->where('store_id', $context->storeId)
            ->where('occurred_at', '>=', $context->fromUtc)
            ->where('occurred_at', '<', $context->toUtcExclusive)
            ->selectRaw('COALESCE(SUM(CASE WHEN unit_cost_snapshot IS NOT NULL THEN quantity_delivered * unit_cost_snapshot ELSE 0 END), 0) as known_cost')
            ->selectRaw('COUNT(CASE WHEN unit_cost_snapshot IS NOT NULL THEN 1 END) as known_items')
            ->selectRaw('COALESCE(SUM(CASE WHEN unit_cost_snapshot IS NULL THEN quantity_delivered ELSE 0 END), 0) as missing_quantity')
            ->selectRaw('COUNT(CASE WHEN unit_cost_snapshot IS NULL THEN 1 END) as missing_items')
            ->firstOrFail();

        return [
            'known_cost_amount' => MoneyMath::normalize((string) $result->known_cost),
            'known_cost_items' => (int) $result->known_items,
            'missing_cost_quantity' => QuantityMath::normalize((string) $result->missing_quantity),
            'missing_cost_items' => (int) $result->missing_items,
        ];
    }
}
