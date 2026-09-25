<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\RouteStop;
use App\Models\RouteStopCollection;
use App\Models\RouteStopItem;
use App\Support\MoneyMath;
use App\Support\QuantityMath;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DeliveryCollectionAmountService
{
    /**
     * Calculate the amount enabled for collection for proposed delivered quantities.
     *
     * @param  array<string, int|string>  $proposedQuantities  route_stop_item_id => quantity_delivered
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
        $cumulativeDelivered = $previousDelivered->map(fn ($quantity): string => QuantityMath::normalize($quantity));

        foreach ($currentDelivered as $productId => $quantity) {
            $cumulativeDelivered[$productId] = QuantityMath::add($cumulativeDelivered[$productId] ?? '0', $quantity);
        }

        $valueBefore = $this->valueDeliveredQuantities($operationItems, $previousDelivered->all());
        $valueAfter = $this->valueDeliveredQuantities($operationItems, $cumulativeDelivered->all());
        $currentValue = MoneyMath::max('0', MoneyMath::subtract($valueAfter, $valueBefore));
        $verifiedPaid = $this->verifiedPayments($order->id, $lockForUpdate);
        $pendingDeclared = $this->pendingDeclaredCollections(
            $order->id,
            $lockForUpdate,
            $excludeDeclaredCollectionId
        );
        $orderTotal = MoneyMath::normalize($order->total);
        $collectibleDeliveredValue = MoneyMath::min($orderTotal, $valueAfter);
        $amountToCollect = MoneyMath::max(
            '0',
            MoneyMath::subtract(MoneyMath::subtract($collectibleDeliveredValue, $verifiedPaid), $pendingDeclared)
        );

        return [
            'order_total' => (float) $orderTotal,
            'delivered_value_current_stop' => (float) $currentValue,
            'delivered_value_cumulative' => (float) $valueAfter,
            'verified_paid_amount' => (float) $verifiedPaid,
            'pending_declared_amount' => (float) $pendingDeclared,
            'amount_to_collect_now' => (float) $amountToCollect,
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

    /** @return \Illuminate\Support\Collection<string, string> */
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
            ->map(fn ($quantity): string => QuantityMath::normalize($quantity));
    }

    /**
     * @param  array<string, int|string>  $proposedQuantities
     * @return \Illuminate\Support\Collection<string, string>
     */
    private function currentDeliveredByProduct(
        RouteStop $stop,
        array $proposedQuantities
    ): \Illuminate\Support\Collection {
        return RouteStopItem::where('route_stop_id', $stop->id)
            ->whereIn('id', array_keys($proposedQuantities))
            ->get(['id', 'product_id'])
            ->groupBy('product_id')
            ->map(fn (Collection $items): string => $items->reduce(
                fn (string $total, RouteStopItem $item): string => QuantityMath::add(
                    $total,
                    $proposedQuantities[$item->id]
                ),
                '0.0000'
            ));
    }

    /**
     * Assign delivered quantities to economic lines FIFO and value each line proportionally.
     *
     * @param  array<string, int|string>  $quantitiesByProduct
     */
    private function valueDeliveredQuantities(Collection $operationItems, array $quantitiesByProduct): string
    {
        $total = '0.00';

        foreach ($operationItems->groupBy('product_id') as $productId => $lines) {
            $remaining = QuantityMath::max('0', $quantitiesByProduct[$productId] ?? '0');

            foreach ($lines as $line) {
                if (QuantityMath::isZero($remaining)) {
                    break;
                }

                $lineQuantity = QuantityMath::normalize($line->quantity);
                if (! QuantityMath::isPositive($lineQuantity)) {
                    continue;
                }

                $allocated = QuantityMath::min($remaining, $lineQuantity);
                $lineTotal = MoneyMath::subtract(
                    MoneyMath::add($line->subtotal, $line->tax_amount),
                    $line->discount_amount
                );

                $allocatedValue = QuantityMath::compare($allocated, $lineQuantity) === 0
                    ? $lineTotal
                    : MoneyMath::proportional($lineTotal, $allocated, $lineQuantity);
                $total = MoneyMath::add($total, $allocatedValue);
                $remaining = QuantityMath::subtract($remaining, $allocated);
            }
        }

        return MoneyMath::max('0', $total);
    }

    private function verifiedPayments(string $orderId, bool $lockForUpdate): string
    {
        $query = OperationPayment::where('operation_id', $orderId);

        if ($lockForUpdate) {
            return $this->sumMoney($query->lockForUpdate()->get(['amount'])->pluck('amount')->all());
        }

        return $this->sumMoney($query->pluck('amount')->all());
    }

    private function pendingDeclaredCollections(
        string $orderId,
        bool $lockForUpdate,
        ?string $excludeCollectionId
    ): string {
        $query = RouteStopCollection::where('commercial_operation_id', $orderId)
            ->where('status', 'declared')
            ->when($excludeCollectionId, fn ($query) => $query->where('id', '!=', $excludeCollectionId));

        if ($lockForUpdate) {
            return $this->sumMoney($query->lockForUpdate()->get(['amount'])->pluck('amount')->all());
        }

        return $this->sumMoney($query->pluck('amount')->all());
    }

    /** @param array<int, int|string> $amounts */
    private function sumMoney(array $amounts): string
    {
        return array_reduce(
            $amounts,
            fn (string $total, int|string $amount): string => MoneyMath::add($total, $amount),
            '0.00'
        );
    }
}
