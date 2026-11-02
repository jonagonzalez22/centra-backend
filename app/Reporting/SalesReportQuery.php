<?php

namespace App\Reporting;

use App\Models\CommercialOperation;
use App\Support\MoneyMath;

final class SalesReportQuery
{
    public function summary(ReportContext $context): array
    {
        $result = CommercialOperation::query()
            ->forStore($context->storeId)
            ->where('type', 'sale')
            ->where('status', 'confirmed')
            ->where('created_at', '>=', $context->fromUtc)
            ->where('created_at', '<', $context->toUtcExclusive)
            ->selectRaw('COALESCE(SUM(total), 0) as amount, COUNT(*) as count')
            ->firstOrFail();

        $amount = MoneyMath::normalize((string) $result->amount);
        $count = (int) $result->count;

        return [
            'amount' => $amount,
            'count' => $count,
            'average_ticket' => $count === 0 ? '0.00' : MoneyMath::normalize(bcdiv($amount, (string) $count, 8)),
        ];
    }
}
