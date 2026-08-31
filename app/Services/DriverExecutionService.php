<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\DeliveryDiscrepancy;
use App\Models\DeliveryRoute;
use App\Models\DeliveryRouteEvent;
use App\Models\ExtraSaleAllocation;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopCollection;
use App\Models\RouteStopItem;
use App\Models\StorePaymentMethod;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class DriverExecutionService
{
    public function __construct(
        private CommercialOperationService $commercialOperationService
    ) {}

    /**
     * Find the active (dispatched) route for a driver.
     * Returns null when no dispatched route exists for this driver.
     */
    public function getActiveRoute(User $driver): ?DeliveryRoute
    {
        return DeliveryRoute::where('driver_id', $driver->id)
            ->where('store_id', $driver->store_id)
            ->where('status', 'dispatched')
            ->with([
                'vehicle',
                'stops' => function ($query) {
                    $query->where('status', '!=', 'cancelled')
                        ->orderBy('sequence');
                },
                'stops.items.product',
                'stops.order.customer',
                'stops.order.items',
                'stops.order.payments',
                'stops.order.payments.storePaymentMethod.paymentMethod',
            ])
            ->first();
    }

    /**
     * Get available surplus products on the truck from completed/failed stops.
     * available_surplus = SUM(quantity_loaded - quantity_delivered) - SUM(extra_sale_allocations.quantity)
     */
    public function getAvailableSurplus(string $routeId, User $driver): array
    {
        $route = DeliveryRoute::where('id', $routeId)
            ->where('store_id', $driver->store_id)
            ->where('driver_id', $driver->id)
            ->where('status', 'dispatched')
            ->first();

        if (! $route) {
            throw $this->notFoundError('Ruta no encontrada o no está en estado despachado.');
        }

        // Get all stop items from completed/failed stops
        $sourceItems = RouteStopItem::whereHas('routeStop', function ($query) use ($routeId) {
            $query->where('route_id', $routeId)
                ->whereIn('status', ['completed', 'failed']);
        })
            ->with('product')
            ->get();

        // Group by product and calculate surplus per product
        $surplusByProduct = [];

        foreach ($sourceItems as $item) {
            $productId = $item->product_id;
            $rawSurplus = $item->quantity_loaded - $item->quantity_delivered;

            if ($rawSurplus <= 0) {
                continue;
            }

            if (! isset($surplusByProduct[$productId])) {
                $surplusByProduct[$productId] = [
                    'product_id' => $productId,
                    'product_name' => $item->product?->name,
                    'sku' => $item->product?->sku,
                    'unit_price' => (float) ($item->product?->price ?? 0),
                    'total_surplus' => 0,
                    'allocated' => 0,
                ];
            }

            $surplusByProduct[$productId]['total_surplus'] += $rawSurplus;
        }

        // Subtract already allocated quantities from extra_sale_allocations
        $allocations = ExtraSaleAllocation::where('route_id', $routeId)
            ->select('source_stop_item_id', 'quantity')
            ->get();

        $sourceItemIds = $sourceItems->pluck('id')->toArray();
        $allocatedBySourceItem = [];

        foreach ($allocations as $allocation) {
            if (in_array($allocation->source_stop_item_id, $sourceItemIds)) {
                if (! isset($allocatedBySourceItem[$allocation->source_stop_item_id])) {
                    $allocatedBySourceItem[$allocation->source_stop_item_id] = 0;
                }
                $allocatedBySourceItem[$allocation->source_stop_item_id] += $allocation->quantity;
            }
        }

        // Map allocated quantities back to products
        foreach ($sourceItems as $item) {
            $productId = $item->product_id;
            if (isset($allocatedBySourceItem[$item->id])) {
                $surplusByProduct[$productId]['allocated'] += $allocatedBySourceItem[$item->id];
            }
        }

        // Filter to only products with positive available quantity
        $result = [];
        foreach ($surplusByProduct as $productData) {
            $available = $productData['total_surplus'] - $productData['allocated'];
            if ($available > 0) {
                $result[] = [
                    'product_id' => $productData['product_id'],
                    'product_name' => $productData['product_name'],
                    'sku' => $productData['sku'],
                    'unit_price' => $productData['unit_price'],
                    'available_quantity' => $available,
                ];
            }
        }

        return $result;
    }

    /**
     * Add extra sale items to a pending/arrived stop.
     * Automatically allocates from source items with available surplus.
     */
    public function addExtraSale(string $stopId, array $items, User $driver): RouteStop
    {
        return DB::transaction(function () use ($stopId, $items, $driver) {
            // The route is the mutex for every extra-sale write. It must be the
            // first shared row locked so availability is always recalculated serially.
            $route = DeliveryRoute::where('store_id', $driver->store_id)
                ->where('driver_id', $driver->id)
                ->where('status', 'dispatched')
                ->whereHas('stops', fn ($query) => $query->where('id', $stopId))
                ->lockForUpdate()
                ->first();

            if (! $route) {
                throw $this->notFoundError('Esta parada no pertenece a una ruta despachada asignada a este conductor.');
            }

            $stop = RouteStop::where('id', $stopId)
                ->where('route_id', $route->id)
                ->lockForUpdate()
                ->first();

            if (! $stop || ! in_array($stop->status, ['pending', 'arrived'], true)) {
                throw $this->validationError('Solo se pueden agregar ventas extra a paradas pendientes o arrivals.');
            }

            $items = collect($items)->sortBy('product_id')->values();
            $productIds = $items->pluck('product_id')->all();
            $products = Product::forStore($route->store_id)
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            if ($products->count() !== count($productIds)) {
                throw $this->validationError('Uno o más productos no pertenecen a la tienda de la ruta.');
            }

            $sourceItems = RouteStopItem::whereIn('product_id', $productIds)
                ->whereHas('routeStop', function ($query) use ($route) {
                    $query->where('route_id', $route->id)
                        ->whereIn('status', ['completed', 'failed']);
                })
                ->with('routeStop')
                ->orderBy('product_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $allocatedBySource = ExtraSaleAllocation::where('route_id', $route->id)
                ->whereIn('source_stop_item_id', $sourceItems->pluck('id'))
                ->select('source_stop_item_id', DB::raw('SUM(quantity) as total_quantity'))
                ->groupBy('source_stop_item_id')
                ->pluck('total_quantity', 'source_stop_item_id');

            $allocationPlan = [];
            foreach ($items as $itemData) {
                $productId = $itemData['product_id'];
                $quantity = (int) $itemData['quantity'];
                $remainingToAllocate = $quantity;

                foreach ($sourceItems->where('product_id', $productId) as $sourceItem) {
                    if ($remainingToAllocate <= 0) {
                        break;
                    }

                    $itemSurplus = $sourceItem->quantity_loaded - $sourceItem->quantity_delivered;
                    $alreadyAllocated = (int) ($allocatedBySource[$sourceItem->id] ?? 0);
                    $availableOnThisItem = $itemSurplus - $alreadyAllocated;

                    if ($availableOnThisItem <= 0) {
                        continue;
                    }

                    $toAllocate = min($remainingToAllocate, $availableOnThisItem);
                    $allocationPlan[] = [$sourceItem, $toAllocate];
                    $allocatedBySource[$sourceItem->id] = $alreadyAllocated + $toAllocate;
                    $remainingToAllocate -= $toAllocate;
                }

                if ($remainingToAllocate > 0) {
                    throw $this->validationError(
                        "No se pudo asignar toda la cantidad solicitada para el producto {$productId}."
                    );
                }
            }

            $sourceOrderIds = collect($allocationPlan)
                ->map(fn (array $allocation) => $allocation[0]->routeStop->order_id)
                ->filter();
            $orderIds = $sourceOrderIds->push($stop->order_id)->unique()->sort()->values();
            $lockedOrders = CommercialOperation::forStore($route->store_id)
                ->whereIn('id', $orderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($lockedOrders->count() !== $orderIds->count()) {
                throw $this->validationError('Uno o más pedidos asociados no pertenecen a la tienda de la ruta.');
            }

            $destinationOrder = CommercialOperation::forStore($route->store_id)
                ->where('id', $stop->order_id)
                ->first();

            if (! $destinationOrder) {
                throw $this->validationError('El pedido destino no existe o no pertenece a la tienda de la ruta.');
            }

            foreach ($items as $itemData) {
                $productId = $itemData['product_id'];
                $quantity = (int) $itemData['quantity'];
                $product = $products[$productId];
                $destinationItem = RouteStopItem::where('route_stop_id', $stop->id)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if ($destinationItem) {
                    $destinationItem->increment('quantity_planned', $quantity);
                    $destinationItem->increment('quantity_loaded', $quantity);
                    $destinationItem->refresh();
                } else {
                    $destinationItem = RouteStopItem::create([
                        'route_stop_id' => $stop->id,
                        'product_id' => $productId,
                        'product_name' => $product->name,
                        'quantity_planned' => $quantity,
                        'quantity_loaded' => $quantity,
                        'quantity_delivered' => 0,
                        'is_extra' => true,
                    ]);
                }

                foreach (collect($allocationPlan)->filter(
                    fn (array $allocation) => $allocation[0]->product_id === $productId
                ) as [$sourceItem, $allocatedQuantity]) {
                    ExtraSaleAllocation::create([
                        'store_id' => $route->store_id,
                        'route_id' => $route->id,
                        'destination_stop_id' => $stop->id,
                        'destination_stop_item_id' => $destinationItem->id,
                        'source_stop_item_id' => $sourceItem->id,
                        'quantity' => $allocatedQuantity,
                    ]);

                    $this->updateSourceDiscrepancy($sourceItem, $stop);
                    $this->commercialOperationService->reduceCommercialObligation(
                        $sourceItem->routeStop->order_id,
                        $productId,
                        $allocatedQuantity
                    );
                }

                OperationItem::create([
                    'operation_id' => $destinationOrder->id,
                    'product_id' => $productId,
                    'product_name' => $product->name,
                    'quantity' => $quantity,
                    'price' => (float) $product->price,
                    'subtotal' => round($quantity * (float) $product->price, 2),
                    'tax_amount' => 0,
                    'discount_amount' => 0,
                ]);
            }

            $this->commercialOperationService->recalculateTotals($destinationOrder);

            return $stop->fresh([
                'items.product',
                'order.customer',
                'order.items',
                'order.payments',
            ]);
        });
    }

    private function updateSourceDiscrepancy(RouteStopItem $sourceItem, RouteStop $destinationStop): void
    {
        $discrepancy = DeliveryDiscrepancy::where('route_stop_item_id', $sourceItem->id)
            ->lockForUpdate()
            ->first();
        $totalAllocated = (int) ExtraSaleAllocation::where('source_stop_item_id', $sourceItem->id)
            ->whereHas('destinationStop', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->sum('quantity');
        $difference = $sourceItem->quantity_loaded - $sourceItem->quantity_delivered - $totalAllocated;
        $notes = "Venta extra a parada {$destinationStop->id}";
        $attributes = [
            'quantity_delivered' => $sourceItem->quantity_delivered,
            'difference_quantity' => $difference,
            'resolution_type' => $difference === 0 ? 'extra_sale' : null,
            'notes' => $discrepancy?->notes ? $discrepancy->notes."; {$notes}" : $notes,
            'resolved_at' => $difference === 0 ? now() : null,
        ];

        if ($discrepancy) {
            $discrepancy->update($attributes);
        } else {
            DeliveryDiscrepancy::create($attributes + [
                'route_stop_item_id' => $sourceItem->id,
                'product_id' => $sourceItem->product_id,
                'quantity_loaded' => $sourceItem->quantity_loaded,
                'resolved_by' => null,
            ]);
        }
    }

    /**
     * Mark arrival at a stop, optionally recording GPS coordinates.
     */
    public function arriveStop(RouteStop $stop, array $data, User $driver): RouteStop
    {
        $route = $this->validateDriverRoute($stop, $driver);

        $stop->update([
            'status' => 'arrived',
            'gps_lat' => $data['gps_lat'] ?? null,
            'gps_lon' => $data['gps_lon'] ?? null,
        ]);

        return $stop->fresh();
    }

    /**
     * Complete a stop delivery. Handles full, partial, and failed deliveries.
     * Also checks if all stops are resolved and transitions the route.
     */
    public function completeStop(RouteStop $stop, array $data, User $driver): RouteStop
    {
        return DB::transaction(function () use ($stop, $data, $driver) {
            $route = $this->validateDriverRoute($stop, $driver);

            // Lock the stop to prevent race conditions
            $stop = RouteStop::where('id', $stop->id)->lockForUpdate()->first();

            if (! in_array($stop->status, ['pending', 'arrived'])) {
                throw $this->validationError('Este stop ya fue procesado.');
            }

            $items = $data['items'];
            $allZero = true;

            foreach ($items as $itemData) {
                $routeStopItem = RouteStopItem::where('id', $itemData['route_stop_item_id'])
                    ->where('route_stop_id', $stop->id)
                    ->lockForUpdate()
                    ->first();

                if (! $routeStopItem) {
                    throw $this->validationError("El item {$itemData['route_stop_item_id']} no pertenece a este stop.");
                }

                $qtyDelivered = (int) $itemData['quantity_delivered'];

                if ($qtyDelivered > $routeStopItem->quantity_loaded) {
                    throw $this->validationError(
                        "La cantidad entregada ({$qtyDelivered}) no puede superar la cargada ({$routeStopItem->quantity_loaded})."
                    );
                }

                // Partial delivery (some delivered, some not) requires a per-item rejection reason
                if ($qtyDelivered > 0 && $qtyDelivered < $routeStopItem->quantity_loaded) {
                    if (empty($itemData['rejection_reason_id'])) {
                        throw $this->validationError(
                            'Se requiere un motivo de rechazo cuando la cantidad entregada es menor a la cargada.'
                        );
                    }
                }

                if ($qtyDelivered > 0) {
                    $allZero = false;
                }

                $routeStopItem->update([
                    'quantity_delivered' => $qtyDelivered,
                ]);
            }

            if ($allZero) {
                if (empty($data['rejection_reason_id'])) {
                    throw $this->validationError(
                        'Se requiere un motivo de rechazo cuando no se entrega ningún producto.'
                    );
                }
            }

            // Determine final status
            $finalStatus = $allZero ? 'failed' : 'completed';

            // ── Process payments (collections) ──────────────────────────
            $payments = $data['payments'] ?? [];

            if (! empty($payments)) {
                $order = $stop->order()
                    ->with(['items', 'payments'])
                    ->lockForUpdate()
                    ->first();

                if (! $order) {
                    throw $this->validationError('No se encontró el pedido asociado a este stop.');
                }

                // Calculate pending balance
                $totalAmount = (float) $order->items->sum(function ($item) {
                    return (float) $item->quantity * (float) $item->price;
                });

                $paidAmount = (float) $order->payments->sum('amount');
                $pendingBalance = $totalAmount - $paidAmount;

                if ($pendingBalance <= 0) {
                    throw $this->validationError('El pedido no tiene saldo pendiente.');
                }

                $declaredTotal = array_sum(array_map(fn ($p) => (float) $p['amount'], $payments));

                if ($declaredTotal > $pendingBalance) {
                    throw $this->validationError('El total declarado supera el saldo pendiente del pedido.');
                }

                // Validate each payment and create collection
                foreach ($payments as $payment) {
                    $storePaymentMethod = StorePaymentMethod::where('id', $payment['store_payment_method_id'])
                        ->where('store_id', $route->store_id)
                        ->first();

                    if (! $storePaymentMethod) {
                        throw $this->validationError('El método de pago no pertenece a la tienda.');
                    }

                    if ($storePaymentMethod->requires_reference && empty($payment['reference'])) {
                        throw $this->validationError('El método de pago requiere una referencia.');
                    }

                    RouteStopCollection::create([
                        'store_id' => $route->store_id,
                        'route_stop_id' => $stop->id,
                        'commercial_operation_id' => $order->id,
                        'store_payment_method_id' => $payment['store_payment_method_id'],
                        'amount' => $payment['amount'],
                        'reference' => $payment['reference'] ?? null,
                        'notes' => $payment['notes'] ?? null,
                        'declared_by' => $driver->id,
                        'declared_at' => now(),
                        'status' => 'declared',
                    ]);
                }
            }

            $stop->update([
                'status' => $finalStatus,
                'completed_by' => $driver->id,
                'completed_at' => now(),
                'gps_lat' => $data['gps_lat'] ?? null,
                'gps_lon' => $data['gps_lon'] ?? null,
                'signature_uri' => $data['signature_uri'] ?? null,
                'evidence_uris' => $data['evidence_uris'] ?? null,
            ]);

            // Create event
            $eventType = $finalStatus === 'completed' ? 'stop_completed' : 'stop_failed';

            $this->createEvent(
                $route,
                $route->store_id,
                $driver->id,
                $eventType,
                'pending',
                $finalStatus,
                null,
                [
                    'stop_id' => $stop->id,
                    'items' => $items,
                    'rejection_reason_id' => $data['rejection_reason_id'] ?? null,
                ]
            );

            // Check if all stops are now resolved
            $this->checkAndTransitionRoute($route);

            return $stop->fresh();
        });
    }

    // ── Private helpers ──────────────────────────────────────────────

    /**
     * Validate that the stop belongs to a dispatched route assigned to the driver.
     * Returns the route if valid, throws otherwise.
     */
    private function validateDriverRoute(RouteStop $stop, User $driver): DeliveryRoute
    {
        $route = DeliveryRoute::with('stops')
            ->where('id', $stop->route_id)
            ->where('driver_id', $driver->id)
            ->where('store_id', $driver->store_id)
            ->first();

        if (! $route) {
            throw $this->notFoundError('No se encontró una ruta activa para este conductor y stop.');
        }

        if ($route->status !== 'dispatched') {
            throw $this->notFoundError('La ruta no está en estado despachado.');
        }

        return $route;
    }

    /**
     * Check if all active stops are completed or failed, transition route if so.
     */
    private function checkAndTransitionRoute(DeliveryRoute $route): void
    {
        $pendingCount = RouteStop::where('route_id', $route->id)
            ->whereNotIn('status', ['completed', 'failed', 'cancelled'])
            ->count();

        if ($pendingCount === 0) {
            $route->update(['status' => 'awaiting_reconciliation']);

            $this->createEvent(
                $route,
                $route->store_id,
                $route->driver_id,
                'route_awaiting_reconciliation',
                'dispatched',
                'awaiting_reconciliation'
            );
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
     * Build a validation error response (422).
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
     * Build a not found error response (404).
     */
    private function notFoundError(string $message): HttpResponseException
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 'error',
                'message' => $message,
                'data' => null,
                'errors' => ['error' => [$message]],
            ], 404)
        );
    }
}
