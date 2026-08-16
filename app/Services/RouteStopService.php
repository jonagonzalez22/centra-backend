<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\RouteStop;
use Carbon\Carbon;

class RouteStopService
{
    /**
     * Calculate the notification window for a route stop.
     *
     * The notification window defines when the customer should be contacted
     * about their upcoming delivery. For most stops the window is ETA ± 30 min.
     * The first stop gets a special window if ETA is between 07:00 and 09:59:
     * start = ETA, end = ETA + 60 min (no -30 min so they're not contacted before 7 AM).
     *
     * All times are calculated in the store's timezone (defaults to app timezone).
     *
     * @return array{
     *     eta: string|null,
     *     start: string|null,
     *     end: string|null,
     *     start_rounded: string|null,
     *     end_rounded: string|null,
     *     start_raw: string|null,
     *     end_raw: string|null,
     *     day_label: string|null,
     * }
     */
    public function calculateNotificationWindow(RouteStop $stop): array
    {
        $eta = $stop->estimated_arrival_at;

        if (! $eta) {
            return [
                'eta' => null,
                'start' => null,
                'end' => null,
                'start_rounded' => null,
                'end_rounded' => null,
                'start_raw' => null,
                'end_raw' => null,
                'day_label' => null,
            ];
        }

        $timezone = $this->resolveTimezone($stop);
        $eta = Carbon::parse($eta)->setTimezone($timezone);

        $isFirstStop = $stop->sequence === 1;
        $etaHour = (int) $eta->format('G'); // 0-23

        if ($isFirstStop && $etaHour >= 7 && $etaHour <= 9) {
            // First stop in morning window: start at ETA (no pre-buffer)
            $start = $eta->copy();
            $end = $eta->copy()->addMinutes(60);
        } else {
            // Default: ±30 min window
            $start = $eta->copy()->subMinutes(30);
            $end = $eta->copy()->addMinutes(30);
        }

        $startRounded = $this->floorToNearestFive($start);
        $endRounded = $this->ceilToNearestFive($end);
        $dayLabel = $this->buildDayLabel($eta, $timezone);

        return [
            'eta' => $eta->toIso8601String(),
            'start' => $start->format('H:i'),
            'end' => $end->format('H:i'),
            'start_rounded' => $startRounded->format('H:i'),
            'end_rounded' => $endRounded->format('H:i'),
            'start_raw' => $start->toIso8601String(),
            'end_raw' => $end->toIso8601String(),
            'day_label' => $dayLabel,
        ];
    }

    /**
     * Floor a Carbon instance to the nearest previous 5-minute mark.
     */
    private function floorToNearestFive(Carbon $time): Carbon
    {
        $minutes = (int) $time->format('i');
        $floored = $minutes - ($minutes % 5);

        return $time->copy()->minute($floored)->second(0);
    }

    /**
     * Ceil a Carbon instance to the nearest next 5-minute mark.
     */
    private function ceilToNearestFive(Carbon $time): Carbon
    {
        $minutes = (int) $time->format('i');
        $remainder = $minutes % 5;
        $ceiled = $remainder === 0 ? $minutes : $minutes + (5 - $remainder);

        if ($ceiled >= 60) {
            return $time->copy()->addHour()->minute(0)->second(0);
        }

        return $time->copy()->minute($ceiled)->second(0);
    }

    /**
     * Build a human-readable day label relative to now in the given timezone.
     */
    private function buildDayLabel(Carbon $eta, string $timezone): string
    {
        $now = Carbon::now($timezone);
        $etaDate = $eta->copy()->startOfDay();
        $today = $now->copy()->startOfDay();
        $tomorrow = $today->copy()->addDay();

        if ($etaDate->equalTo($today)) {
            return 'hoy';
        }

        if ($etaDate->equalTo($tomorrow)) {
            return 'mañana';
        }

        return $eta->format('d/m');
    }

    /**
     * Resolve the store's timezone for this stop.
     * Falls back to app timezone if the store has no timezone set.
     */
    private function resolveTimezone(RouteStop $stop): string
    {
        $storeTimezone = $stop->route?->store?->timezone;

        if ($storeTimezone) {
            return $storeTimezone;
        }

        return config('app.timezone', 'UTC');
    }
}
