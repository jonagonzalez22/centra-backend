<?php

namespace App\Reporting;

use App\Models\CommercialOperation;
use App\Models\DeliveryRoute;

final class AttentionReportQuery
{
    public function summary(ReportContext $context): array
    {
        return [
            'partially_delivered_orders' => CommercialOperation::query()
                ->forStore($context->storeId)
                ->where('type', 'order')
                ->where('status', 'partially_delivered')
                ->count(),
            'routes_pending_reconciliation' => DeliveryRoute::query()
                ->forStore($context->storeId)
                ->where('status', 'awaiting_reconciliation')
                ->count(),
        ];
    }
}
