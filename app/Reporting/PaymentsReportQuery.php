<?php

namespace App\Reporting;

use App\Models\CommercialOperation;
use App\Models\OperationPayment;
use App\Support\MoneyMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

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

        return [
            'amount' => MoneyMath::normalize((string) $amount),
            'channels' => $this->channels($context),
        ];
    }

    /** @return array<string, string> */
    public function dailyCollectedAmounts(ReportContext $context): array
    {
        $payments = OperationPayment::query()
            ->join('commercial_operations as operations', 'operations.id', '=', 'operation_payments.operation_id')
            ->where('operations.store_id', $context->storeId)
            ->where('operation_payments.status', 'active')
            ->select('operation_payments.created_at', 'operation_payments.amount');

        $rows = DB::query()
            ->fromSub(ReportDailyBuckets::query($context), 'days')
            ->leftJoinSub($payments, 'payments', function (JoinClause $join): void {
                $join->on('payments.created_at', '>=', 'days.starts_at')
                    ->on('payments.created_at', '<', 'days.ends_at');
            })
            ->selectRaw('days.report_date, COALESCE(SUM(payments.amount), 0) as amount')
            ->groupBy('days.report_date')
            ->orderBy('days.report_date')
            ->get();

        return $rows->mapWithKeys(fn (object $row): array => [
            $row->report_date => MoneyMath::normalize((string) $row->amount),
        ])->all();
    }

    /** @return array<int, array{payment_method_id: string, name: string, amount: string, count: int}> */
    public function paymentMethods(ReportContext $context): array
    {
        $rows = OperationPayment::query()
            ->join('commercial_operations as operations', 'operations.id', '=', 'operation_payments.operation_id')
            ->join('store_payment_methods', 'store_payment_methods.id', '=', 'operation_payments.store_payment_method_id')
            ->join('payment_methods', 'payment_methods.id', '=', 'store_payment_methods.payment_method_id')
            ->where('operations.store_id', $context->storeId)
            ->where('operation_payments.status', 'active')
            ->where('operation_payments.created_at', '>=', $context->fromUtc)
            ->where('operation_payments.created_at', '<', $context->toUtcExclusive)
            ->selectRaw("payment_methods.id as payment_method_id, COALESCE(NULLIF(store_payment_methods.custom_name, ''), payment_methods.name) as name, COALESCE(SUM(operation_payments.amount), 0) as amount, COUNT(*) as count")
            ->groupBy('payment_methods.id', 'store_payment_methods.custom_name', 'payment_methods.name')
            ->orderByDesc('amount')
            ->get();

        return $rows->map(fn (object $row): array => [
            'payment_method_id' => $row->payment_method_id,
            'name' => $row->name,
            'amount' => MoneyMath::normalize((string) $row->amount),
            'count' => (int) $row->count,
        ])->all();
    }

    /** @return array{pos_amount: string, orders_amount: string} */
    public function channels(ReportContext $context): array
    {
        $payments = OperationPayment::query()
            ->join('commercial_operations as operations', 'operations.id', '=', 'operation_payments.operation_id')
            ->where('operations.store_id', $context->storeId)
            ->where('operation_payments.status', 'active')
            ->where('operation_payments.created_at', '>=', $context->fromUtc)
            ->where('operation_payments.created_at', '<', $context->toUtcExclusive)
            ->get(['operation_payments.amount', 'operation_payments.payment_details', 'operations.type']);

        $amounts = ['pos_amount' => '0.00', 'orders_amount' => '0.00'];

        foreach ($payments as $payment) {
            $origin = $payment->payment_details['origin'] ?? null;
            $channel = $origin === 'pos_sale' || ($origin === null && $payment->type === 'sale')
                ? 'pos_amount'
                : 'orders_amount';
            $amounts[$channel] = MoneyMath::add($amounts[$channel], (string) $payment->amount);
        }

        return $amounts;
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
