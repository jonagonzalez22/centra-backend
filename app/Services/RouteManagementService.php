<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\CustomerAddress;
use App\Models\DeliveryDiscrepancy;
use App\Models\DeliveryRoute;
use App\Models\DeliveryRouteEvent;
use App\Models\InventoryMovement;
use App\Models\OperationPayment;
use App\Models\Product;
use App\Models\RouteLoadAdjustment;
use App\Models\RouteStop;
use App\Models\RouteStopCollection;
use App\Models\RouteStopItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\InventoryMovementService;
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

            // Prevent duplicate stop for the same order in the same route
            $duplicateInSameRoute = RouteStop::where('route_id', $route->id)
                ->where('order_id', $order->id)
                ->where('status', '!=', 'cancelled')
                ->exists();

            if ($duplicateInSameRoute) {
                throw $this->validationError('El pedido ya tiene una parada activa en esta ruta.');
            }

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
            $etas = $optimizer->calculateETAs($departureTime, $durations, $unloadTime, $optimizedOrder, $route->operational_date->format('Y-m-d'));

            // Persist ETAs, durations, and route data
            for ($i = 0; $i < count($optimizedOrder); $i++) {
                $stopIndex = $optimizedOrder[$i];
                if (! isset($stopIdMap[$stopIndex])) {
                    continue;
                }
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
            if (! in_array($route->status, ['draft', 'planned', 'loaded'])) {
                if ($route->status === 'dispatched') {
                    throw $this->validationError('No se puede cancelar una ruta despachada.');
                }
                throw $this->validationError('Solo se pueden cancelar rutas en estado draft, planned o loaded.');
            }

            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();

            $fromStatus = $route->status;

            // Cancel all active stops to free orders
            RouteStop::where('route_id', $route->id)
                ->where('status', '!=', 'cancelled')
                ->update(['status' => 'cancelled']);

            $route->update(['status' => 'cancelled']);

            $metadata = [];

            // If cancelling from loaded, log the need for physical return
            if ($fromStatus === 'loaded') {
                $metadata['physical_return_required'] = true;
                $metadata['note'] = 'La mercadería cargada debe ser devuelta físicamente al depósito.';
            }

            $this->createEvent($route, $route->store_id, $user->id, 'cancelled', $fromStatus, 'cancelled', $reason, $metadata ?: null);

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
            $etas = $optimizer->calculateETAs($departureTime, $durations, $unloadTime, $originalOrder, $route->operational_date->format('Y-m-d'));

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

    // ── Execution Methods ────────────────────────────────────────────

    /**
     * Assign items (products) to a stop in a draft route.
     * Validates that the total planned quantity across all active routes
     * does not exceed the order item quantity.
     */
    public function assignItems(DeliveryRoute $route, RouteStop $stop, array $items, User $user): void
    {
        DB::transaction(function () use ($route, $stop, $items, $user) {
            if ($route->status !== 'draft') {
                throw $this->validationError('Solo se pueden asignar items en rutas draft.');
            }

            if ($stop->route_id !== $route->id) {
                throw $this->validationError('El stop no pertenece a esta ruta.');
            }

            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();
            $stop = RouteStop::where('id', $stop->id)->lockForUpdate()->first();

            // Load order items for validation
            $order = CommercialOperation::with('items')->find($stop->order_id);
            if (! $order) {
                throw $this->validationError('El pedido asociado al stop no existe.');
            }

            foreach ($items as $item) {
                $productId = $item['product_id'];
                $quantityPlanned = (int) $item['quantity_planned'];

                if ($quantityPlanned < 1) {
                    throw $this->validationError('La cantidad planificada debe ser al menos 1.');
                }

                // Validate product exists in the order
                $orderItem = $order->items->firstWhere('product_id', $productId);
                if (! $orderItem) {
                    throw $this->validationError("El producto {$productId} no pertenece a este pedido.");
                }

                // Calculate already-assigned quantity for this product across ALL active routes
                $alreadyAssigned = RouteStopItem::where('product_id', $productId)
                    ->whereHas('routeStop', function (Builder $q) use ($stop) {
                        $q->where('order_id', $stop->order_id)
                            ->where('status', '!=', 'cancelled');
                    })
                    ->whereNot('route_stop_id', $stop->id) // exclude current stop (for updates)
                    ->sum('quantity_planned');

                $totalAfter = $alreadyAssigned + $quantityPlanned;

                if ($totalAfter > $orderItem->quantity) {
                    throw $this->validationError(
                        "La cantidad planificada ({$quantityPlanned}) más la ya asignada ({$alreadyAssigned}) "
                        ."supera la cantidad del pedido ({$orderItem->quantity}) para el producto."
                    );
                }

                // Create or update (unique on route_stop_id + product_id)
                RouteStopItem::updateOrCreate(
                    [
                        'route_stop_id' => $stop->id,
                        'product_id' => $productId,
                    ],
                    [
                        'quantity_planned' => $quantityPlanned,
                        'quantity_loaded' => 0,
                        'quantity_delivered' => 0,
                    ]
                );
            }

            $this->createEvent(
                $route,
                $route->store_id,
                $user->id,
                'items_assigned',
                null,
                null,
                null,
                ['stop_id' => $stop->id, 'item_count' => count($items)]
            );
        });
    }

    /**
     * Get a consolidated load sheet for warehouse picking.
     */
    public function getLoadSheet(DeliveryRoute $route): array
    {
        $stops = $route->stops()
            ->with(['items.product', 'order'])
            ->where('status', '!=', 'cancelled')
            ->orderBy('sequence')
            ->get();

        $byProduct = [];
        $byStop = [];

        foreach ($stops as $stop) {
            $stopItems = [];
            foreach ($stop->items as $item) {
                $productId = $item->product_id;
                $productName = $item->product?->name ?? 'Producto desconocido';

                $stopItems[] = [
                    'route_stop_item_id' => $item->id,
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'quantity_planned' => $item->quantity_planned,
                    'quantity_loaded' => $item->quantity_loaded,
                ];

                if (! isset($byProduct[$productId])) {
                    $byProduct[$productId] = [
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'total_planned' => 0,
                        'total_loaded' => 0,
                    ];
                }

                $byProduct[$productId]['total_planned'] += $item->quantity_planned;
                $byProduct[$productId]['total_loaded'] += $item->quantity_loaded;
            }

            if (! empty($stopItems)) {
                $byStop[] = [
                    'stop_id' => $stop->id,
                    'sequence' => $stop->sequence,
                    'order_number' => $stop->order?->operation_number,
                    'customer_name' => $stop->order?->customer?->display_name ?? $stop->order?->customer?->name,
                    'items' => $stopItems,
                ];
            }
        }

        return [
            'route_id' => $route->id,
            'status' => $route->status,
            'operational_date' => $route->operational_date?->format('Y-m-d'),
            'by_product' => array_values($byProduct),
            'by_stop' => $byStop,
            'total_items' => array_sum(array_column($byProduct, 'total_planned')),
        ];
    }

    /**
     * Confirm load: transition route from planned to loaded.
     * Records adjustments when loaded quantities differ from planned.
     */
    public function confirmLoad(DeliveryRoute $route, array $loadedQuantities, User $user): DeliveryRoute
    {
        return DB::transaction(function () use ($route, $loadedQuantities, $user) {
            if ($route->status !== 'planned') {
                throw $this->validationError('Solo se puede confirmar carga de rutas planificadas.');
            }

            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();

            $hasLoaded = false;

            foreach ($loadedQuantities as $entry) {
                $routeStopItemId = $entry['route_stop_item_id'];
                $quantityLoaded = (int) $entry['quantity_loaded'];

                $stopItem = RouteStopItem::where('id', $routeStopItemId)
                    ->whereHas('routeStop', function (Builder $q) use ($route) {
                        $q->where('route_id', $route->id)
                            ->where('status', '!=', 'cancelled');
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $stopItem) {
                    throw $this->validationError("El item {$routeStopItemId} no pertenece a esta ruta.");
                }

                if ($quantityLoaded > $stopItem->quantity_planned) {
                    throw $this->validationError(
                        "La cantidad cargada ({$quantityLoaded}) no puede superar la planificada ({$stopItem->quantity_planned})."
                    );
                }

                if ($quantityLoaded < $stopItem->quantity_planned) {
                    if (empty($entry['reason'])) {
                        throw $this->validationError(
                            'Se requiere un motivo cuando la cantidad cargada es menor a la planificada.'
                        );
                    }
                }

                // Create adjustment if quantity changed
                $oldQuantity = $stopItem->quantity_loaded;
                if ($oldQuantity !== $quantityLoaded) {
                    RouteLoadAdjustment::create([
                        'route_stop_item_id' => $stopItem->id,
                        'user_id' => $user->id,
                        'old_quantity' => $oldQuantity,
                        'new_quantity' => $quantityLoaded,
                        'reason' => $entry['reason'] ?? 'other',
                        'notes' => $entry['notes'] ?? null,
                    ]);
                }

                $stopItem->update(['quantity_loaded' => $quantityLoaded]);

                if ($quantityLoaded > 0) {
                    $hasLoaded = true;
                }
            }

            if (! $hasLoaded) {
                throw $this->validationError('Al menos un producto debe tener cantidad cargada mayor a cero.');
            }

            $route->update([
                'status' => 'loaded',
                'loaded_at' => now(),
                'loaded_by' => $user->id,
            ]);

            $this->createEvent(
                $route,
                $route->store_id,
                $user->id,
                'route_loaded',
                'planned',
                'loaded',
                null,
                ['item_count' => count($loadedQuantities)]
            );

            return $route;
        });
    }

    /**
     * Dispatch a loaded route: transition to dispatched.
     */
    public function dispatch(DeliveryRoute $route, User $user): DeliveryRoute
    {
        return DB::transaction(function () use ($route, $user) {
            if ($route->status !== 'loaded') {
                throw $this->validationError('Solo se pueden despachar rutas en estado loaded.');
            }

            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();

            // At least one stop must have items with quantity_loaded > 0
            $hasLoadedItems = RouteStopItem::whereHas('routeStop', function (Builder $q) use ($route) {
                $q->where('route_id', $route->id)
                    ->where('status', '!=', 'cancelled');
            })->where('quantity_loaded', '>', 0)->exists();

            if (! $hasLoadedItems) {
                throw $this->validationError('La ruta debe tener al menos un producto cargado para ser despachada.');
            }

            $route->update([
                'status' => 'dispatched',
                'dispatched_at' => now(),
                'dispatched_by' => $user->id,
            ]);

            $this->createEvent(
                $route,
                $route->store_id,
                $user->id,
                'route_dispatched',
                'loaded',
                'dispatched'
            );

            return $route;
        });
    }

    /**
     * Process deliveries: reconcile completed/failed stops, update inventory,
     * release stock_reserved, update order statuses, and complete the route.
     */
    public function processDeliveries(DeliveryRoute $route, User $user): DeliveryRoute
    {
        try {
            return $this->executeProcessDeliveries($route, $user);
        } catch (\InvalidArgumentException $e) {
            throw $this->validationError($e->getMessage());
        }
    }

    /**
     * Execute the processDeliveries logic inside a transaction.
     */
    private function executeProcessDeliveries(DeliveryRoute $route, User $user): DeliveryRoute
    {
        return DB::transaction(function () use ($route, $user) {
            // 1. Idempotency — check BEFORE status validation
            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();

            if ($route->processed_at !== null) {
                throw new HttpResponseException(
                    response()->json([
                        'status' => 'error',
                        'message' => 'La ruta ya fue procesada.',
                        'data' => null,
                        'errors' => ['error' => ['La ruta ya fue procesada.']],
                    ], 409)
                );
            }

            // 2. Validate pre-conditions
            if ($route->status !== 'awaiting_reconciliation') {
                throw $this->validationError('Solo se pueden procesar entregas de rutas en estado awaiting_reconciliation.');
            }

            // All stops must be completed, failed, or cancelled (no pending/arrived)
            $pendingExists = RouteStop::where('route_id', $route->id)
                ->whereNotIn('status', ['completed', 'failed', 'cancelled'])
                ->exists();

            if ($pendingExists) {
                throw $this->validationError('Hay paradas pendientes de completar.');
            }

            // Store_id match
            if ($route->store_id !== $user->store_id) {
                throw $this->validationError('La ruta no pertenece a la tienda del usuario.');
            }

            // 3. Process each completed stop
            $inventoryService = new InventoryMovementService();
            $orderDelivered = [];

            $completedStops = $route->stops()
                ->where('status', 'completed')
                ->with('items.product')
                ->get();

            foreach ($completedStops as $stop) {
                foreach ($stop->items as $item) {
                    if ($item->quantity_delivered <= 0) {
                        continue;
                    }

                    // a. Record inventory movement (output)
                    $inventoryService->recordMovement(
                        $item->product,
                        $user,
                        'output',
                        $item->quantity_delivered,
                        "Entrega ruta {$route->id} — Pedido {$stop->order_id}"
                    );

                    // b. Release stock_reserved
                    $product = Product::where('id', $item->product_id)->lockForUpdate()->first();
                    $newReserved = max(0, $product->stock_reserved - $item->quantity_delivered);
                    $product->update(['stock_reserved' => $newReserved]);

                    // c. Track delivered quantity per order
                    $orderDelivered[$stop->order_id] = ($orderDelivered[$stop->order_id] ?? 0) + $item->quantity_delivered;
                }
            }

            // 4. Update order statuses
            foreach ($orderDelivered as $orderId => $deliveredQty) {
                $order = CommercialOperation::find($orderId);

                if (! $order) {
                    continue;
                }

                $totalOrdered = $order->items()->sum('quantity');

                if ($deliveredQty >= $totalOrdered) {
                    $order->update(['status' => 'delivered']);
                } elseif ($deliveredQty > 0) {
                    $order->update(['status' => 'partially_delivered']);
                }
                // deliveredQty == 0 (failed stop): order status unchanged
            }

            // 5. Complete route
            $route->update([
                'status' => 'completed',
                'processed_at' => now(),
                'processed_by' => $user->id,
            ]);

            $this->createEvent(
                $route,
                $route->store_id,
                $user->id,
                'route_processed',
                'awaiting_reconciliation',
                'completed'
            );

            return $route;
        });
    }

    // ── Reconciliation Methods ───────────────────────────────────────

    /**
     * Get the full reconciliation summary for a route.
     * Read-only — no transaction needed.
     */
    public function getReconciliation(DeliveryRoute $route): array
    {
        if ($route->status !== 'awaiting_reconciliation') {
            throw $this->validationError('La ruta no está en estado de conciliación.');
        }

        $route->load([
            'stops' => fn ($q) => $q->where('status', '!=', 'cancelled')->orderBy('sequence'),
            'stops.items.product',
            'stops.items.discrepancy',
            'stops.order' => fn ($q) => $q->with(['customer', 'payments.storePaymentMethod.paymentMethod']),
            'stops.collections' => fn ($q) => $q->with(['storePaymentMethod.paymentMethod', 'declaredBy']),
            'vehicle',
            'driver',
            'events' => fn ($q) => $q->orderBy('created_at'),
        ]);

        $declaredAmount = 0;
        $verifiedAmount = 0;
        $rejectedAmount = 0;
        $hasDeclaredCollections = false;
        $allDiscrepanciesResolved = true;
        $hasNegativeDifferences = false;

        $stopsData = [];

        foreach ($route->stops as $stop) {
            $stopItems = [];
            $stopHasUnresolved = false;

            foreach ($stop->items as $item) {
                $diff = $item->quantity_loaded - $item->quantity_delivered;
                $discrepancy = $item->discrepancy ?? null;

                $stopItems[] = [
                    'route_stop_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name,
                    'quantity_loaded' => $item->quantity_loaded,
                    'quantity_delivered' => $item->quantity_delivered,
                    'difference' => $diff,
                    'discrepancy' => $discrepancy ? [
                        'id' => $discrepancy->id,
                        'resolution_type' => $discrepancy->resolution_type,
                        'notes' => $discrepancy->notes,
                        'resolved_at' => $discrepancy->resolved_at?->format('Y-m-d H:i:s'),
                    ] : null,
                ];

                if ($diff > 0 && ! $discrepancy) {
                    $stopHasUnresolved = true;
                    $allDiscrepanciesResolved = false;
                }

                if ($diff < 0) {
                    $hasNegativeDifferences = true;
                }
            }

            $stopCollections = [];
            $stopOrderData = null;

            if ($stop->order) {
                foreach ($stop->collections as $collection) {
                    $collectionAmount = (float) $collection->amount;

                    if ($collection->status === 'declared') {
                        $declaredAmount += $collectionAmount;
                        $hasDeclaredCollections = true;
                    } elseif ($collection->status === 'verified') {
                        $verifiedAmount += $collectionAmount;
                    } elseif ($collection->status === 'rejected') {
                        $rejectedAmount += $collectionAmount;
                    }

                    $stopCollections[] = [
                        'id' => $collection->id,
                        'status' => $collection->status,
                        'amount' => $collectionAmount,
                        'reference' => $collection->reference,
                        'notes' => $collection->notes,
                        'payment_method' => $collection->storePaymentMethod?->paymentMethod?->name
                            ?? $collection->storePaymentMethod?->custom_name,
                        'declared_by' => $collection->declaredBy?->name,
                        'declared_at' => $collection->declared_at?->format('Y-m-d H:i:s'),
                        'verified_at' => $collection->verified_at?->format('Y-m-d H:i:s'),
                    ];
                }

                $stopOrderData = [
                    'id' => $stop->order->id,
                    'operation_number' => $stop->order->operation_number,
                    'customer_name' => $stop->order->customer?->display_name ?? $stop->order->customer?->name,
                    'total_amount' => (float) $stop->order->items?->sum(fn ($i) => (float) $i->quantity * (float) $i->price) ?? 0,
                    'paid_amount' => (float) ($stop->order->payments?->sum('amount') ?? 0),
                ];
                $stopOrderData['pending_balance'] = $stopOrderData['total_amount'] - $stopOrderData['paid_amount'];
            }

            $stopsData[] = [
                'stop_id' => $stop->id,
                'sequence' => $stop->sequence,
                'status' => $stop->status,
                'order' => $stopOrderData,
                'items' => $stopItems,
                'collections' => $stopCollections,
            ];
        }

        $canClose = ! $hasDeclaredCollections && ! $hasNegativeDifferences && $allDiscrepanciesResolved;

        return [
            'route_id' => $route->id,
            'status' => $route->status,
            'operational_date' => $route->operational_date?->format('Y-m-d'),
            'vehicle' => $route->vehicle?->plate_number ?? $route->vehicle?->name,
            'driver' => $route->driver?->name,
            'stops' => $stopsData,
            'totals' => [
                'declared_amount' => $declaredAmount,
                'verified_amount' => $verifiedAmount,
                'rejected_amount' => $rejectedAmount,
            ],
            'can_close' => $canClose,
        ];
    }

    /**
     * Verify a declared collection and create the corresponding OperationPayment.
     */
    public function verifyCollection(RouteStopCollection $collection, User $user): RouteStopCollection
    {
        return DB::transaction(function () use ($collection, $user) {
            if ($collection->status !== 'declared') {
                throw $this->validationError('La cobranza ya fue procesada.');
            }

            if ($collection->operation_payment_id !== null) {
                throw $this->validationError('La cobranza ya tiene un pago asociado.');
            }

            $collection = RouteStopCollection::where('id', $collection->id)->lockForUpdate()->first();

            $order = CommercialOperation::with(['items', 'payments'])->find($collection->commercial_operation_id);

            if (! $order) {
                throw $this->validationError('El pedido asociado a la cobranza no existe.');
            }

            $totalAmount = (float) $order->items->sum(fn ($i) => (float) $i->quantity * (float) $i->price);
            $paidAmount = (float) $order->payments->sum('amount');
            $pendingBalance = $totalAmount - $paidAmount;

            if ((float) $collection->amount > $pendingBalance) {
                throw $this->validationError('El monto supera el saldo pendiente del pedido.');
            }

            $payment = OperationPayment::create([
                'operation_id' => $collection->commercial_operation_id,
                'store_payment_method_id' => $collection->store_payment_method_id,
                'amount' => $collection->amount,
                'reference' => $collection->reference,
                'payment_details' => [
                    'route_stop_collection_id' => $collection->id,
                    'declared_by' => $collection->declared_by,
                ],
            ]);

            $collection->update([
                'status' => 'verified',
                'verified_by' => $user->id,
                'verified_at' => now(),
                'operation_payment_id' => $payment->id,
            ]);

            return $collection;
        });
    }

    /**
     * Reject a declared collection (no OperationPayment created).
     */
    public function rejectCollection(RouteStopCollection $collection, string $reason, User $user): RouteStopCollection
    {
        return DB::transaction(function () use ($collection, $reason, $user) {
            if ($collection->status !== 'declared') {
                throw $this->validationError('La cobranza ya fue procesada.');
            }

            $collection = RouteStopCollection::where('id', $collection->id)->lockForUpdate()->first();

            $collection->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'verified_by' => $user->id,
                'verified_at' => now(),
            ]);

            return $collection;
        });
    }

    /**
     * Resolve a delivery discrepancy for a RouteStopItem.
     */
    public function resolveDiscrepancy(RouteStopItem $item, array $data, User $user): DeliveryDiscrepancy
    {
        return DB::transaction(function () use ($item, $data, $user) {
            $diff = $item->quantity_loaded - $item->quantity_delivered;

            if ($diff <= 0) {
                throw $this->validationError('No hay diferencia que resolver.');
            }

            $quantityToResolve = (int) $data['quantity_to_resolve'];

            if ($quantityToResolve > $diff) {
                throw $this->validationError('La cantidad a resolver supera la diferencia real.');
            }

            $discrepancy = DeliveryDiscrepancy::updateOrCreate(
                ['route_stop_item_id' => $item->id],
                [
                    'product_id' => $item->product_id,
                    'quantity_loaded' => $item->quantity_loaded,
                    'quantity_delivered' => $item->quantity_delivered,
                    'difference_quantity' => $diff,
                    'resolution_type' => $data['resolution_type'],
                    'notes' => $data['notes'] ?? null,
                    'resolved_by' => $user->id,
                    'resolved_at' => now(),
                ]
            );

            return $discrepancy;
        });
    }

    /**
     * Finalize the reconciliation and complete the route.
     * Validates: no pending collections, all discrepancies resolved,
     * no negative differences, then transitions to completed.
     */
    public function finalizeReconciliation(DeliveryRoute $route, User $user, ?string $observations = null): DeliveryRoute
    {
        return DB::transaction(function () use ($route, $user, $observations) {
            // Idempotency check — must be BEFORE status validation
            if ($route->processed_at !== null) {
                throw new HttpResponseException(
                    response()->json([
                        'status' => 'error',
                        'message' => 'La ruta ya fue conciliada.',
                        'data' => null,
                        'errors' => ['error' => ['La ruta ya fue conciliada.']],
                    ], 409)
                );
            }

            if ($route->status !== 'awaiting_reconciliation') {
                throw $this->validationError('La ruta no está en estado de conciliación.');
            }

            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();

            $stops = $route->stops()
                ->where('status', '!=', 'cancelled')
                ->with(['items', 'collections'])
                ->get();

            // Check for any declared (unprocessed) collections
            $declaredCollections = RouteStopCollection::whereIn(
                'route_stop_id',
                $stops->pluck('id')
            )->where('status', 'declared')->exists();

            if ($declaredCollections) {
                throw $this->validationError('Hay cobranzas pendientes de verificar o rechazar.');
            }

            // Check all discrepancies are resolved
            foreach ($stops as $stop) {
                foreach ($stop->items as $item) {
                    $diff = $item->quantity_loaded - $item->quantity_delivered;

                    if ($diff < 0) {
                        throw $this->validationError('Hay items con cantidad entregada mayor a la cargada.');
                    }

                    if ($diff > 0) {
                        $hasResolution = DeliveryDiscrepancy::where('route_stop_item_id', $item->id)
                            ->whereNotNull('resolution_type')
                            ->exists();

                        if (! $hasResolution) {
                            throw $this->validationError('Hay discrepancias sin resolver.');
                        }
                    }
                }
            }

            // Update order statuses based on delivered quantities
            $orderDelivered = [];
            foreach ($stops as $stop) {
                if ($stop->status !== 'completed') {
                    continue;
                }
                foreach ($stop->items as $item) {
                    if ($item->quantity_delivered <= 0) {
                        continue;
                    }
                    $orderDelivered[$stop->order_id] = ($orderDelivered[$stop->order_id] ?? 0) + $item->quantity_delivered;
                }
            }

            foreach ($orderDelivered as $orderId => $deliveredQty) {
                $order = CommercialOperation::find($orderId);
                if (! $order) {
                    continue;
                }
                $totalOrdered = $order->items()->sum('quantity');
                if ($deliveredQty >= $totalOrdered) {
                    $order->update(['status' => 'delivered']);
                } elseif ($deliveredQty > 0) {
                    $order->update(['status' => 'partially_delivered']);
                }
            }

            // Process discrepancy inventory impacts
            $this->processDiscrepancyInventory($route, $stops, $user);

            // Update route
            $route->update([
                'status' => 'completed',
                'processed_at' => now(),
                'processed_by' => $user->id,
                'observations' => $observations ?? $route->observations,
            ]);

            $this->createEvent(
                $route,
                $route->store_id,
                $user->id,
                'route_reconciliation_completed',
                'awaiting_reconciliation',
                'completed'
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

        // Note: A single order CAN be split across multiple active routes.
        // The correct control is at the product quantity level via
        // route_stop_items.quantity_planned, validated in assignItems().
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
        // Guard: if optimizedOrder is empty (single stop, nothing to optimize),
        // use natural order 0, 1, 2...
        if (empty($optimizedOrder)) {
            $optimizedOrder = range(0, count($stopIdMap) - 1);
        }

        // Phase 1: move to temporary negative sequences
        foreach ($optimizedOrder as $newIndex => $oldIndex) {
            if (! isset($stopIdMap[$oldIndex])) {
                continue; // skip invalid indices
            }
            $stopId = $stopIdMap[$oldIndex];
            RouteStop::where('id', $stopId)
                ->where('route_id', $routeId)
                ->update(['sequence' => -($newIndex + 1)]);
        }

        // Phase 2: reassign to final positive sequences
        foreach ($optimizedOrder as $newIndex => $oldIndex) {
            if (! isset($stopIdMap[$oldIndex])) {
                continue;
            }
            $stopId = $stopIdMap[$oldIndex];
            RouteStop::where('id', $stopId)
                ->where('route_id', $routeId)
                ->update(['sequence' => $newIndex + 1]);
        }
    }

    /**
     * Process inventory impacts for all discrepancies in a route.
     * Called during finalizeReconciliation. Idempotent via processed_at.
     */
    private function processDiscrepancyInventory(DeliveryRoute $route, $stops, User $user): void
    {
        $inventoryService = new InventoryMovementService();

        foreach ($stops as $stop) {
            foreach ($stop->items as $item) {
                $discrepancy = DeliveryDiscrepancy::where('route_stop_item_id', $item->id)
                    ->whereNotNull('resolution_type')
                    ->whereNull('processed_at')
                    ->first();

                if (! $discrepancy) {
                    continue;
                }

                $product = Product::where('id', $discrepancy->product_id)
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    continue;
                }

                $diff = $discrepancy->difference_quantity;
                $orderId = $stop->order_id;

                switch ($discrepancy->resolution_type) {
                    case 'returned':
                    case 'rejected_by_customer':
                        // Release stock_reserved, no stock change
                        $product->update([
                            'stock_reserved' => max(0, $product->stock_reserved - $diff),
                        ]);
                        $inventoryService->recordMovement(
                            $product, $user, 'input', $diff,
                            "Reingreso logístico — Ruta {$route->id} — Pedido {$orderId}"
                        );
                        break;

                    case 'missing':
                        // Release reserve + decrease stock
                        $product->update([
                            'stock_reserved' => max(0, $product->stock_reserved - $diff),
                        ]);
                        $inventoryService->recordMovement(
                            $product, $user, 'output', $diff,
                            "Ajuste por faltante — Ruta {$route->id} — Pedido {$orderId}"
                        );
                        break;

                    case 'damaged':
                        // Release reserve + decrease stock
                        $product->update([
                            'stock_reserved' => max(0, $product->stock_reserved - $diff),
                        ]);
                        $inventoryService->recordMovement(
                            $product, $user, 'output', $diff,
                            "Salida por daño logístico — Ruta {$route->id} — Pedido {$orderId}"
                        );
                        break;

                    case 'pending_redelivery':
                        // Keep stock_reserved, release from route. No inventory movement.
                        break;
                }

                $discrepancy->update(['processed_at' => now()]);
            }
        }
    }
}
