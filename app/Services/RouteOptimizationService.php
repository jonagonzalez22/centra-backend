<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class RouteOptimizationService
{
    private string $apiKey;
    private string $baseUrl = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    public function __construct()
    {
        $this->apiKey = config('services.google_maps.api_key') ?? '';
    }

    /**
     * Optimize route using Google Routes API v2.
     *
     * @param array{0: float, 1: float} $origin [lat, lng]
     * @param array{0: float, 1: float} $destination [lat, lng]
     * @param array<int, array{0: float, 1: float}> $intermediates Waypoints
     * @param bool $optimizeOrder Whether to let Google optimize waypoint order
     * @return array{optimizedOrder: int[], durations: int[], polyline: string}
     */
    public function optimizeRoute(array $origin, array $destination, array $intermediates, bool $optimizeOrder = true): array
    {
        $payload = $this->buildPayload($origin, $destination, $intermediates, $optimizeOrder);
        $response = $this->callGoogleAPI($payload);

        return $this->parseResponse($response, count($intermediates));
    }

    /**
     * Calculate ETAs for stops given departure time, durations, and unload time.
     *
     * ETA for stop i = departure_time + sum(durations[0..i]) + (i * unload_time_for_previous_stops)
     *
     * @param string $departureTime Format "H:i"
     * @param int[] $durationsSeconds Leg durations in seconds
     * @param int $unloadTimeMinutes Time spent at each stop before proceeding
     * @param int[] $stopOrder The order of stop indices
     * @param string $operationalDate Date in "Y-m-d" format to prepend to ETAs
     * @return string[] Array of ETAs in "Y-m-d H:i:s" format
     */
    public function calculateETAs(string $departureTime, array $durationsSeconds, int $unloadTimeMinutes, array $stopOrder, string $operationalDate): array
    {
        if (empty($stopOrder)) {
            return [];
        }

        $etas = [];
        $baseTime = $this->parseTimeToSeconds($departureTime);

        foreach ($stopOrder as $position => $stopIndex) {
            $cumulativeTravel = 0;
            // Sum durations from leg 0 to leg $position (inclusive)
            for ($i = 0; $i <= $position; $i++) {
                $cumulativeTravel += $durationsSeconds[$i] ?? 0;
            }

            // Unload time for previous stops (not including current)
            $unloadOffset = $position * $unloadTimeMinutes * 60;

            $totalSeconds = $baseTime + $cumulativeTravel + $unloadOffset;
            $etas[$stopIndex] = $operationalDate . ' ' . $this->formatSecondsToTime($totalSeconds);
        }

        // Ensure array is ordered by original stop index
        ksort($etas);

        return array_values($etas);
    }

    /**
     * Build the Google Routes API v2 request payload.
     */
    private function buildPayload(array $origin, array $destination, array $intermediates, bool $optimizeOrder): array
    {
        $payload = [
            'origin' => [
                'location' => [
                    'latLng' => [
                        'latitude' => $origin[0],
                        'longitude' => $origin[1],
                    ],
                ],
            ],
            'destination' => [
                'location' => [
                    'latLng' => [
                        'latitude' => $destination[0],
                        'longitude' => $destination[1],
                    ],
                ],
            ],
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_AWARE',
        ];

        if (! empty($intermediates)) {
            $payload['intermediates'] = array_map(function (array $waypoint): array {
                return [
                    'location' => [
                        'latLng' => [
                            'latitude' => $waypoint[0],
                            'longitude' => $waypoint[1],
                        ],
                    ],
                ];
            }, $intermediates);

            if ($optimizeOrder) {
                $payload['optimizeWaypointOrder'] = true;
            }
        }

        return $payload;
    }

    /**
     * Call Google Routes API with proper headers.
     */
    private function callGoogleAPI(array $payload): array
    {
        $response = Http::withHeaders([
            'X-Goog-FieldMask' => 'routes.optimizedIntermediateWaypointIndex,routes.legs.duration,routes.polyline.encodedPolyline',
            'X-Goog-Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl, $payload);

        return $response->json() ?? [];
    }

    /**
     * Parse Google response: extract optimized order, durations, polyline.
     */
    private function parseResponse(array $response, int $waypointCount): array
    {
        $route = $response['routes'][0] ?? [];

        $optimizedOrder = $route['optimizedIntermediateWaypointIndex'] ?? null;

        // Fallback: if Google returns empty or null (single stop, nothing to optimize),
        // use natural order
        if (empty($optimizedOrder) || ! is_array($optimizedOrder)) {
            $optimizedOrder = range(0, max(0, $waypointCount - 1));
        }

        // Filter out invalid indices (Google may return -1 for unreachable waypoints)
        $optimizedOrder = array_values(array_filter(
            array_map('intval', $optimizedOrder),
            fn (int $index) => $index >= 0 && $index < $waypointCount
        ));

        // If filtering removed all entries, fall back to natural order
        if (empty($optimizedOrder)) {
            $optimizedOrder = range(0, max(0, $waypointCount - 1));
        }

        $durations = [];
        $legs = $route['legs'] ?? [];

        foreach ($legs as $leg) {
            $durationStr = $leg['duration'] ?? '0s';
            $durations[] = (int) rtrim($durationStr, 's');
        }

        // Global polyline for the entire route (store → stops → store)
        $polyline = $route['polyline']['encodedPolyline'] ?? '';

        return [
            'optimizedOrder' => array_map('intval', $optimizedOrder),
            'durations' => $durations,
            'polyline' => $polyline,
        ];
    }

    /**
     * Parse a "H:i" string to total seconds since midnight.
     */
    private function parseTimeToSeconds(string $time): int
    {
        $parts = explode(':', $time);
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);

        return ($hours * 3600) + ($minutes * 60);
    }

    /**
     * Format total seconds since midnight to "H:i:s" string.
     */
    private function formatSecondsToTime(int $totalSeconds): string
    {
        $hours = intdiv($totalSeconds, 3600) % 24;
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }
}
