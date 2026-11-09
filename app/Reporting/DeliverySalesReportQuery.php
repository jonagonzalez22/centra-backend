<?php

namespace App\Reporting;

use App\Models\DeliverySaleAllocation;
use App\Support\MoneyMath;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** Read-only financial reporting over the immutable delivery allocation snapshot. */
final class DeliverySalesReportQuery
{
    public function amount(ReportContext $context): string
    {
        $amount = DeliverySaleAllocation::query()
            ->where('store_id', $context->storeId)
            ->where('occurred_at', '>=', $context->fromUtc)
            ->where('occurred_at', '<', $context->toUtcExclusive)
            ->sum('net_amount');

        return MoneyMath::normalize((string) $amount);
    }

    /** @return array<string, string> */
    public function dailyAmounts(ReportContext $context): array
    {
        $rows = DB::query()
            ->fromSub(ReportDailyBuckets::query($context), 'days')
            ->leftJoin('delivery_sale_allocations as allocations', function (JoinClause $join) use ($context): void {
                $join->on('allocations.occurred_at', '>=', 'days.starts_at')
                    ->on('allocations.occurred_at', '<', 'days.ends_at')
                    ->where('allocations.store_id', $context->storeId);
            })
            ->selectRaw('days.report_date, COALESCE(SUM(allocations.net_amount), 0) as amount')
            ->groupBy('days.report_date')
            ->orderBy('days.report_date')
            ->get();

        return $rows->mapWithKeys(fn (object $row): array => [
            $row->report_date => MoneyMath::normalize((string) $row->amount),
        ])->all();
    }

    /** @return array<int, array{product_id: string, name: string, operations_count: int}> */
    public function topProducts(ReportContext $context): array
    {
        // Allocation rows retain their originating operation item, whose product_name is
        // the commercial snapshot captured when the order line was created.
        $rows = DeliverySaleAllocation::query()
            ->where('delivery_sale_allocations.store_id', $context->storeId)
            ->where('delivery_sale_allocations.occurred_at', '>=', $context->fromUtc)
            ->where('delivery_sale_allocations.occurred_at', '<', $context->toUtcExclusive)
            ->join('operation_items', 'operation_items.id', '=', 'delivery_sale_allocations.operation_item_id')
            ->select([
                'delivery_sale_allocations.product_id',
                'operation_items.product_name',
                'delivery_sale_allocations.operation_id',
                'delivery_sale_allocations.occurred_at',
                'delivery_sale_allocations.id',
            ])
            ->orderBy('delivery_sale_allocations.product_id')
            ->orderByDesc('delivery_sale_allocations.occurred_at')
            ->orderByDesc('delivery_sale_allocations.id')
            ->get();

        return $rows->groupBy('product_id')->map(function ($allocations, string $productId): array {
            $latest = $allocations->first();

            return [
                'product_id' => $productId,
                'name' => $latest->product_name,
                // Re-deliveries and multiple allocation rows for the same product in
                // one order are one product choice, not separate sales.
                'operations_count' => $allocations->pluck('operation_id')->unique()->count(),
            ];
        })->values()->all();
    }
}
