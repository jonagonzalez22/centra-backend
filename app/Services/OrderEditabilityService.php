<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\ExtraSaleAllocation;
use App\Models\RouteStopItem;
use Illuminate\Support\Collection;

class OrderEditabilityService
{
    private const EDITABLE_ORDER_STATUSES = [
        'open',
        'confirmed',
        'partially_delivered',
    ];

    private const ACTIVE_ROUTE_STATUSES = [
        'draft',
        'planned',
        'loaded',
        'dispatched',
        'awaiting_reconciliation',
    ];

    /**
     * Build a read-only editability snapshot. This is intentionally not the
     * final authority for a future write: an update must recalculate these
     * values inside its own transaction and locks.
     */
    public function describe(CommercialOperation $order): array
    {
        $order->loadMissing('items.product');

        $currentQuantities = $order->items
            ->groupBy('product_id')
            ->map(fn (Collection $lines): int => (int) $lines->sum('quantity'));

        $deliveredQuantities = $this->completedDeliveredQuantities($order);
        $activeCommittedQuantities = $this->activeCommittedQuantities($order);
        $hasActiveExtraSale = $this->hasActiveExtraSaleAllocation($order);

        $statusIsEditable = in_array($order->status, self::EDITABLE_ORDER_STATUSES, true);
        $blockReason = ! $statusIsEditable
            ? 'terminal_status'
            : ($hasActiveExtraSale ? 'active_extra_sale' : null);

        $editable = $blockReason === null;
        $hasActiveRouteCommitment = $activeCommittedQuantities->sum() > 0;
        $deliveryDateBlockReason = $blockReason ?? ($hasActiveRouteCommitment ? 'active_route_commitment' : null);

        return [
            'order_id' => $order->id,
            'status' => $order->status,
            'editable' => $editable,
            'block_reason' => $blockReason,
            'block_message' => $this->blockMessage($blockReason),
            'paid_amount' => (float) $order->payments()->sum('amount'),
            'delivery_date_editable' => $deliveryDateBlockReason === null,
            'delivery_date_block_reason' => $deliveryDateBlockReason,
            'delivery_date_block_message' => $this->deliveryDateBlockMessage($deliveryDateBlockReason),
            'items' => $currentQuantities
                ->map(function (int $currentQuantity, string $productId) use ($order, $deliveredQuantities, $activeCommittedQuantities): array {
                    $lines = $order->items->where('product_id', $productId);
                    $line = $lines->first();
                    $deliveredQuantity = (int) $deliveredQuantities->get($productId, 0);
                    $activeCommittedQuantity = (int) $activeCommittedQuantities->get($productId, 0);
                    $minimumQuantity = $deliveredQuantity + $activeCommittedQuantity;

                    return [
                        'product_id' => $productId,
                        'product_name' => $line?->product_name ?? $line?->product?->name,
                        'current_quantity' => $currentQuantity,
                        'delivered_quantity' => $deliveredQuantity,
                        'active_committed_quantity' => $activeCommittedQuantity,
                        'minimum_quantity' => $minimumQuantity,
                        'editable_quantity' => max(0, $currentQuantity - $minimumQuantity),
                    ];
                })
                ->sortBy('product_name')
                ->values()
                ->all(),
        ];
    }

    private function completedDeliveredQuantities(CommercialOperation $order): Collection
    {
        return RouteStopItem::query()
            ->join('route_stops', 'route_stops.id', '=', 'route_stop_items.route_stop_id')
            ->join('delivery_routes', 'delivery_routes.id', '=', 'route_stops.route_id')
            ->where('route_stops.order_id', $order->id)
            ->where('route_stops.status', 'completed')
            ->where('delivery_routes.status', 'completed')
            ->groupBy('route_stop_items.product_id')
            ->selectRaw('route_stop_items.product_id, SUM(route_stop_items.quantity_delivered) as quantity')
            ->pluck('quantity', 'product_id')
            ->map(fn ($quantity): int => (int) $quantity);
    }

    private function activeCommittedQuantities(CommercialOperation $order): Collection
    {
        return RouteStopItem::query()
            ->join('route_stops', 'route_stops.id', '=', 'route_stop_items.route_stop_id')
            ->join('delivery_routes', 'delivery_routes.id', '=', 'route_stops.route_id')
            ->where('route_stops.order_id', $order->id)
            ->where('route_stops.status', '!=', 'cancelled')
            ->whereIn('delivery_routes.status', self::ACTIVE_ROUTE_STATUSES)
            ->groupBy('route_stop_items.product_id')
            ->selectRaw(
                'route_stop_items.product_id, SUM(CASE WHEN delivery_routes.status IN (?, ?) THEN route_stop_items.quantity_planned ELSE route_stop_items.quantity_loaded END) as quantity',
                ['draft', 'planned']
            )
            ->pluck('quantity', 'product_id')
            ->map(fn ($quantity): int => (int) $quantity);
    }

    private function hasActiveExtraSaleAllocation(CommercialOperation $order): bool
    {
        return ExtraSaleAllocation::query()
            ->forStore($order->store_id)
            ->whereHas('route', fn ($query) => $query->whereIn('status', self::ACTIVE_ROUTE_STATUSES))
            ->where(function ($query) use ($order) {
                $query
                    ->whereHas('sourceStopItem.routeStop', fn ($stopQuery) => $stopQuery->where('order_id', $order->id))
                    ->orWhereHas('destinationStopItem.routeStop', fn ($stopQuery) => $stopQuery->where('order_id', $order->id));
            })
            ->exists();
    }

    private function blockMessage(?string $blockReason): ?string
    {
        return match ($blockReason) {
            'terminal_status' => 'El pedido no puede editarse en su estado actual.',
            'active_extra_sale' => 'El pedido tiene una venta extra activa en una ruta operativa.',
            default => null,
        };
    }

    private function deliveryDateBlockMessage(?string $blockReason): ?string
    {
        return match ($blockReason) {
            'active_route_commitment' => 'La fecha de entrega no puede modificarse porque el pedido tiene mercadería comprometida en una ruta activa.',
            default => $this->blockMessage($blockReason),
        };
    }
}
