<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Support\QuantityMath;
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

        $ordered = $items->groupBy('product_id')->map(
            fn (Collection $lines): string => $this->sumQuantities($lines->pluck('quantity')->all())
        );
        $delivered = $this->quantitiesByProduct($stops, 'delivered');
        $planned = $this->quantitiesByProduct($stops, 'planned');

        $summaryItems = $ordered->map(function (string $orderedQuantity, string $productId) use ($items, $delivered, $planned) {
            $deliveredQuantity = QuantityMath::normalize($delivered->get($productId, '0'));
            $pendingQuantity = QuantityMath::max('0', QuantityMath::subtract($orderedQuantity, $deliveredQuantity));
            $plannedActiveQuantity = QuantityMath::normalize($planned->get($productId, '0'));

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
                'unassigned_pending_quantity' => QuantityMath::max(
                    '0',
                    QuantityMath::subtract($pendingQuantity, $plannedActiveQuantity)
                ),
            ];
        })->values();

        return [
            'has_pending_delivery' => $summaryItems->contains(
                fn (array $item): bool => QuantityMath::isPositive($item['pending_quantity'])
            ),
            'items' => $summaryItems->all(),
            'pending_delivery_quantity' => $this->sumQuantities($summaryItems->pluck('pending_quantity')->all()),
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
                        'quantity' => QuantityMath::normalize(
                            $item->{$quantity === 'planned' ? 'quantity_planned' : 'quantity_delivered'} ?? '0'
                        ),
                    ]);
            })
            ->groupBy('product_id')
            ->map(fn (Collection $rows): string => $this->sumQuantities($rows->pluck('quantity')->all()));
    }

    /** @param array<int, int|string> $quantities */
    private function sumQuantities(array $quantities): string
    {
        return array_reduce(
            $quantities,
            fn (string $total, int|string $quantity): string => QuantityMath::add($total, $quantity),
            '0.0000'
        );
    }
}
