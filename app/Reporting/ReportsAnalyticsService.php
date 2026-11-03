<?php

namespace App\Reporting;

final readonly class ReportsAnalyticsService
{
    public function __construct(
        private OrdersReportQuery $orders,
        private PaymentsReportQuery $payments,
    ) {}

    public function analytics(ReportContext $context): array
    {
        $collected = $this->payments->dailyCollectedAmounts($context);
        $ordersTaken = $this->orders->dailyOrderCounts($context);
        $deliveries = $this->orders->dailyDeliveriesCounts($context);

        return [
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
        ];
    }
}
