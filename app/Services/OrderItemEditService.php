<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\CommercialOperationEvent;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\User;
use App\Support\MoneyMath;
use App\Support\QuantityMath;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderItemEditService
{
    public function __construct(
        private readonly CommercialOperationService $commercialOperationService,
        private readonly OrderEditabilityService $orderEditabilityService,
        private readonly OrderDeliveryDateService $orderDeliveryDateService,
    ) {}

    /**
     * Updates the final commercial quantities only. The editability snapshot is
     * recalculated while the order is locked; callers must not send it back.
     */
    public function update(
        CommercialOperation $operation,
        ?array $items,
        ?string $requestedDeliveryDate,
        ?string $reason,
        ?string $observation,
        User $user
    ): CommercialOperation {
        return DB::transaction(function () use ($operation, $items, $requestedDeliveryDate, $reason, $observation, $user) {
            $order = CommercialOperation::forStore($user->store_id)
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->type !== 'order') {
                throw ValidationException::withMessages([
                    'order' => ['La operación indicada no es un pedido.'],
                ]);
            }

            if ($items === null) {
                $previousDate = $this->changeDeliveryDate($order, $requestedDeliveryDate, $reason);

                CommercialOperationEvent::create([
                    'store_id' => $order->store_id,
                    'operation_id' => $order->id,
                    'event_type' => 'order_edited',
                    'previous_date' => $previousDate,
                    'new_date' => $requestedDeliveryDate,
                    'reason' => $reason,
                    'observation' => $observation,
                    'metadata' => ['items' => []],
                    'user_id' => $user->id,
                    'previous_status' => $order->status,
                    'new_status' => $order->status,
                ]);

                return $order->fresh();
            }

            $lockedItems = OperationItem::query()
                ->where('operation_id', $order->id)
                ->orderBy('product_id')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $order->setRelation('items', $lockedItems);

            $requestedQuantities = collect($items)
                ->mapWithKeys(fn (array $item): array => [$item['product_id'] => QuantityMath::normalize($item['quantity'])]);
            $currentQuantities = $lockedItems
                ->groupBy('product_id')
                ->map(fn (Collection $lines): string => $this->sumQuantities($lines->pluck('quantity')->all()));
            $productIds = $currentQuantities->keys()
                ->merge($requestedQuantities->keys())
                ->unique()
                ->sort()
                ->values();

            $products = Product::forStore($order->store_id)
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'items' => ['Uno o más productos no existen o no pertenecen a la tienda.'],
                ]);
            }

            $order->loadMissing('items.product');
            $editability = $this->orderEditabilityService->describe($order);

            if (! $editability['editable']) {
                throw ValidationException::withMessages([
                    'order' => [$this->editabilityMessage($editability['block_reason'])],
                ]);
            }

            $minimumQuantities = collect($editability['items'])
                ->mapWithKeys(fn (array $item): array => [$item['product_id'] => QuantityMath::normalize($item['minimum_quantity'])]);
            $changes = [];

            foreach ($productIds as $productId) {
                $currentQuantity = QuantityMath::normalize($currentQuantities->get($productId, '0'));
                $requestedQuantity = QuantityMath::normalize($requestedQuantities->get($productId, '0'));
                $minimumQuantity = QuantityMath::normalize($minimumQuantities->get($productId, '0'));

                if (QuantityMath::compare($requestedQuantity, $minimumQuantity) < 0) {
                    throw ValidationException::withMessages([
                        'items' => ["La cantidad solicitada para {$products[$productId]->name} no puede ser menor al mínimo editable de {$minimumQuantity}."],
                    ]);
                }

                if (QuantityMath::compare($requestedQuantity, $currentQuantity) !== 0) {
                    $changes[$productId] = [
                        'product_id' => $productId,
                        'product_name' => $products[$productId]->name,
                        'previous_quantity' => $currentQuantity,
                        'new_quantity' => $requestedQuantity,
                    ];
                }
            }

            if ($changes === [] && $requestedDeliveryDate === null) {
                return $order->fresh();
            }

            $previousDate = $requestedDeliveryDate === null
                ? $order->requested_delivery_date?->format('Y-m-d')
                : $this->changeDeliveryDate($order, $requestedDeliveryDate, $reason);

            $order->payments()->orderBy('id')->lockForUpdate()->get();
            $paidInCents = (int) round((float) $order->payments()->sum('amount') * 100);
            $previousTotal = (float) $order->total;

            foreach ($changes as $productId => $change) {
                $delta = QuantityMath::subtract($change['new_quantity'], $change['previous_quantity']);
                $product = $products[$productId];

                if (QuantityMath::isPositive($delta)) {
                    $availableStock = QuantityMath::subtract($product->stock, $product->stock_reserved);

                    if (QuantityMath::compare($availableStock, $delta) < 0) {
                        throw ValidationException::withMessages([
                            'items' => ["No hay stock disponible suficiente para aumentar {$product->name}."],
                        ]);
                    }

                    $this->addCommercialObligation($order, $product, $delta);
                    $product->update([
                        'stock_reserved' => QuantityMath::add($product->stock_reserved, $delta),
                    ]);

                    continue;
                }

                $quantityToRelease = QuantityMath::isNegative($delta)
                    ? QuantityMath::subtract('0', $delta)
                    : '0.0000';

                if (QuantityMath::compare($product->stock_reserved, $quantityToRelease) < 0) {
                    throw ValidationException::withMessages([
                        'stock_reserved' => ["La reserva de {$product->name} es inconsistente para la reducción solicitada."],
                    ]);
                }

                $this->commercialOperationService->reduceCommercialObligation(
                    $order->id,
                    $productId,
                    $quantityToRelease
                );
                $product->update([
                    'stock_reserved' => QuantityMath::subtract($product->stock_reserved, $quantityToRelease),
                ]);
            }

            $this->commercialOperationService->recalculateTotals($order);
            $updatedOrder = CommercialOperation::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $newTotalInCents = (int) round((float) $updatedOrder->total * 100);

            if ($newTotalInCents < $paidInCents) {
                throw ValidationException::withMessages([
                    'items' => ['El nuevo total del pedido no puede ser menor al monto ya pagado.'],
                ]);
            }

            CommercialOperationEvent::create([
                'store_id' => $updatedOrder->store_id,
                'operation_id' => $updatedOrder->id,
                'event_type' => 'order_edited',
                'previous_date' => $previousDate,
                'new_date' => $updatedOrder->requested_delivery_date,
                'reason' => $requestedDeliveryDate === null ? 'items_updated' : $reason,
                'observation' => $requestedDeliveryDate === null ? null : $observation,
                'metadata' => [
                    'items' => array_values($changes),
                    'totals' => [
                        'previous' => $previousTotal,
                        'new' => (float) $updatedOrder->total,
                    ],
                ],
                'user_id' => $user->id,
                'previous_status' => $updatedOrder->status,
                'new_status' => $updatedOrder->status,
            ]);

            return $updatedOrder->fresh();
        });
    }

    private function changeDeliveryDate(CommercialOperation $order, ?string $requestedDeliveryDate, ?string $reason): string
    {
        if ($requestedDeliveryDate === null) {
            throw ValidationException::withMessages([
                'requested_delivery_date' => ['La fecha de entrega es obligatoria.'],
            ]);
        }

        if ($reason === null) {
            throw ValidationException::withMessages([
                'reason' => ['El motivo es obligatorio cuando cambia la fecha de entrega.'],
            ]);
        }

        return $this->orderDeliveryDateService->change($order, $requestedDeliveryDate);
    }

    private function addCommercialObligation(CommercialOperation $order, Product $product, int|string $quantity): void
    {
        $sourceItem = $order->items()
            ->where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        $quantity = QuantityMath::normalize($quantity);
        $sourceQuantity = $sourceItem ? QuantityMath::normalize($sourceItem->quantity) : null;
        $price = $sourceItem?->price ?? $product->price;

        OperationItem::create([
            'operation_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $sourceItem?->product_name ?? $product->name,
            'quantity' => $quantity,
            'price' => $price,
            'subtotal' => MoneyMath::multiplyQuantityByPrice($quantity, $price),
            'tax_amount' => $sourceItem && $sourceQuantity && QuantityMath::isPositive($sourceQuantity)
                ? MoneyMath::proportional($sourceItem->tax_amount, $quantity, $sourceQuantity)
                : '0.00',
            'discount_amount' => $sourceItem && $sourceQuantity && QuantityMath::isPositive($sourceQuantity)
                ? MoneyMath::proportional($sourceItem->discount_amount, $quantity, $sourceQuantity)
                : '0.00',
        ]);
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

    private function editabilityMessage(?string $blockReason): string
    {
        return match ($blockReason) {
            'terminal_status' => 'El pedido no puede editarse en su estado actual.',
            'active_extra_sale' => 'El pedido tiene una venta extra activa en una ruta operativa.',
            default => 'El pedido no puede editarse actualmente.',
        };
    }
}
