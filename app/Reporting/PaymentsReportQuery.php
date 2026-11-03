<?php

namespace App\Reporting;

use App\Models\CommercialOperation;
use App\Models\OperationPayment;
use App\Support\MoneyMath;
use Illuminate\Database\Eloquent\Builder;

final class PaymentsReportQuery
{
    public function collected(ReportContext $context): array
    {
        $amount = OperationPayment::query()
            ->whereHas('operation', fn (Builder $query) => $query->forStore($context->storeId))
            ->active()
            ->where('created_at', '>=', $context->fromUtc)
            ->where('created_at', '<', $context->toUtcExclusive)
            ->sum('amount');

        return ['amount' => MoneyMath::normalize((string) $amount)];
    }

    public function currentPendingBalance(ReportContext $context): array
    {
        $operations = CommercialOperation::query()
            ->forStore($context->storeId)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $orders): void {
                    $orders->where('type', 'order')
                        ->whereIn('status', ['open', 'partially_delivered', 'delivered']);
                })->orWhere(function (Builder $sales): void {
                    $sales->where('type', 'sale')->where('status', 'confirmed');
                });
            })
            ->withSum('activePayments', 'amount')
            ->get(['id', 'total']);

        $pendingBalance = '0.00';

        foreach ($operations as $operation) {
            $pending = MoneyMath::subtract(
                (string) $operation->total,
                (string) ($operation->active_payments_sum_amount ?? '0'),
            );

            if (MoneyMath::compare($pending, '0') > 0) {
                $pendingBalance = MoneyMath::add($pendingBalance, $pending);
            }
        }

        return ['amount' => $pendingBalance];
    }

    public function deliveredOrdersWithPendingBalanceCount(ReportContext $context): int
    {
        $orders = CommercialOperation::query()
            ->forStore($context->storeId)
            ->where('type', 'order')
            ->where('status', 'delivered')
            ->withSum('activePayments', 'amount')
            ->get(['id', 'total']);

        return $orders->filter(fn (CommercialOperation $order): bool => MoneyMath::compare(
            MoneyMath::subtract(
                (string) $order->total,
                (string) ($order->active_payments_sum_amount ?? '0'),
            ),
            '0'
        ) > 0)->count();
    }
}
