<?php

namespace App\Services;

use App\Models\DeliveryRoute;
use App\Models\DeliveryRouteEvent;
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
