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
        private CommercialSalesCostReportQuery $commercialCosts,
    ) {}

    public function summary(ReportContext $context): array
    {
        $posSales = $this->sales->summary($context);
        $ordersAmount = $this->deliverySales->amount($context);
        $realizedAmount = \App\Support\MoneyMath::add($posSales['amount'], $ordersAmount);
        $commercialCosts = $this->commercialCosts->summary($context);

        return [
            'metrics' => [
                'pos_sales' => $posSales,
                'realized_sales' => [
                    'amount' => $realizedAmount,
                    'channels' => $this->realizedChannels($posSales['amount'], $ordersAmount, $realizedAmount),
                ],
                'gross_profit' => $this->grossProfit($realizedAmount, $commercialCosts),
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

    /**
     * @param  array{known_cost_amount: string, known_cost_items: int, missing_cost_quantity: string, missing_cost_items: int}  $costs
     * @return array{sales_amount: string, known_cost_amount: string, amount: string|null, margin_percentage: string|null, status: string, has_missing_costs: bool}
     */
    private function grossProfit(string $salesAmount, array $costs): array
    {
        $hasMissingCosts = $costs['missing_cost_items'] > 0;
        $hasKnownCost = $costs['known_cost_items'] > 0;
        $isWithoutSales = \App\Support\MoneyMath::compare($salesAmount, '0') === 0;
        $status = $isWithoutSales || ! $hasMissingCosts
            ? 'complete'
            : ($hasKnownCost ? 'partial' : 'unavailable');

        $amount = $status === 'unavailable'
            ? null
            : \App\Support\MoneyMath::subtract($salesAmount, $costs['known_cost_amount']);
        $margin = $amount === null || $isWithoutSales
            ? null
            : \App\Support\MoneyMath::roundHalfUp(
                bcdiv(bcmul($amount, '100', 8), $salesAmount, 8),
                2,
            );

        return [
            'sales_amount' => $salesAmount,
            'known_cost_amount' => $costs['known_cost_amount'],
            'amount' => $amount,
            'margin_percentage' => $margin,
            'status' => $status,
            'has_missing_costs' => $hasMissingCosts,
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
