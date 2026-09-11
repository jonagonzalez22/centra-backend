<?php

namespace App\Services;

use App\Models\Store;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class CashBusinessDateService
{
    public function currentForStore(Store $store, ?CarbonInterface $at = null): string
    {
        $timezone = $store->timezone ?: config('app.timezone', 'UTC');
        $cutoffHour = (int) config('cash.business_day_cutoff_hour', 4);
        $localTime = $at
            ? CarbonImmutable::instance($at)->setTimezone($timezone)
            : CarbonImmutable::now($timezone);

        return $localTime->subHours($cutoffHour)->toDateString();
    }
}
