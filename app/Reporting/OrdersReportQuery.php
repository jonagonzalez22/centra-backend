<?php

namespace App\Reporting;

use App\Models\CommercialOperation;
use App\Models\RouteStop;
use App\Support\MoneyMath;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class OrdersReportQuery
{
    public function summary(ReportContext $context): array
    {
        $result = CommercialOperation::query()
            ->forStore($context->storeId)
            ->where('type', 'order')
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $context->fromUtc)
            ->where('created_at', '<', $context->toUtcExclusive)
            ->selectRaw('COALESCE(SUM(total), 0) as amount, COUNT(*) as count')
            ->firstOrFail();

        return [
            'amount' => MoneyMath::normalize((string) $result->amount),
            'count' => (int) $result->count,
        ];
    }

    public function completedDeliveriesCount(ReportContext $context): array
    {
        $count = RouteStop::query()
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $context->fromUtc)
            ->where('completed_at', '<', $context->toUtcExclusive)
            ->whereHas('route', fn ($query) => $query->forStore($context->storeId))
            ->whereHas('order', fn ($query) => $query->where('type', 'order'))
            ->whereHas('items', fn ($query) => $query->where('quantity_delivered', '>', 0))
            ->count();

        return ['count' => $count];
    }

    /** @return array<string, int> */
    public function dailyOrderCounts(ReportContext $context): array
    {
        $rows = DB::query()
            ->fromSub(ReportDailyBuckets::query($context), 'days')
            ->leftJoin('commercial_operations as operations', function (JoinClause $join) use ($context): void {
                $join->on('operations.created_at', '>=', 'days.starts_at')
                    ->on('operations.created_at', '<', 'days.ends_at')
                    ->where('operations.store_id', $context->storeId)
                    ->where('operations.type', 'order')
                    ->where('operations.status', '!=', 'cancelled');
            })
            ->selectRaw('days.report_date, COUNT(operations.id) as count')
            ->groupBy('days.report_date')
            ->orderBy('days.report_date')
            ->get();

        return $rows->mapWithKeys(fn (object $row): array => [$row->report_date => (int) $row->count])->all();
    }

    /** @return array<string, int> */
    public function dailyDeliveriesCounts(ReportContext $context): array
    {
        $deliveries = RouteStop::query()
            ->join('delivery_routes', 'delivery_routes.id', '=', 'route_stops.route_id')
            ->join('commercial_operations as orders', 'orders.id', '=', 'route_stops.order_id')
            ->where('delivery_routes.store_id', $context->storeId)
            ->where('route_stops.status', 'completed')
            ->whereNotNull('route_stops.completed_at')
            ->where('orders.type', 'order')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('route_stop_items')
                    ->whereColumn('route_stop_items.route_stop_id', 'route_stops.id')
                    ->where('route_stop_items.quantity_delivered', '>', 0);
            })
            ->select('route_stops.completed_at');

        $rows = DB::query()
            ->fromSub(ReportDailyBuckets::query($context), 'days')
            ->leftJoinSub($deliveries, 'deliveries', function (JoinClause $join): void {
                $join->on('deliveries.completed_at', '>=', 'days.starts_at')
                    ->on('deliveries.completed_at', '<', 'days.ends_at');
            })
            ->selectRaw('days.report_date, COUNT(deliveries.completed_at) as count')
            ->groupBy('days.report_date')
            ->orderBy('days.report_date')
            ->get();

        return $rows->mapWithKeys(fn (object $row): array => [$row->report_date => (int) $row->count])->all();
    }
}
