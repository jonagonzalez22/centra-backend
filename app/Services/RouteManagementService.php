<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\CustomerAddress;
use App\Models\DeliveryRoute;
use App\Models\DeliveryRouteEvent;
use App\Models\RouteStop;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\RouteOptimizationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RouteManagementService
{
    /**
     * Create a new delivery route.
     */
    public function create(array $data, string $storeId, User $user): DeliveryRoute
    {
        return DB::transaction(function () use ($data, $storeId, $user) {
            $this->validateVehicle($data['vehicle_id'], $storeId, $data['operational_date']);
            $this->validateDriver($data['driver_id'], $storeId);

            $route = DeliveryRoute::create([
                'store_id' => $storeId,
                'vehicle_id' => $data['vehicle_id'],
                'driver_id' => $data['driver_id'],
                'operational_date' => $data['operational_date'],
                'status' => 'draft',
                'observations' => $data['observations'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->createEvent($route, $storeId, $user->id, 'created');

            return $route;
        });
    }

    /**
     * Update an existing draft delivery route.
     */
    public function update(DeliveryRoute $route, array $data): DeliveryRoute
    {
        if ($route->status !== 'draft') {
            throw $this->validationError('Solo se pueden editar rutas en estado draft.');
        }

        return DB::transaction(function () use ($route, $data) {
            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();

            $vehicleId = $data['vehicle_id'] ?? $route->vehicle_id;
            $operationalDate = $data['operational_date'] ?? $route->operational_date->format('Y-m-d');

            if (isset($data['vehicle_id']) || isset($data['operational_date'])) {
                $this->validateVehicle($vehicleId, $route->store_id, $operationalDate, $route->id);
            }

            if (isset($data['driver_id'])) {
                $this->validateDriver($data['driver_id'], $route->store_id);
            }

            $route->update($data);

            return $route;
        });
    }

    /**
     * Query eligible orders for route assignment.
     */
    public function eligibleOrders(string $storeId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = CommercialOperation::forStore($storeId)
            ->byType('order')
            ->whereNotIn('status', ['cancelled', 'closed'])
            ->whereHas('customer', function (Builder $q) {
                $q->whereHas('addresses', function (Builder $aq) {
                    $aq->where('is_main', true)
                        ->whereNotNull('latitude')
                        ->whereNotNull('longitude');
                });
            })
            ->whereNotIn('id', function ($subQuery) {
                $subQuery->select('order_id')
                    ->from('route_stops')
                    ->where('status', '!=', 'cancelled');
            });

        if (! empty($filters['requested_delivery_date'])) {
            $query->whereDate('requested_delivery_date', $filters['requested_delivery_date']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('operation_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', function (Builder $cq) use ($search) {
                        $cq->where('display_name', 'like', "%{$search}%")
                            ->orWhere('search_text', 'like', "%{$search}%");
                    });
            });
        }

        if (! empty($filters['locality_id'])) {
            $query->whereHas('customer.addresses', function (Builder $q) use ($filters) {
                $q->where('locality_id', $filters['locality_id']);
            });
        }

        return $query->with(['customer', 'customer.addresses.locality'])
            ->orderBy('requested_delivery_date')
            ->orderBy('operation_number')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Add a stop (order) to a route.
     */
    public function addStop(DeliveryRoute $route, CommercialOperation $order, array $data, User $user): RouteStop
    {
        return DB::transaction(function () use ($route, $order, $data, $user) {
            if ($route->status !== 'draft') {
                throw $this->validationError('Solo se pueden modificar los stops en rutas draft.');
            }

            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();
            $order = CommercialOperation::where('id', $order->id)->lockForUpdate()->first();

            $this->validateOrderEligible($order, $route->store_id);

            // Exceptional assignment: date mismatch requires reason
            $orderDate = $order->requested_delivery_date?->format('Y-m-d');
            $routeDate = $route->operational_date->format('Y-m-d');

            if ($orderDate && $orderDate !== $routeDate) {
                if (empty($data['reason'])) {
                    throw $this->validationError('Se requiere un motivo cuando la fecha del pedido difiere de la fecha operativa de la ruta.');
                }
            }

            // Calculate next sequence
            $maxSequence = RouteStop::where('route_id', $route->id)->max('sequence') ?? 0;

            $stop = RouteStop::create([
                'route_id' => $route->id,
                'order_id' => $order->id,
                'sequence' => $maxSequence + 1,
                'status' => 'pending',
                'logistics_notes' => $data['logistics_notes'] ?? null,
            ]);

            $metadata = ['stop_id' => $stop->id, 'order_id' => $order->id];
            if (! empty($data['reason'])) {
                $metadata['reason'] = $data['reason'];
                $metadata['exceptional'] = true;
            }

            $this->createEvent($route, $route->store_id, $user->id, 'stop_added', null, null, $data['reason'] ?? null, $metadata);

            return $stop;
        });
    }

    /**
     * Remove (cancel) a stop from a route.
     */
    public function removeStop(DeliveryRoute $route, RouteStop $stop, array $data, User $user): RouteStop
    {
        return DB::transaction(function () use ($route, $stop, $data, $user) {
            if ($route->status !== 'draft') {
                throw $this->validationError('Solo se pueden modificar los stops en rutas draft.');
            }

            if ($stop->route_id !== $route->id) {
                throw $this->validationError('El stop no pertenece a esta ruta.');
            }

            if ($stop->status === 'cancelled') {
                throw $this->validationError('El stop ya está cancelado.');
            }

            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();

            // Check not last active stop
            $activeCount = RouteStop::where('route_id', $route->id)
                ->where('status', '!=', 'cancelled')
                ->count();

            if ($activeCount <= 1) {
                throw $this->validationError('No se puede cancelar el último stop activo. Cancelá la ruta en su lugar.');
            }

            $stop->update([
                'status' => 'cancelled',
                'logistics_notes' => $data['logistics_notes'] ?? null,
            ]);

            $this->createEvent(
                $route,
                $route->store_id,
                $user->id,
                'stop_removed',
                'pending',
                'cancelled',
                $data['reason'] ?? null,
                ['stop_id' => $stop->id, 'order_id' => $stop->order_id]
            );

            // Renormalize sequences
            $this->renormalizeSequences($route->id);

            return $stop;
        });
    }

    /**
     * Atomically reorder stops via full array replacement.
     * Accepts both draft and planned routes.
     * For planned routes: sets requires_recalculation and clears ETAs.
     */
    public function reorderStops(DeliveryRoute $route, array $orderedStopIds, User $user): void
    {
        DB::transaction(function () use ($route, $orderedStopIds, $user) {
            if (! in_array($route->status, ['draft', 'planned'])) {
                throw $this->validationError('Solo se pueden reordenar los stops en rutas draft o planned.');
            }

            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();

            $activeStops = RouteStop::where('route_id', $route->id)
                ->where('status', '!=', 'cancelled')
                ->get();

            if (count($activeStops) !== count($orderedStopIds)) {
                throw $this->validationError('Todos los stops activos deben estar incluidos en el reordenamiento.');
            }

            $activeStopIds = $activeStops->pluck('id')->toArray();
            foreach ($orderedStopIds as $stopId) {
                if (! in_array($stopId, $activeStopIds)) {
                    throw $this->validationError("El stop {$stopId} no es un stop activo de esta ruta.");
                }
            }

            foreach ($orderedStopIds as $index => $stopId) {
                RouteStop::where('id', $stopId)
                    ->where('route_id', $route->id)
                    ->update(['sequence' => -($index + 1)]);
            }

            foreach ($orderedStopIds as $index => $stopId) {
                RouteStop::where('id', $stopId)
                    ->where('route_id', $route->id)
                    ->update(['sequence' => $index + 1]);
            }

            if ($route->status === 'planned') {
                // Clear calculated fields since order changed
                $route->update(['requires_recalculation' => true]);

                RouteStop::where('route_id', $route->id)->update([
                    'estimated_arrival_at' => null,
                    'travel_duration_seconds' => null,
                ]);

                $this->createEvent(
                    $route,
                    $route->store_id,
                    $user->id,
                    'stops_reordered_planned',
                    null,
                    null,
                    null,
                    ['previous_order' => $activeStops->pluck('id', 'sequence')->toArray(), 'new_order' => array_flip($orderedStopIds)]
                );
            } else {
                $this->createEvent(
                    $route,
                    $route->store_id,
                    $user->id,
                    'stops_reordered',
                    null,
                    null,
                    null,
                    ['previous_order' => $activeStops->pluck('id', 'sequence')->toArray(), 'new_order' => array_flip($orderedStopIds)]
                );
            }
        });
    }

    /**
     * Transition route from draft to planned with Google Routes optimization.
     */
    public function plan(DeliveryRoute $route, string $departureTime, User $user): DeliveryRoute
    {
        return DB::transaction(function () use ($route, $departureTime, $user) {
            if ($route->status !== 'draft') {
                throw $this->validationError('Solo se pueden planificar rutas en estado draft.');
            }

            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();

            $activeStops = RouteStop::where('route_id', $route->id)
                ->where('status', '!=', 'cancelled')
                ->orderBy('sequence')
                ->get();

            if ($activeStops->count() < 1) {
                throw $this->validationError('La ruta debe tener al menos un stop para ser planificada.');
            }

            // Validate store has coordinates
            $store = Store::find($route->store_id);
            if (! $store || ! $store->latitude || ! $store->longitude) {
                throw $this->validationError('La tienda no tiene coordenadas configuradas.');
            }

            // Validate all stops have geolocated delivery addresses
            $this->validateStopCoordinates($activeStops);

            // Get store unload time
            $unloadTime = $store->getUnloadTimeMinutes();

            // Build waypoints from active stops in current sequence order
            $origin = [(float) $store->latitude, (float) $store->longitude];
            $destination = [(float) $store->latitude, (float) $store->longitude]; // round trip
            $intermediates = [];
            $stopIdMap = [];

            foreach ($activeStops as $stop) {
                $address = $this->getStopMainAddress($stop);
                $intermediates[] = [(float) $address->latitude, (float) $address->longitude];
                $stopIdMap[] = $stop->id;
            }

            // Call Google Routes API with optimization
            $optimizer = new RouteOptimizationService();
            $result = $optimizer->optimizeRoute($origin, $destination, $intermediates, true);

            $optimizedOrder = $result['optimizedOrder'];
            $durations = $result['durations'];
            $polyline = $result['polyline'];

            // Reorder stops based on optimized order
            $this->applyOptimizedOrder($route->id, $stopIdMap, $optimizedOrder);

            // Calculate ETAs
            $etas = $optimizer->calculateETAs($departureTime, $durations, $unloadTime, $optimizedOrder);

            // Persist ETAs, durations, and route data
            for ($i = 0; $i < count($optimizedOrder); $i++) {
                $stopIndex = $optimizedOrder[$i];
                $stopId = $stopIdMap[$stopIndex];

                RouteStop::where('id', $stopId)
                    ->where('route_id', $route->id)
                    ->update([
                        'estimated_arrival_at' => $etas[$i] ?? null,
                        'travel_duration_seconds' => $durations[$i] ?? 0,
                    ]);
            }

            $route->update([
                'status' => 'planned',
                'planned_at' => now(),
                'departure_time' => $departureTime,
                'encoded_polyline' => $polyline,
                'unload_time_minutes_snapshot' => $unloadTime,
                'requires_recalculation' => false,
            ]);

            $this->createEvent(
                $route,
                $route->store_id,
                $user->id,
                'planned',
                'draft',
                'planned',
                null,
                [
                    'departure_time' => $departureTime,
                    'optimized' => true,
                    'unload_snapshot' => $unloadTime,
                ]
            );

            return $route;
        });
    }

    /**
     * Revert route from planned back to draft, clearing all calculated fields.
     */
    public function revert(DeliveryRoute $route, string $reason, ?string $observation, User $user): DeliveryRoute
    {
        return DB::transaction(function () use ($route, $reason, $observation, $user) {
            if ($route->status !== 'planned') {
                throw $this->validationError('Solo se pueden revertir rutas planificadas.');
            }

            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();

            // Clear calculated fields on route
            $route->update([
                'status' => 'draft',
                'observations' => $observation ?? $route->observations,
                'planned_at' => null,
                'departure_time' => null,
                'encoded_polyline' => null,
                'unload_time_minutes_snapshot' => null,
                'requires_recalculation' => false,
            ]);

            // Clear calculated fields on all stops
            RouteStop::where('route_id', $route->id)->update([
                'estimated_arrival_at' => null,
                'travel_duration_seconds' => null,
            ]);

            $this->createEvent($route, $route->store_id, $user->id, 'reverted', 'planned', 'draft', $reason);

            return $route;
        });
    }

    /**
     * Cancel a route and all its active stops.
     */
    public function cancel(DeliveryRoute $route, string $reason, User $user): DeliveryRoute
    {
        return DB::transaction(function () use ($route, $reason, $user) {
            if (! in_array($route->status, ['draft', 'planned'])) {
                throw $this->validationError('Solo se pueden cancelar rutas en estado draft o planned.');
            }

            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();

            $fromStatus = $route->status;

            // Cancel all active stops to free orders
            RouteStop::where('route_id', $route->id)
                ->where('status', '!=', 'cancelled')
                ->update(['status' => 'cancelled']);

            $route->update(['status' => 'cancelled']);

            $this->createEvent($route, $route->store_id, $user->id, 'cancelled', $fromStatus, 'cancelled', $reason);

            return $route;
        });
    }

    /**
     * Recalculate a planned route that requires recalculation.
     * Uses original departure time and unload snapshot. Respects current stop order.
     */
    public function recalculate(DeliveryRoute $route, User $user): DeliveryRoute
    {
        return DB::transaction(function () use ($route, $user) {
            if ($route->status !== 'planned') {
                throw $this->validationError('Solo se pueden recalcular rutas planificadas.');
            }

            if (! $route->requires_recalculation) {
                throw $this->validationError('La ruta no requiere recálculo.');
            }

            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();

            $activeStops = RouteStop::where('route_id', $route->id)
                ->where('status', '!=', 'cancelled')
                ->orderBy('sequence')
                ->get();

            if ($activeStops->count() < 1) {
                throw $this->validationError('La ruta debe tener al menos un stop para ser recalculada.');
            }

            // Validate store coordinates
            $store = Store::find($route->store_id);
            if (! $store || ! $store->latitude || ! $store->longitude) {
                throw $this->validationError('La tienda no tiene coordenadas configuradas.');
            }

            // Validate all stops have geolocated addresses
            $this->validateStopCoordinates($activeStops);

            $departureTime = $route->departure_time;
            $unloadTime = $route->unload_time_minutes_snapshot ?? 15;

            // Build waypoints in CURRENT sequence order (don't optimize)
            $origin = [(float) $store->latitude, (float) $store->longitude];
            $destination = [(float) $store->latitude, (float) $store->longitude];
            $intermediates = [];

            foreach ($activeStops as $stop) {
                $address = $this->getStopMainAddress($stop);
                $intermediates[] = [(float) $address->latitude, (float) $address->longitude];
            }

            // Call Google Routes API WITHOUT optimization (respect manual order)
            $optimizer = new RouteOptimizationService();
            $result = $optimizer->optimizeRoute($origin, $destination, $intermediates, false);

            $durations = $result['durations'];
            $polyline = $result['polyline'];

            // Calculate ETAs using existing order (0, 1, 2...)
            $stopCount = $activeStops->count();
            $originalOrder = range(0, $stopCount - 1);
            $etas = $optimizer->calculateETAs($departureTime, $durations, $unloadTime, $originalOrder);

            // Persist new ETAs
            foreach ($activeStops as $i => $stop) {
                RouteStop::where('id', $stop->id)
                    ->where('route_id', $route->id)
                    ->update([
                        'estimated_arrival_at' => $etas[$i] ?? null,
                        'travel_duration_seconds' => $durations[$i] ?? 0,
                    ]);
            }

            $route->update([
                'encoded_polyline' => $polyline,
                'requires_recalculation' => false,
            ]);

            $this->createEvent(
                $route,
                $route->store_id,
                $user->id,
                'route_recalculated',
                null,
                null,
                null,
                ['departure_time' => $departureTime, 'unload_snapshot' => $unloadTime]
            );

            return $route;
        });
    }

    // ── Private helpers ──────────────────────────────────────────────

    /**
     * Validate vehicle belongs to store, is active, and not double-booked.
     */
    private function validateVehicle(string $vehicleId, string $storeId, string $date, ?string $excludeRouteId = null): void
    {
        $vehicle = Vehicle::forStore($storeId)->find($vehicleId);

        if (! $vehicle) {
            throw $this->validationError('El vehículo no pertenece a la tienda.');
        }

        if (! $vehicle->is_active) {
            throw $this->validationError('El vehículo no está activo.');
        }

        // Check for double-booking: same vehicle, same date, active status
        $doubleBookingQuery = DeliveryRoute::where('vehicle_id', $vehicleId)
            ->whereDate('operational_date', $date)
            ->whereIn('status', ['draft', 'planned']);

        if ($excludeRouteId) {
            $doubleBookingQuery->where('id', '!=', $excludeRouteId);
        }

        if ($doubleBookingQuery->exists()) {
            throw $this->validationError('El vehículo ya tiene una ruta activa en esta fecha.');
        }
    }

    /**
     * Validate driver belongs to store and has STORE_DRIVER role.
     */
    private function validateDriver(string $driverId, string $storeId): void
    {
        $driver = User::where('id', $driverId)
            ->where('store_id', $storeId)
            ->first();

        if (! $driver) {
            throw $this->validationError('El conductor no pertenece a la tienda.');
        }

        if (! $driver->is_active) {
            throw $this->validationError('El conductor no está activo.');
        }

        if (! $driver->hasRole('STORE_DRIVER')) {
            throw $this->validationError('El usuario no tiene el rol de conductor.');
        }
    }

    /**
     * Validate order is eligible for route assignment.
     */
    private function validateOrderEligible(CommercialOperation $order, string $storeId): void
    {
        if ($order->store_id !== $storeId) {
            throw $this->validationError('El pedido no pertenece a la tienda.');
        }

        if ($order->type !== 'order') {
            throw $this->validationError('Solo se pueden asignar pedidos a una ruta.');
        }

        if (in_array($order->status, ['cancelled', 'closed'])) {
            throw $this->validationError('El pedido está cancelado o cerrado.');
        }

        // Check order has a geolocated delivery address
        $customer = $order->customer()->with('addresses')->first();
        if (! $customer) {
            throw $this->validationError('El pedido no tiene cliente asignado.');
        }

        $mainAddress = $customer->addresses->firstWhere('is_main', true);
        if (! $mainAddress || ! $mainAddress->latitude || ! $mainAddress->longitude) {
            throw $this->validationError('El pedido no tiene una dirección de entrega geolocalizada.');
        }

        // Check not already in an active route
        $alreadyAssigned = RouteStop::where('order_id', $order->id)
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($alreadyAssigned) {
            throw $this->validationError('El pedido ya está asignado a una ruta activa.');
        }
    }

    /**
     * Renormalize sequence numbers after a stop cancellation.
     */
    private function renormalizeSequences(string $routeId): void
    {
        // Phase 0: Move all cancelled stops' sequences out of the way (use NULL or negative)
        RouteStop::where('route_id', $routeId)
            ->where('status', 'cancelled')
            ->update(['sequence' => 0]);

        // Phase 1: move all active stops to temporary negative sequences
        $activeStops = RouteStop::where('route_id', $routeId)
            ->where('status', '!=', 'cancelled')
            ->orderBy('sequence')
            ->get();

        foreach ($activeStops as $index => $stop) {
            RouteStop::where('id', $stop->id)->update(['sequence' => -($index + 1)]);
        }

        // Phase 2: reassign to final positive sequences
        foreach ($activeStops as $index => $stop) {
            RouteStop::where('id', $stop->id)->update(['sequence' => $index + 1]);
        }
    }

    /**
     * Create an immutable event record.
     */
    private function createEvent(
        DeliveryRoute $route,
        string $storeId,
        string $userId,
        string $eventType,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $reason = null,
        ?array $metadata = null
    ): DeliveryRouteEvent {
        return DeliveryRouteEvent::create([
            'store_id' => $storeId,
            'route_id' => $route->id,
            'user_id' => $userId,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Build a validation error response.
     */
    private function validationError(string $message): HttpResponseException
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 'error',
                'message' => $message,
                'data' => null,
                'errors' => ['error' => [$message]],
            ], 422)
        );
    }

    /**
     * Validate that all active stops have geolocated delivery addresses.
     */
    private function validateStopCoordinates(\Illuminate\Database\Eloquent\Collection $stops): void
    {
        foreach ($stops as $stop) {
            $address = $this->getStopMainAddress($stop);

            if (! $address || ! $address->latitude || ! $address->longitude) {
                throw $this->validationError('Uno o más pedidos no tienen una dirección de entrega geolocalizada.');
            }
        }
    }

    /**
     * Get the main delivery address for a stop's order.
     */
    private function getStopMainAddress(RouteStop $stop): ?CustomerAddress
    {
        $order = $stop->order()->with(['customer.addresses'])->first();

        if (! $order || ! $order->customer) {
            return null;
        }

        return $order->customer->addresses->firstWhere('is_main', true);
    }

    /**
     * Apply Google's optimized stop order to the route.
     * Uses temporary negative sequences to avoid UNIQUE constraint conflicts.
     */
    private function applyOptimizedOrder(string $routeId, array $stopIdMap, array $optimizedOrder): void
    {
        // Phase 1: move to temporary negative sequences
        foreach ($optimizedOrder as $newIndex => $oldIndex) {
            $stopId = $stopIdMap[$oldIndex];
            RouteStop::where('id', $stopId)
                ->where('route_id', $routeId)
                ->update(['sequence' => -($newIndex + 1)]);
        }

        // Phase 2: reassign to final positive sequences
        foreach ($optimizedOrder as $newIndex => $oldIndex) {
            $stopId = $stopIdMap[$oldIndex];
            RouteStop::where('id', $stopId)
                ->where('route_id', $routeId)
                ->update(['sequence' => $newIndex + 1]);
        }
    }
}
