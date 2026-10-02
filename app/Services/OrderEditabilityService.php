<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\ExtraSaleAllocation;
use App\Models\RouteStopItem;
use App\Support\QuantityMath;
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
        $order->loadMissing('items.product.stockMeasurementUnit');

        $currentQuantities = $order->items
            ->groupBy('product_id')
            ->map(fn (Collection $lines): string => $this->sumQuantities($lines->pluck('quantity')->all()));

        $deliveredQuantities = $this->completedDeliveredQuantities($order);
        $activeCommittedQuantities = $this->activeCommittedQuantities($order);
        $hasActiveExtraSale = $this->hasActiveExtraSaleAllocation($order);

        $statusIsEditable = in_array($order->status, self::EDITABLE_ORDER_STATUSES, true);
        $blockReason = ! $statusIsEditable
            ? 'terminal_status'
            : ($hasActiveExtraSale ? 'active_extra_sale' : null);

        $editable = $blockReason === null;
        $hasActiveRouteCommitment = $activeCommittedQuantities
            ->contains(fn (string $quantity): bool => QuantityMath::isPositive($quantity));
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
                ->map(function (string $currentQuantity, string $productId) use ($order, $deliveredQuantities, $activeCommittedQuantities): array {
                    $lines = $order->items->where('product_id', $productId);
                    $line = $lines->first();
                    $deliveredQuantity = QuantityMath::normalize($deliveredQuantities->get($productId, '0'));
                    $activeCommittedQuantity = QuantityMath::normalize($activeCommittedQuantities->get($productId, '0'));
                    $minimumQuantity = QuantityMath::add($deliveredQuantity, $activeCommittedQuantity);
                    $editableQuantity = QuantityMath::max(
                        '0',
                        QuantityMath::subtract($currentQuantity, $minimumQuantity)
                    );
                    $product = $line?->product;
                    $step = QuantityMath::normalize($product?->sale_quantity_step ?? '1');
                    $commercialAvailable = QuantityMath::normalize($product?->commercial_available_quantity ?? '0');

                    return [
                        'product_id' => $productId,
                        'product_name' => $line?->product_name ?? $line?->product?->name,
                        'current_quantity' => QuantityMath::normalize($currentQuantity),
                        'delivered_quantity' => $deliveredQuantity,
                        'active_committed_quantity' => $activeCommittedQuantity,
                        'minimum_quantity' => $minimumQuantity,
                        'editable_quantity' => $editableQuantity,
                        'sale_quantity_step' => $step,
                        'commercial_available_quantity' => $commercialAvailable,
                        // The order's own current reservation is releasable while editing,
                        // so it is added back to the externally available commercial stock.
                        'maximum_editable_quantity' => QuantityMath::add($commercialAvailable, $currentQuantity),
                        'stock_measurement_unit' => $product?->stockMeasurementUnit ? [
                            'code' => $product->stockMeasurementUnit->code,
                            'name' => $product->stockMeasurementUnit->name,
                            'symbol' => $product->stockMeasurementUnit->symbol,
                        ] : null,
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
            ->map(fn ($quantity): string => QuantityMath::normalize($quantity));
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
            ->map(fn ($quantity): string => QuantityMath::normalize($quantity));
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

    /**
     * @param  array<int, int|string>  $quantities
     */
    private function sumQuantities(array $quantities): string
    {
        return array_reduce(
            $quantities,
            fn (string $total, int|string $quantity): string => QuantityMath::add($total, $quantity),
            '0.0000'
        );
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
