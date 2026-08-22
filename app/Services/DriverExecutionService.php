<?php

namespace App\Services;

use App\Models\CommercialOperation;
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
    public function getAvailableSurplus(string $routeId, string $storeId): array
    {
        $route = DeliveryRoute::where('id', $routeId)
            ->where('store_id', $storeId)
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
            $stop = RouteStop::with('route')->find($stopId);

            if (! $stop) {
                throw $this->notFoundError('Parada no encontrada.');
            }

            $route = $stop->route;

            if ($route->driver_id !== $driver->id) {
                throw $this->notFoundError('Esta parada no pertenece a una ruta asignada a este conductor.');
            }

            if ($route->store_id !== $driver->store_id) {
                throw $this->notFoundError('La parada no pertenece a la tienda del conductor.');
            }

            if ($route->status !== 'dispatched') {
                throw $this->validationError('La ruta no está en estado despachado.');
            }

            if (! in_array($stop->status, ['pending', 'arrived'])) {
                throw $this->validationError('Solo se pueden agregar ventas extra a paradas pendientes o arrivals.');
            }

            // Lock route for update to prevent race conditions
            $route = DeliveryRoute::where('id', $route->id)->lockForUpdate()->first();
            $stop = RouteStop::where('id', $stopId)->lockForUpdate()->first();

            // Validate and process each item
            foreach ($items as $itemData) {
                $productId = $itemData['product_id'];
                $quantity = (int) $itemData['quantity'];

                if ($quantity <= 0) {
                    throw $this->validationError("La cantidad debe ser mayor a 0 para el producto {$productId}.");
                }

                // Get available surplus for this product
                $availableSurplus = $this->getAvailableSurplusForProduct($route->id, $productId);

                if ($quantity > $availableSurplus) {
                    throw $this->validationError(
                        "Cantidad solicitada ({$quantity}) excede el excedente disponible ({$availableSurplus}) para el producto {$productId}."
                    );
                }

                // Find source items with available surplus and distribute allocation
                $remainingToAllocate = $quantity;
                $sourceItems = $this->getSourceItemsWithSurplus($route->id, $productId);

                foreach ($sourceItems as $sourceItem) {
                    if ($remainingToAllocate <= 0) {
                        break;
                    }

                    $itemSurplus = $sourceItem->quantity_loaded - $sourceItem->quantity_delivered;
                    $alreadyAllocated = ExtraSaleAllocation::where('source_stop_item_id', $sourceItem->id)
                        ->sum('quantity');
                    $availableOnThisItem = $itemSurplus - $alreadyAllocated;

                    if ($availableOnThisItem <= 0) {
                        continue;
                    }

                    $toAllocate = min($remainingToAllocate, $availableOnThisItem);

                    // Get product for price
                    $product = Product::find($productId);
                    $unitPrice = (float) ($product?->price ?? 0);

                    // Create the destination RouteStopItem (extra sale)
                    $destStopItem = RouteStopItem::create([
                        'route_stop_id' => $stop->id,
                        'product_id' => $productId,
                        'product_name' => $product?->name,
                        'quantity_planned' => $toAllocate,
                        'quantity_loaded' => $toAllocate,
                        'quantity_delivered' => $toAllocate,
                        'is_extra' => true,
                    ]);

                    // Create the allocation record
                    ExtraSaleAllocation::create([
                        'store_id' => $route->store_id,
                        'route_id' => $route->id,
                        'destination_stop_id' => $stop->id,
                        'destination_stop_item_id' => $destStopItem->id,
                        'source_stop_item_id' => $sourceItem->id,
                        'quantity' => $toAllocate,
                    ]);

                    // Add OperationItem to the CommercialOperation (order)
                    $order = $stop->order;
                    if ($order) {
                        OperationItem::create([
                            'operation_id' => $order->id,
                            'product_id' => $productId,
                            'product_name' => $product?->name,
                            'quantity' => $toAllocate,
                            'price' => $unitPrice,
                            'subtotal' => $toAllocate * $unitPrice,
                        ]);
                    }

                    $remainingToAllocate -= $toAllocate;
                }

                if ($remainingToAllocate > 0) {
                    throw $this->validationError(
                        "No se pudo allocating toda la cantidad solicitada para el producto {$productId}."
                    );
                }
            }

            // Recalculate CommercialOperation totals
            $order = $stop->order;
            if ($order) {
                $this->recalculateOperationTotals($order);
            }

            return $stop->fresh([
                'items.product',
                'order.customer',
                'order.items',
                'order.payments',
            ]);
        });
    }

    /**
     * Get available surplus quantity for a specific product on a route.
     */
    private function getAvailableSurplusForProduct(string $routeId, string $productId): int
    {
        $sourceItems = RouteStopItem::whereHas('routeStop', function ($query) use ($routeId) {
            $query->where('route_id', $routeId)
                ->whereIn('status', ['completed', 'failed']);
        })
            ->where('product_id', $productId)
            ->get();

        $totalSurplus = 0;
        foreach ($sourceItems as $item) {
            $totalSurplus += $item->quantity_loaded - $item->quantity_delivered;
        }

        $allocated = ExtraSaleAllocation::where('route_id', $routeId)
            ->whereIn('source_stop_item_id', $sourceItems->pluck('id'))
            ->sum('quantity');

        return max(0, $totalSurplus - $allocated);
    }

    /**
     * Get source route stop items that have surplus for a product.
     */
    private function getSourceItemsWithSurplus(string $routeId, string $productId): \Illuminate\Database\Eloquent\Collection
    {
        $sourceItems = RouteStopItem::whereHas('routeStop', function ($query) use ($routeId) {
            $query->where('route_id', $routeId)
                ->whereIn('status', ['completed', 'failed']);
        })
            ->where('product_id', $productId)
            ->get();

        // Filter to only items with available surplus
        return $sourceItems->filter(function ($item) {
            $surplus = $item->quantity_loaded - $item->quantity_delivered;
            $allocated = ExtraSaleAllocation::where('source_stop_item_id', $item->id)->sum('quantity');
            return ($surplus - $allocated) > 0;
        })->values();
    }

    /**
     * Recalculate and update CommercialOperation totals based on its items.
     */
    private function recalculateOperationTotals(CommercialOperation $order): void
    {
        $order = CommercialOperation::where('id', $order->id)->lockForUpdate()->first();

        $subtotal = (float) $order->items()->sum(DB::raw('quantity * price'));
        $tax = (float) $order->items()->sum('tax_amount');
        $discount = (float) $order->items()->sum('discount_amount');
        $total = $subtotal + $tax - $discount;

        $order->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => $discount,
            'total' => $total,
        ]);
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
