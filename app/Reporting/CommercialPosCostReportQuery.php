<?php

namespace App\Reporting;

use App\Models\OperationItem;
use App\Support\MoneyMath;
use App\Support\QuantityMath;

/** Commercial historical cost of the same confirmed POS sales used by Reports. */
final class CommercialPosCostReportQuery
{
    /** @return array{known_cost_amount: string, known_cost_items: int, missing_cost_quantity: string, missing_cost_items: int} */
    public function summary(ReportContext $context): array
    {
        $result = OperationItem::query()
            ->join('commercial_operations as operations', 'operations.id', '=', 'operation_items.operation_id')
            ->where('operations.store_id', $context->storeId)
            ->where('operations.type', 'sale')
            ->where('operations.status', 'confirmed')
            ->where('operations.created_at', '>=', $context->fromUtc)
            ->where('operations.created_at', '<', $context->toUtcExclusive)
            ->selectRaw('COALESCE(SUM(CASE WHEN operation_items.unit_cost_snapshot IS NOT NULL THEN operation_items.quantity * operation_items.unit_cost_snapshot ELSE 0 END), 0) as known_cost')
            ->selectRaw('COUNT(CASE WHEN operation_items.unit_cost_snapshot IS NOT NULL THEN 1 END) as known_items')
            ->selectRaw('COALESCE(SUM(CASE WHEN operation_items.unit_cost_snapshot IS NULL THEN operation_items.quantity ELSE 0 END), 0) as missing_quantity')
            ->selectRaw('COUNT(CASE WHEN operation_items.unit_cost_snapshot IS NULL THEN 1 END) as missing_items')
            ->firstOrFail();

        return [
            'known_cost_amount' => MoneyMath::normalize((string) $result->known_cost),
            'known_cost_items' => (int) $result->known_items,
            'missing_cost_quantity' => QuantityMath::normalize((string) $result->missing_quantity),
            'missing_cost_items' => (int) $result->missing_items,
        ];
    }
}
