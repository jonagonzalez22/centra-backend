<?php

namespace App\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ReportDailyBuckets
{
    /**
     * Builds local calendar-day buckets with UTC boundaries. Keeping the boundaries
     * explicit avoids grouping UTC timestamps with DATE(created_at), and does not
     * require MySQL timezone tables to be installed for CONVERT_TZ.
     */
    public static function query(ReportContext $context): Builder
    {
        $day = $context->fromLocal;
        $query = null;

        while ($day->lessThanOrEqualTo($context->toLocal)) {
            $bucket = DB::query()->selectRaw(
                '? as report_date, ? as starts_at, ? as ends_at',
                [
                    $day->format('Y-m-d'),
                    $day->utc()->toDateTimeString(),
                    $day->addDay()->utc()->toDateTimeString(),
                ],
            );

            $query = $query === null ? $bucket : $query->unionAll($bucket);
            $day = $day->addDay();
        }

        /** @var Builder $query */
        return $query;
    }

    /** @return array<int, string> */
    public static function dates(ReportContext $context): array
    {
        $dates = [];
        $day = $context->fromLocal;

        while ($day->lessThanOrEqualTo($context->toLocal)) {
            $dates[] = $day->format('Y-m-d');
            $day = $day->addDay();
        }

        return $dates;
    }
}
