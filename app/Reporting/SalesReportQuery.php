<?php

namespace App\Reporting;

use App\Models\CommercialOperation;
use App\Models\OperationItem;
use App\Support\MoneyMath;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

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

    /** @return array<string, string> */
    public function dailyAmounts(ReportContext $context): array
    {
        $rows = DB::query()
            ->fromSub(ReportDailyBuckets::query($context), 'days')
            ->leftJoin('commercial_operations as operations', function (JoinClause $join) use ($context): void {
                $join->on('operations.created_at', '>=', 'days.starts_at')
                    ->on('operations.created_at', '<', 'days.ends_at')
                    ->where('operations.store_id', $context->storeId)
                    ->where('operations.type', 'sale')
                    ->where('operations.status', 'confirmed');
            })
            ->selectRaw('days.report_date, COALESCE(SUM(operations.total), 0) as amount')
            ->groupBy('days.report_date')
            ->orderBy('days.report_date')
            ->get();

        return $rows->mapWithKeys(fn (object $row): array => [
            $row->report_date => MoneyMath::normalize((string) $row->amount),
        ])->all();
    }

    /** @return array<int, array{product_id: string, name: string, amount: string}> */
    public function topProducts(ReportContext $context): array
    {
        $rows = OperationItem::query()
            ->join('commercial_operations as operations', 'operations.id', '=', 'operation_items.operation_id')
            ->where('operations.store_id', $context->storeId)
            ->where('operations.type', 'sale')
            ->where('operations.status', 'confirmed')
            ->where('operations.created_at', '>=', $context->fromUtc)
            ->where('operations.created_at', '<', $context->toUtcExclusive)
            ->selectRaw('operation_items.product_id, operation_items.product_name, operation_items.created_at, (operation_items.subtotal + operation_items.tax_amount - operation_items.discount_amount) as line_amount')
            ->orderBy('operation_items.product_id')
            ->orderByDesc('operation_items.created_at')
            ->orderByDesc('operation_items.id')
            ->get();

        return $rows->groupBy('product_id')
            ->map(function ($items, string $productId): array {
                $latest = $items->first();
                $amount = $items->reduce(
                    fn (string $total, object $item): string => MoneyMath::add($total, (string) $item->line_amount),
                    '0.00',
                );

                return ['product_id' => $productId, 'name' => $latest->product_name, 'amount' => $amount];
            })
            ->sortByDesc('amount', SORT_NUMERIC)
            ->take(5)
            ->values()
            ->all();
    }
}
