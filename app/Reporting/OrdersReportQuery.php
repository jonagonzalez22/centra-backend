<?php

namespace App\Reporting;

use App\Models\CommercialOperation;
use App\Models\RouteStop;
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

    public function completedDeliveriesCount(ReportContext $context): array
    {
        $count = RouteStop::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $context->fromUtc)
            ->where('completed_at', '<', $context->toUtcExclusive)
            ->whereHas('route', fn ($query) => $query->forStore($context->storeId))
            ->whereHas('order', fn ($query) => $query->where('type', 'order'))
            ->whereHas('items', fn ($query) => $query->where('quantity_delivered', '>', 0))
            ->count();

        return ['count' => $count];
    }
}
