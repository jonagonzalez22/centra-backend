<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\RouteStop;
use App\Models\RouteStopCollection;
use App\Models\RouteStopItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DeliveryCollectionAmountService
{
    /**
     * Calculate the amount enabled for collection for proposed delivered quantities.
     *
     * @param  array<string, int>  $proposedQuantities  route_stop_item_id => quantity_delivered
     * @return array<string, float>
     */
    public function calculate(
        RouteStop $stop,
        array $proposedQuantities,
        bool $lockForUpdate = false,
        ?string $excludeDeclaredCollectionId = null
    ): array {
        $orderQuery = CommercialOperation::forStore($stop->route->store_id)
            ->where('id', $stop->order_id);

        if ($lockForUpdate) {
            $orderQuery->lockForUpdate();
        }

        $order = $orderQuery->firstOrFail();
        $operationItems = $this->operationItems($order, $lockForUpdate);
        $previousDelivered = $this->previousDeliveredByProduct($stop);
        $currentDelivered = $this->currentDeliveredByProduct($stop, $proposedQuantities);
        $cumulativeDelivered = $previousDelivered->map(fn ($quantity) => (int) $quantity);

        foreach ($currentDelivered as $productId => $quantity) {
            $cumulativeDelivered[$productId] = (int) ($cumulativeDelivered[$productId] ?? 0) + $quantity;
        }

        $valueBefore = $this->valueDeliveredQuantities($operationItems, $previousDelivered->all());
        $valueAfter = $this->valueDeliveredQuantities($operationItems, $cumulativeDelivered->all());
        $currentValue = round(max(0, $valueAfter - $valueBefore), 2);
        $verifiedPaid = $this->verifiedPayments($order->id, $lockForUpdate);
        $pendingDeclared = $this->pendingDeclaredCollections(
            $order->id,
            $lockForUpdate,
            $excludeDeclaredCollectionId
        );
        $orderTotal = round((float) $order->total, 2);
        $collectibleDeliveredValue = min($orderTotal, $valueAfter);
        $amountToCollect = round(max(0, $collectibleDeliveredValue - $verifiedPaid - $pendingDeclared), 2);

        return [
            'order_total' => $orderTotal,
            'delivered_value_current_stop' => $currentValue,
            'delivered_value_cumulative' => $valueAfter,
            'verified_paid_amount' => $verifiedPaid,
            'pending_declared_amount' => $pendingDeclared,
            'amount_to_collect_now' => $amountToCollect,
        ];
    }

    private function operationItems(CommercialOperation $order, bool $lockForUpdate): Collection
    {
        $query = OperationItem::where('operation_id', $order->id)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** @return \Illuminate\Support\Collection<string, int> */
    private function previousDeliveredByProduct(RouteStop $stop): \Illuminate\Support\Collection
    {
        return RouteStopItem::query()
            ->select('route_stop_items.product_id', DB::raw('SUM(route_stop_items.quantity_delivered) as delivered_quantity'))
            ->join('route_stops', 'route_stops.id', '=', 'route_stop_items.route_stop_id')
            ->join('delivery_routes', 'delivery_routes.id', '=', 'route_stops.route_id')
            ->where('route_stops.order_id', $stop->order_id)
            ->where('route_stops.id', '!=', $stop->id)
            ->where('route_stops.status', 'completed')
            ->where('delivery_routes.status', '!=', 'cancelled')
            ->where('route_stop_items.quantity_delivered', '>', 0)
            ->groupBy('route_stop_items.product_id')
            ->pluck('delivered_quantity', 'route_stop_items.product_id')
            ->map(fn ($quantity) => (int) $quantity);
    }

    /**
     * @param  array<string, int>  $proposedQuantities
     * @return \Illuminate\Support\Collection<string, int>
     */
    private function currentDeliveredByProduct(
        RouteStop $stop,
        array $proposedQuantities
    ): \Illuminate\Support\Collection {
        return RouteStopItem::where('route_stop_id', $stop->id)
            ->whereIn('id', array_keys($proposedQuantities))
            ->get(['id', 'product_id'])
            ->groupBy('product_id')
            ->map(fn (Collection $items) => $items->sum(
                fn (RouteStopItem $item) => (int) $proposedQuantities[$item->id]
            ));
    }

    /**
     * Assign delivered quantities to economic lines FIFO and value each line proportionally.
     *
     * @param  array<string, int>  $quantitiesByProduct
     */
    private function valueDeliveredQuantities(Collection $operationItems, array $quantitiesByProduct): float
    {
        $total = 0.0;

        foreach ($operationItems->groupBy('product_id') as $productId => $lines) {
            $remaining = max(0, (int) ($quantitiesByProduct[$productId] ?? 0));

            foreach ($lines as $line) {
                if ($remaining <= 0) {
                    break;
                }

                $lineQuantity = (int) $line->quantity;
                if ($lineQuantity <= 0) {
                    continue;
                }

                $allocated = min($remaining, $lineQuantity);
                $lineTotal = round(
                    (float) $line->subtotal
                    + (float) $line->tax_amount
                    - (float) $line->discount_amount,
                    2
                );

                $total += $allocated === $lineQuantity
                    ? $lineTotal
                    : round($lineTotal * $allocated / $lineQuantity, 2);
                $remaining -= $allocated;
            }
        }

        return round(max(0, $total), 2);
    }

    private function verifiedPayments(string $orderId, bool $lockForUpdate): float
    {
        $query = OperationPayment::where('operation_id', $orderId);

        if ($lockForUpdate) {
            return round((float) $query->lockForUpdate()->get(['amount'])->sum('amount'), 2);
        }

        return round((float) $query->sum('amount'), 2);
    }

    private function pendingDeclaredCollections(
        string $orderId,
        bool $lockForUpdate,
        ?string $excludeCollectionId
    ): float {
        $query = RouteStopCollection::where('commercial_operation_id', $orderId)
            ->where('status', 'declared')
            ->when($excludeCollectionId, fn ($query) => $query->where('id', '!=', $excludeCollectionId));

        if ($lockForUpdate) {
            return round((float) $query->lockForUpdate()->get(['amount'])->sum('amount'), 2);
        }

        return round((float) $query->sum('amount'), 2);
    }
}
