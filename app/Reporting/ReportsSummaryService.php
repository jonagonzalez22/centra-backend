<?php

namespace App\Reporting;

final readonly class ReportsSummaryService
{
    public function __construct(
        private SalesReportQuery $sales,
        private OrdersReportQuery $orders,
        private PaymentsReportQuery $payments,
        private InventoryReportQuery $inventory,
        private AttentionReportQuery $attention,
    ) {}

    public function summary(ReportContext $context): array
    {
        return [
            'metrics' => [
                'pos_sales' => $this->sales->summary($context),
                'orders_taken' => $this->orders->summary($context),
                'deliveries_completed' => $this->orders->completedDeliveriesCount($context),
                'collected' => $this->payments->collected($context),
            ],
            'current' => [
                'pending_balance' => $this->payments->currentPendingBalance($context),
                'inventory_alerts' => $this->inventory->alerts($context),
                'attention' => [
                    ...$this->attention->summary($context),
                    'delivered_with_balance' => $this->payments->deliveredOrdersWithPendingBalanceCount($context),
                ],
            ],
        ];
    }
}
