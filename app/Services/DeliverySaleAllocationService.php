<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\DeliveryRoute;
use App\Models\DeliverySaleAllocation;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
use App\Support\MoneyMath;
use App\Support\QuantityMath;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Persists immutable commercial values for quantities physically delivered. */
class DeliverySaleAllocationService
{
    public function persist(RouteStop $stop, DeliveryRoute $route, CarbonInterface $occurredAt): void
    {
        DB::transaction(fn () => $this->persistWithinTransaction($stop, $route, $occurredAt));
    }

    private function persistWithinTransaction(RouteStop $stop, DeliveryRoute $route, CarbonInterface $occurredAt): void
    {
        if ($stop->route_id !== $route->id) {
            throw $this->invalid('La parada no pertenece a la ruta indicada.');
        }

        $operation = CommercialOperation::whereKey($stop->order_id)
            ->lockForUpdate()
            ->first();

        $stopItems = RouteStopItem::where('route_stop_id', $stop->id)
            ->where('quantity_delivered', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($stopItems->isEmpty()) {
            return;
        }

        if (! $operation) {
            throw $this->invalid('No se puede confirmar una entrega con mercadería sin un pedido comercial asignable.');
        }

        // Lock the commercial SKUs while their delivery cost is frozen. This
        // deliberately does not follow stock_source_product_id: physical stock
        // valuation is recorded independently by InventoryValuationService.
        $products = Product::whereIn('id', $stopItems->pluck('product_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        foreach ($stopItems as $stopItem) {
            if (! $products->has($stopItem->product_id) || $products[$stopItem->product_id]->store_id !== $route->store_id) {
                throw $this->invalid('Un producto entregado no pertenece a la tienda de la ruta.');
            }
        }

        $lines = OperationItem::where('operation_id', $operation->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($lines->isEmpty()) {
            throw $this->invalid('No se puede confirmar una entrega sin líneas comerciales asignables.');
        }
        if ($operation->store_id !== $route->store_id) {
            throw $this->invalid('El pedido de la parada no pertenece a la tienda de la ruta.');
        }
        $allocatedByLine = $this->allocatedByLine($lines);
        $alreadyAllocatedForStopItem = $this->allocatedByStopItem($stopItems);

        foreach ($stopItems as $stopItem) {
            $remaining = QuantityMath::subtract(
                $stopItem->quantity_delivered,
                $alreadyAllocatedForStopItem[$stopItem->id] ?? '0'
            );
            if (QuantityMath::isNegative($remaining)) {
                throw $this->invalid('Las allocations existentes superan la cantidad entregada.');
            }

            foreach ($lines->where('product_id', $stopItem->product_id) as $line) {
                if (QuantityMath::isZero($remaining)) {
                    break;
                }

                $lineQuantity = QuantityMath::normalize($line->quantity);
                $before = $allocatedByLine[$line->id] ?? '0.0000';
                $available = QuantityMath::subtract($lineQuantity, $before);
                if (QuantityMath::isNegative($available)) {
                    throw $this->invalid('Una línea comercial fue reducida por debajo de lo ya entregado.');
                }
                if (! QuantityMath::isPositive($available)) {
                    continue;
                }

                $quantity = QuantityMath::min($remaining, $available);
                $after = QuantityMath::add($before, $quantity);
                $gross = $this->allocationAmount($line->subtotal, $before, $after, $lineQuantity);
                $discount = $this->allocationAmount($line->discount_amount, $before, $after, $lineQuantity);
                $tax = $this->allocationAmount($line->tax_amount, $before, $after, $lineQuantity);

                DeliverySaleAllocation::firstOrCreate(
                    ['route_stop_item_id' => $stopItem->id, 'operation_item_id' => $line->id],
                    [
                        'store_id' => $route->store_id,
                        'route_id' => $route->id,
                        'route_stop_id' => $stop->id,
                        'operation_id' => $operation->id,
                        'product_id' => $stopItem->product_id,
                        'quantity_delivered' => $quantity,
                        'unit_price_snapshot' => $line->price,
                        'unit_cost_snapshot' => $products[$stopItem->product_id]->cost,
                        'gross_amount' => $gross,
                        'discount_amount' => $discount,
                        'tax_amount' => $tax,
                        'net_amount' => MoneyMath::subtract(MoneyMath::add($gross, $tax), $discount),
                        'price_list_id' => $line->price_list_id,
                        'price_list_name' => $line->price_list_name,
                        'discount_percentage' => $line->discount_percentage,
                        'is_extra' => $stopItem->is_extra,
                        'occurred_at' => $occurredAt,
                    ]
                );

                $allocatedByLine[$line->id] = $after;
                $remaining = QuantityMath::subtract($remaining, $quantity);
            }

            if (QuantityMath::isPositive($remaining)) {
                throw $this->invalid('La cantidad entregada supera la obligación comercial pendiente del producto.');
            }
        }
    }

    /** @return array<string, string> */
    private function allocatedByLine(Collection $lines): array
    {
        return DeliverySaleAllocation::whereIn('operation_item_id', $lines->pluck('id'))
            ->select('operation_item_id', DB::raw('SUM(quantity_delivered) as quantity'))
            ->groupBy('operation_item_id')
            ->pluck('quantity', 'operation_item_id')
            ->map(fn ($quantity): string => QuantityMath::normalize($quantity))
            ->all();
    }

    /** @return array<string, string> */
    private function allocatedByStopItem(Collection $stopItems): array
    {
        return DeliverySaleAllocation::whereIn('route_stop_item_id', $stopItems->pluck('id'))
            ->select('route_stop_item_id', DB::raw('SUM(quantity_delivered) as quantity'))
            ->groupBy('route_stop_item_id')
            ->pluck('quantity', 'route_stop_item_id')
            ->map(fn ($quantity): string => QuantityMath::normalize($quantity))
            ->all();
    }

    private function allocationAmount(string $amount, string $before, string $after, string $lineQuantity): string
    {
        return MoneyMath::subtract(
            MoneyMath::proportional($amount, $after, $lineQuantity),
            MoneyMath::proportional($amount, $before, $lineQuantity),
        );
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['delivery_sale_allocation' => [$message]]);
    }
}
