<?php

namespace App\Services;

use App\Models\CommercialOperation;
use Illuminate\Support\Collection;

/**
 * Derives the operational delivery balance from the current commercial
 * obligation and route-stop quantities. It intentionally does not use
 * reconciliation discrepancies as a source of truth.
 */
class DeliverySummaryService
{
    private const ACTIVE_ROUTE_STATUSES = [
        'draft',
        'planned',
        'loaded',
        'dispatched',
        'awaiting_reconciliation',
    ];

    public function summarize(CommercialOperation $operation): array
    {
        $operation->loadMissing(['items.product', 'routeStops.items', 'routeStops.route']);
        $items = $operation->items;
        $stops = $operation->routeStops;

        $ordered = $items->groupBy('product_id')->map(fn (Collection $lines) => (int) $lines->sum('quantity'));
        $delivered = $this->quantitiesByProduct($stops, 'delivered');
        $planned = $this->quantitiesByProduct($stops, 'planned');

        $summaryItems = $ordered->map(function (int $orderedQuantity, string $productId) use ($items, $delivered, $planned) {
            $deliveredQuantity = $delivered->get($productId, 0);
            $pendingQuantity = max(0, $orderedQuantity - $deliveredQuantity);
            $plannedActiveQuantity = $planned->get($productId, 0);

            $line = $items->firstWhere('product_id', $productId);
            $product = $line?->relationLoaded('product') ? $line->product : null;

            return [
                'product_id' => $productId,
                'product_name' => $line?->product_name ?? $product?->name,
                'sku' => $product?->sku,
                'ordered_quantity' => $orderedQuantity,
                'delivered_quantity' => $deliveredQuantity,
                'pending_quantity' => $pendingQuantity,
                'planned_active_quantity' => $plannedActiveQuantity,
                'unassigned_pending_quantity' => max(0, $pendingQuantity - $plannedActiveQuantity),
            ];
        })->values();

        return [
            'has_pending_delivery' => $summaryItems->contains(fn (array $item) => $item['pending_quantity'] > 0),
            'items' => $summaryItems->all(),
            'pending_delivery_quantity' => (int) $summaryItems->sum('pending_quantity'),
        ];
    }

    private function quantitiesByProduct(Collection $stops, string $quantity): Collection
    {
        return $stops
            ->filter(fn ($stop) => $quantity === 'planned'
                ? $stop->status !== 'cancelled'
                : $stop->status === 'completed')
            ->flatMap(function ($stop) use ($quantity) {
                $routeIsActive = $stop->relationLoaded('route')
                    && in_array($stop->route?->status, self::ACTIVE_ROUTE_STATUSES, true);

                return ($quantity === 'planned' && ! $routeIsActive) || ! $stop->relationLoaded('items')
                    ? []
                    : $stop->items->map(fn ($item) => [
                        'product_id' => $item->product_id,
                        'quantity' => (int) ($item->{$quantity === 'planned' ? 'quantity_planned' : 'quantity_delivered'} ?? 0),
                    ]);
            })
            ->groupBy('product_id')
            ->map(fn (Collection $rows) => (int) $rows->sum('quantity'));
    }
}
