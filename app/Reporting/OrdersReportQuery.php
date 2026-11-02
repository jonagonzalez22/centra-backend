<?php

namespace App\Reporting;

use App\Models\CommercialOperation;
use App\Support\MoneyMath;

final class OrdersReportQuery
{
    public function summary(ReportContext $context): array
    {
        $result = CommercialOperation::query()
            ->forStore($context->storeId)
            ->where('type', 'order')
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $context->fromUtc)
            ->where('created_at', '<', $context->toUtcExclusive)
            ->selectRaw('COALESCE(SUM(total), 0) as amount, COUNT(*) as count')
            ->firstOrFail();

        return [
            'amount' => MoneyMath::normalize((string) $result->amount),
            'count' => (int) $result->count,
        ];
    }
}
