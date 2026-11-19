<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RouteOptimizationException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RouteOptimizationService
{
    public const MAX_INTERMEDIATE_WAYPOINTS = 25;

    private string $apiKey;

    private string $baseUrl = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    public function __construct()
    {
        $this->apiKey = config('services.google_maps.api_key') ?? '';
    }

    /**
     * Optimize route using Google Routes API v2.
     *
     * @param  array{0: float, 1: float}  $origin  [lat, lng]
     * @param  array{0: float, 1: float}  $destination  [lat, lng]
     * @param  array<int, array{0: float, 1: float}>  $intermediates  Waypoints
     * @param  bool  $optimizeOrder  Whether to let Google optimize waypoint order
     * @return array{optimizedOrder: int[], durations: int[], polyline: string}
     */
    public function optimizeRoute(array $origin, array $destination, array $intermediates, bool $optimizeOrder = true, ?CarbonImmutable $departureAt = null): array
    {
        if (count($intermediates) > self::MAX_INTERMEDIATE_WAYPOINTS) {
            throw new RouteOptimizationException(
                'La ruta tiene más de '.self::MAX_INTERMEDIATE_WAYPOINTS.' paradas. Reducí las paradas antes de optimizarla.'
            );
        }

        try {
            $payload = $this->buildPayload($origin, $destination, $intermediates, $optimizeOrder, $departureAt);
            $response = $this->callGoogleAPI($payload);

            return $this->parseResponse($response, count($intermediates), $optimizeOrder);
        } catch (RouteOptimizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Google Routes request failed while optimizing delivery route.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'waypoint_count' => count($intermediates),
                'optimize_order' => $optimizeOrder,
            ]);

            throw new RouteOptimizationException(
                'No se pudo optimizar la ruta en este momento. Conservamos el recorrido anterior; intentá nuevamente.'
            );
        }
    }

    /**
     * Request a route shape exclusively for immediate display.
     *
     * This intentionally does not optimize waypoint order and the returned
     * polyline must not be persisted by callers.
     *
     * @param  array{0: float, 1: float}  $origin
     * @param  array{0: float, 1: float}  $destination
     * @param  array<int, array{0: float, 1: float}>  $intermediates
     */
    public function routeGeometry(array $origin, array $destination, array $intermediates): string
    {
        if (count($intermediates) > self::MAX_INTERMEDIATE_WAYPOINTS) {
            throw new RouteOptimizationException(
                'La ruta tiene más de '.self::MAX_INTERMEDIATE_WAYPOINTS.' paradas. No se puede mostrar el recorrido vial todavía.'
            );
        }

        try {
            $payload = $this->buildPayload($origin, $destination, $intermediates, false);
            // Geometry-only rendering does not need a traffic-sensitive ETA.
            // This also keeps viewer calls on the lower-latency route preference.
            $payload['routingPreference'] = 'TRAFFIC_UNAWARE';
            $response = $this->callGoogleAPI(
                $payload,
                'routes.polyline.encodedPolyline'
            );
            $polyline = $response['routes'][0]['polyline']['encodedPolyline'] ?? null;

            if (! is_string($polyline) || $polyline === '') {
                throw new RouteOptimizationException('Google no devolvió una geometría válida para el recorrido.');
            }

            return $polyline;
        } catch (RouteOptimizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Google Routes request failed while rendering delivery route geometry.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'waypoint_count' => count($intermediates),
            ]);

            throw new RouteOptimizationException('No se pudo obtener el recorrido en este momento. Intentá nuevamente.');
        }
    }

    /**
     * Calculate ETAs for stops given departure time, durations, and unload time.
     *
     * ETA for stop i = departure_time + sum(durations[0..i]) + (i * unload_time_for_previous_stops)
     *
     * @param  string  $departureTime  Format "H:i"
     * @param  int[]  $durationsSeconds  Leg durations in seconds
     * @param  int  $unloadTimeMinutes  Time spent at each stop before proceeding
     * @param  int[]  $stopOrder  The order of stop indices
     * @param  string  $operationalDate  Date in "Y-m-d" format to prepend to ETAs
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
            $etas[$stopIndex] = $operationalDate.' '.$this->formatSecondsToTime($totalSeconds);
        }

        return $etas;
    }

    /**
     * Build the Google Routes API v2 request payload.
     */
    private function buildPayload(array $origin, array $destination, array $intermediates, bool $optimizeOrder, ?CarbonImmutable $departureAt = null): array
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

        if ($departureAt) {
            $payload['departureTime'] = $departureAt->utc()->toRfc3339String();
        }

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
    private function callGoogleAPI(
        array $payload,
        string $fieldMask = 'routes.optimizedIntermediateWaypointIndex,routes.legs.duration,routes.polyline.encodedPolyline'
    ): array {
        $response = Http::withHeaders([
            'X-Goog-FieldMask' => $fieldMask,
            'X-Goog-Api-Key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl, $payload);

        if (! $response->successful()) {
            $error = $response->json('error') ?? [];
            Log::warning('Google Routes returned an unsuccessful response.', [
                'status' => $response->status(),
                'provider_status' => $error['status'] ?? null,
                'provider_code' => $error['code'] ?? null,
            ]);

            throw new RouteOptimizationException(
                'No se pudo optimizar la ruta en este momento. Conservamos el recorrido anterior; intentá nuevamente.'
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Parse Google response: extract optimized order, durations, polyline.
     */
    private function parseResponse(array $response, int $waypointCount, bool $optimizeOrder): array
    {
        $route = $response['routes'][0] ?? [];
        if (! is_array($route) || $route === []) {
            return $this->invalidProviderResponse('missing_route', $waypointCount, $optimizeOrder);
        }

        $optimizedOrder = $route['optimizedIntermediateWaypointIndex'] ?? null;

        if (! $optimizeOrder) {
            $optimizedOrder = range(0, max(0, $waypointCount - 1));
        } elseif (! is_array($optimizedOrder)
            || count($optimizedOrder) !== $waypointCount
            || count(array_unique($optimizedOrder, SORT_REGULAR)) !== $waypointCount
            || collect($optimizedOrder)->contains(fn ($index) => ! is_int($index) || $index < 0 || $index >= $waypointCount)) {
            return $this->invalidProviderResponse('invalid_optimized_waypoint_indices', $waypointCount, $optimizeOrder);
        }

        $legs = $route['legs'] ?? [];
        if (! is_array($legs) || count($legs) !== $waypointCount + 1) {
            return $this->invalidProviderResponse('invalid_leg_count', $waypointCount, $optimizeOrder);
        }

        $durations = [];
        foreach ($legs as $leg) {
            $durationStr = is_array($leg) ? ($leg['duration'] ?? null) : null;
            if (! is_string($durationStr) || ! preg_match('/^(\\d+(?:\\.\\d+)?)s$/', $durationStr, $matches)) {
                return $this->invalidProviderResponse('invalid_leg_duration', $waypointCount, $optimizeOrder);
            }
            $durations[] = (int) ceil((float) $matches[1]);
        }

        // Global polyline for the entire route (store → stops → store)
        $polyline = $route['polyline']['encodedPolyline'] ?? '';
        if (! is_string($polyline) || $polyline === '') {
            return $this->invalidProviderResponse('missing_polyline', $waypointCount, $optimizeOrder);
        }

        return [
            'optimizedOrder' => $optimizedOrder,
            'durations' => $durations,
            'polyline' => $polyline,
        ];
    }

    private function invalidProviderResponse(string $reason, int $waypointCount, bool $optimizeOrder): never
    {
        Log::warning('Google Routes returned an invalid optimization response.', [
            'reason' => $reason,
            'waypoint_count' => $waypointCount,
            'optimize_order' => $optimizeOrder,
        ]);

        throw new RouteOptimizationException(
            'Google no devolvió un recorrido válido. Conservamos el recorrido anterior; intentá nuevamente.'
        );
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
