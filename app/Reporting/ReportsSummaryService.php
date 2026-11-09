<?php

namespace App\Reporting;

final readonly class ReportsSummaryService
{
    public function __construct(
        private SalesReportQuery $sales,
        private DeliverySalesReportQuery $deliverySales,
        private OrdersReportQuery $orders,
        private PaymentsReportQuery $payments,
        private InventoryReportQuery $inventory,
        private AttentionReportQuery $attention,
    ) {}

    public function summary(ReportContext $context): array
    {
        $posSales = $this->sales->summary($context);
        $ordersAmount = $this->deliverySales->amount($context);
        $realizedAmount = \App\Support\MoneyMath::add($posSales['amount'], $ordersAmount);

        return [
            'metrics' => [
                'pos_sales' => $posSales,
                'realized_sales' => [
                    'amount' => $realizedAmount,
                    'channels' => $this->realizedChannels($posSales['amount'], $ordersAmount, $realizedAmount),
                ],
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

    private function realizedChannels(string $posAmount, string $ordersAmount, string $total): array
    {
        if (\App\Support\MoneyMath::compare($total, '0') === 0) {
            return [
                'pos' => ['amount' => $posAmount, 'percentage' => 0],
                'orders' => ['amount' => $ordersAmount, 'percentage' => 0],
            ];
        }

        // The second share is the complement, so integer labels always add up to 100.
        $posPercentage = (int) \App\Support\MoneyMath::roundHalfUp(
            bcdiv(bcmul($posAmount, '100', 8), $total, 8),
            0,
        );

        return [
            'pos' => ['amount' => $posAmount, 'percentage' => $posPercentage],
            'orders' => ['amount' => $ordersAmount, 'percentage' => 100 - $posPercentage],
        ];
    }
}
