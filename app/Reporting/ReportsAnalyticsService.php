<?php

namespace App\Reporting;

final readonly class ReportsAnalyticsService
{
    public function __construct(
        private SalesReportQuery $sales,
        private DeliverySalesReportQuery $deliverySales,
        private OrdersReportQuery $orders,
        private PaymentsReportQuery $payments,
    ) {}

    public function analytics(ReportContext $context): array
    {
        $collected = $this->payments->dailyCollectedAmounts($context);
        $ordersTaken = $this->orders->dailyOrderCounts($context);
        $deliveries = $this->orders->dailyDeliveriesCounts($context);
        $posSales = $this->sales->dailyAmounts($context);
        $orderSales = $this->deliverySales->dailyAmounts($context);

        return [
            'realized_sales_series' => array_map(fn (string $date): array => [
                'date' => $date,
                'pos_amount' => $posSales[$date] ?? '0.00',
                'orders_amount' => $orderSales[$date] ?? '0.00',
                'total_amount' => \App\Support\MoneyMath::add($posSales[$date] ?? '0.00', $orderSales[$date] ?? '0.00'),
            ], ReportDailyBuckets::dates($context)),
            'payments_series' => array_map(fn (string $date): array => [
                'date' => $date,
                'collected_amount' => $collected[$date] ?? '0.00',
            ], ReportDailyBuckets::dates($context)),
            'orders_series' => array_map(fn (string $date): array => [
                'date' => $date,
                'orders_taken_count' => $ordersTaken[$date] ?? 0,
                'deliveries_count' => $deliveries[$date] ?? 0,
            ], ReportDailyBuckets::dates($context)),
            'payment_methods' => $this->payments->paymentMethods($context),
            'top_products' => $this->topProducts($context),
        ];
    }

    /** @return array<int, array{product_id: string, name: string, operations_count: int}> */
    private function topProducts(ReportContext $context): array
    {
        $products = [];
        foreach ([...$this->sales->topProducts($context), ...$this->deliverySales->topProducts($context)] as $product) {
            $id = $product['product_id'];
            $products[$id] ??= ['product_id' => $id, 'name' => $product['name'], 'operations_count' => 0];
            // POS and delivery both carry the historical operation-item name. Prefer POS
            // when it exists, but never use current product data for a financial report.
            if ($products[$id]['name'] === '') {
                $products[$id]['name'] = $product['name'];
            }
            // POS sales and delivered orders are distinct operation origins. Adding
            // their per-origin distinct counts avoids collisions even if identifiers
            // were ever shared across sources.
            $products[$id]['operations_count'] += $product['operations_count'];
        }

        uasort($products, fn (array $left, array $right): int => $right['operations_count'] <=> $left['operations_count']
            ?: strcmp($left['name'], $right['name'])
            ?: strcmp($left['product_id'], $right['product_id'])
        );

        return array_values(array_slice($products, 0, 5));
    }
}
