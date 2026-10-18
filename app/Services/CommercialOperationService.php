<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\CommercialOperationEvent;
use App\Models\Customer;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\StorePaymentMethod;
use App\Models\User;
use App\Support\MoneyMath;
use App\Support\QuantityMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommercialOperationService
{
    public function __construct(
        private readonly StoreOperationPaymentService $storePaymentService,
        private readonly OrderDeliveryDateService $orderDeliveryDateService,
        private readonly ProductStockConsumptionResolver $stockConsumptionResolver,
    ) {}

    /**
     * Reduce quantities that are no longer commercially owed while preserving
     * item price history and existing payments. A physical snapshot restricts
     * the reduction to the historical line that released the merchandise.
     *
     * @param  array{stock_source_product_id: string|null, stock_consumption_quantity: string|null}|null  $physicalSnapshot
     * @return array<int, array{item: OperationItem, quantity: string}>
     */
    public function reduceCommercialObligation(
        string $orderId,
        string $productId,
        int|string $quantity,
        ?array $physicalSnapshot = null,
    ): array {
        $quantity = QuantityMath::normalize($quantity);

        if (! QuantityMath::isPositive($quantity)) {
            return [];
        }

        $order = CommercialOperation::where('id', $orderId)->lockForUpdate()->first();

        if (! $order) {
            throw ValidationException::withMessages([
                'order' => 'El pedido asociado no existe.',
            ]);
        }

        $itemsQuery = $order->items()
            ->where('product_id', $productId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($physicalSnapshot !== null) {
            foreach ($physicalSnapshot as $column => $value) {
                $value === null
                    ? $itemsQuery->whereNull($column)
                    : $itemsQuery->where($column, $value);
            }
        }

        $items = $itemsQuery->lockForUpdate()->get();

        $remaining = $quantity;
        $reductions = [];

        foreach ($items as $operationItem) {
            if (QuantityMath::isZero($remaining)) {
                break;
            }

            $oldQuantity = QuantityMath::normalize($operationItem->quantity);
            $decrement = QuantityMath::min($remaining, $oldQuantity);
            $newQuantity = QuantityMath::subtract($oldQuantity, $decrement);

            $reductions[] = ['item' => $operationItem, 'quantity' => $decrement];

            $operationItem->update([
                'quantity' => $newQuantity,
                'subtotal' => MoneyMath::multiplyQuantityByPrice($newQuantity, $operationItem->price),
                'tax_amount' => MoneyMath::proportional($operationItem->tax_amount, $newQuantity, $oldQuantity),
                'discount_amount' => MoneyMath::proportional($operationItem->discount_amount, $newQuantity, $oldQuantity),
            ]);

            $remaining = QuantityMath::subtract($remaining, $decrement);
        }

        if (QuantityMath::isPositive($remaining)) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad supera la obligación comercial pendiente del producto.',
            ]);
        }

        $this->recalculateTotals($order);

        return $reductions;
    }

    public function recalculateTotals(CommercialOperation $operation): void
    {
        $operation = CommercialOperation::where('id', $operation->id)->lockForUpdate()->firstOrFail();
        $items = $operation->items()->lockForUpdate()->get();
        $subtotal = $items->reduce(
            fn (string $total, OperationItem $item): string => MoneyMath::add($total, $item->subtotal),
            '0.00'
        );
        $tax = $items->reduce(
            fn (string $total, OperationItem $item): string => MoneyMath::add($total, $item->tax_amount),
            '0.00'
        );
        $discount = $items->reduce(
            fn (string $total, OperationItem $item): string => MoneyMath::add($total, $item->discount_amount),
            '0.00'
        );

        $operation->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => $discount,
            'total' => MoneyMath::subtract(MoneyMath::add($subtotal, $tax), $discount),
        ]);
    }

    public function create(array $data, string $storeId, string $userId): CommercialOperation
    {
        return DB::transaction(function () use ($data, $storeId, $userId) {
            $type = $data['type'];
            $isSale = $type === 'sale';
            $items = $data['items'];
            $payments = $data['payments'] ?? [];
            $user = User::where('id', $userId)->where('store_id', $storeId)->firstOrFail();

            $customerId = $data['customer_id'] ?? null;
            $customerDisplayName = $data['customer_display_name'] ?? null;
            $requestedDeliveryDate = $data['requested_delivery_date'] ?? null;

            $this->validateBusinessRules($data, $storeId);

            if ($customerId) {
                $customer = Customer::forStore($storeId)->find($customerId);

                if (! $customer) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'El cliente no existe o no pertenece a tu tienda.',
                    ]);
                }

                $customerDisplayName = $customer->display_name;
            }

            usort($items, fn ($a, $b) => strcmp($a['product_id'], $b['product_id']));

            $stockContext = $this->lockAndValidateProducts($items, $storeId);
            $products = $stockContext['products'];

            $subtotal = '0.00';
            $tax = '0.00';
            $discount = '0.00';
            $total = '0.00';

            foreach ($items as $index => $item) {
                $quantity = QuantityMath::normalize($item['quantity']);
                $itemSubtotal = MoneyMath::multiplyQuantityByPrice($quantity, (string) $item['price']);
                $itemTax = MoneyMath::normalize((string) ($item['tax_amount'] ?? 0));
                $itemDiscount = MoneyMath::normalize((string) ($item['discount_amount'] ?? 0));
                $itemTotal = MoneyMath::subtract(MoneyMath::add($itemSubtotal, $itemTax), $itemDiscount);

                $subtotal = MoneyMath::add($subtotal, $itemSubtotal);
                $tax = MoneyMath::add($tax, $itemTax);
                $discount = MoneyMath::add($discount, $itemDiscount);
                $total = MoneyMath::add($total, $itemTotal);
            }

            $totalPaid = $this->calculateTotalPaid($payments);
            $totalPaid = round($totalPaid, 2);

            $this->validatePayments($payments, $total, $totalPaid, $storeId);

            if (! $isSale && $totalPaid > $total) {
                throw ValidationException::withMessages([
                    'payments' => 'El monto total de pagos no puede superar el total de la operación.',
                ]);
            }

            if ($isSale && $totalPaid > $total) {
                throw ValidationException::withMessages([
                    'payments' => 'El monto total de pagos no puede superar el total de la operación.',
                ]);
            }

            if ($isSale && $totalPaid < $total && ! $customerId) {
                throw ValidationException::withMessages([
                    'customer_id' => 'El cliente es obligatorio cuando la venta no está completamente pagada.',
                ]);
            }

            $this->applyStockChanges($stockContext, $isSale);

            $status = $isSale ? 'confirmed' : 'open';

            $operationNumber = CommercialOperation::generateNumber($type, $storeId);

            $operation = CommercialOperation::create([
                'store_id' => $storeId,
                'user_id' => $userId,
                'customer_id' => $customerId,
                'customer_display_name' => $customerDisplayName,
                'operation_number' => $operationNumber,
                'type' => $type,
                'status' => $status,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'discount' => $discount,
                'total' => $total,
                'requested_delivery_date' => $isSale ? null : $requestedDeliveryDate,
            ]);

            foreach ($items as $index => $item) {
                $product = $products[$index];
                $itemSubtotal = MoneyMath::multiplyQuantityByPrice(
                    QuantityMath::normalize($item['quantity']),
                    (string) $item['price']
                );

                OperationItem::create([
                    'operation_id' => $operation->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $product->name,
                    ...$this->stockConsumptionResolver->operationItemSnapshot($product),
                    'measurement_unit_code' => $product->stockMeasurementUnit->code,
                    'measurement_unit_symbol' => $product->stockMeasurementUnit->symbol,
                    'sale_quantity_step' => QuantityMath::normalize($product->sale_quantity_step),
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'subtotal' => $itemSubtotal,
                    'tax_amount' => MoneyMath::normalize((string) ($item['tax_amount'] ?? 0)),
                    'discount_amount' => MoneyMath::normalize((string) ($item['discount_amount'] ?? 0)),
                ]);
            }

            foreach ($payments as $payment) {
                $this->storePaymentService->createForStore($operation, $payment, $user, [
                    'origin' => $isSale ? 'pos_sale' : 'order_deposit',
                ]);
            }

            return $operation;
        });
    }

    public function rescheduleDeliveryDate(
        CommercialOperation $operation,
        string $newDate,
        string $reason,
        ?string $observation,
        User $user
    ): CommercialOperation {
        return DB::transaction(function () use ($operation, $newDate, $reason, $observation, $user) {
            $operation = CommercialOperation::where('id', $operation->id)->lockForUpdate()->firstOrFail();

            if ($operation->type !== 'order') {
                throw ValidationException::withMessages([
                    'type' => ['Solo los pedidos pueden ser reprogramados.'],
                ]);
            }

            $currentDate = $this->orderDeliveryDateService->change($operation, $newDate, 'new_date');

            CommercialOperationEvent::create([
                'store_id' => $operation->store_id,
                'operation_id' => $operation->id,
                'event_type' => 'delivery_date_changed',
                'previous_date' => $currentDate,
                'new_date' => $newDate,
                'reason' => $reason,
                'observation' => $observation,
                'user_id' => $user->id,
            ]);

            return $operation->fresh();
        });
    }

    public function cancel(
        CommercialOperation $operation,
        string $reasonCode,
        ?string $reasonNote,
        User $user
    ): CommercialOperation {
        return DB::transaction(function () use ($operation, $reasonCode, $reasonNote, $user) {
            $operation = CommercialOperation::where('id', $operation->id)->lockForUpdate()->firstOrFail();

            if ($operation->type !== 'order') {
                throw ValidationException::withMessages([
                    'type' => ['Solo los pedidos pueden ser cancelados.'],
                ]);
            }

            if ($operation->status !== 'open') {
                throw ValidationException::withMessages([
                    'status' => ['Solo los pedidos abiertos pueden ser cancelados.'],
                ]);
            }

            $previousDate = $operation->requested_delivery_date?->format('Y-m-d');

            CommercialOperationEvent::create([
                'store_id' => $operation->store_id,
                'operation_id' => $operation->id,
                'event_type' => 'order_cancelled',
                'previous_date' => $previousDate,
                'new_date' => null,
                'reason' => $reasonCode,
                'observation' => $reasonNote,
                'user_id' => $user->id,
                'previous_status' => 'open',
                'new_status' => 'cancelled',
                'reason_code' => $reasonCode,
                'reason_note' => $reasonNote,
            ]);

            // Every open order reserves stock when it is created, regardless of
            // its requested delivery date. Cancelling it must release that same
            // pending reservation before changing its status.
            $items = $operation->items()->orderBy('id')->lockForUpdate()->get();
            $this->releaseReservedStockForOperationItems($operation->store_id, $items);

            $operation->update([
                'status' => 'cancelled',
            ]);

            return $operation->fresh();
        });
    }

    private function validateBusinessRules(array $data, string $storeId): void
    {
        $type = $data['type'];
        $errors = [];

        if ($type === 'order') {
            if (empty($data['customer_id'])) {
                $errors['customer_id'] = 'El cliente es obligatorio para pedidos.';
            }
            if (empty($data['requested_delivery_date'])) {
                $errors['requested_delivery_date'] = 'La fecha de entrega solicitada es obligatoria para pedidos.';
            }

            if (! empty($errors)) {
                throw ValidationException::withMessages($errors);
            }
        }
    }

    /**
     * @return array{
     *     products: array<int, Product>,
     *     stock_products: \Illuminate\Support\Collection<string, Product>,
     *     physical_quantities: array<int, string>,
     *     physical_totals: array<string, string>
     * }
     */
    private function lockAndValidateProducts(array $items, string $storeId): array
    {
        $products = [];
        $commercialProductIds = collect($items)->pluck('product_id')->unique()->sort()->values();

        // The preliminary read only discovers stock owners. C2 does not expose
        // association changes publicly; after the deterministic lock we resolve
        // again from the locked rows before using any configuration.
        $preliminaryProducts = Product::forStore($storeId)
            ->whereIn('id', $commercialProductIds)
            ->get()
            ->keyBy('id');

        if ($preliminaryProducts->count() !== $commercialProductIds->count()) {
            throw ValidationException::withMessages([
                'items' => ['Uno o más productos no existen o no pertenecen a la tienda.'],
            ]);
        }

        $stockOwnerIds = $preliminaryProducts->map(
            fn (Product $product): string => $product->stock_source_product_id ?? $product->id
        );
        $lockIds = $commercialProductIds->merge($stockOwnerIds)->unique()->sort()->values();

        $lockedProducts = Product::forStore($storeId)
            ->whereIn('id', $lockIds)
            ->with('stockMeasurementUnit')
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($lockedProducts->count() !== $lockIds->count()) {
            throw new \RuntimeException('No se pudieron bloquear todos los productos involucrados en la operación.');
        }

        $productsById = $lockedProducts;
        $physicalQuantities = [];
        $physicalTotals = [];

        foreach ($items as $index => $item) {
            $product = $productsById->get($item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'El producto no existe o no pertenece a tu tienda.',
                ]);
            }

            if (! $product->is_active || ! $product->is_sellable) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'El producto no está disponible para nuevas ventas o pedidos.',
                ]);
            }

            if ($product->stock_source_product_id !== null) {
                $source = $lockedProducts->get($product->stock_source_product_id);
                if (! $source) {
                    throw new \LogicException('La fuente de stock del producto no pudo bloquearse.');
                }
                $product->setRelation('stockSourceProduct', $source);
            }

            if ($quantityError = $product->commercialQuantityError($item['quantity'])) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => $quantityError,
                ]);
            }

            $products[$index] = $product;
            $consumption = $this->stockConsumptionResolver->resolve($product);
            $physicalQuantity = QuantityMath::multiply($item['quantity'], $consumption->consumptionFactor);
            $physicalQuantities[$index] = $physicalQuantity;
            $stockProductId = $consumption->stockProduct->id;
            $physicalTotals[$stockProductId] = QuantityMath::add(
                $physicalTotals[$stockProductId] ?? '0.0000',
                $physicalQuantity,
            );
        }

        foreach ($physicalTotals as $stockProductId => $requiredQuantity) {
            $stockProduct = $lockedProducts->get($stockProductId);
            $available = QuantityMath::subtract($stockProduct->stock, $stockProduct->stock_reserved);

            if (QuantityMath::compare($requiredQuantity, $available) > 0) {
                throw ValidationException::withMessages([
                    'items' => ['Stock insuficiente para los productos que consumen '.$stockProduct->name.'.'],
                ]);
            }
        }

        return [
            'products' => $products,
            'stock_products' => $lockedProducts,
            'physical_quantities' => $physicalQuantities,
            'physical_totals' => $physicalTotals,
        ];
    }

    private function applyStockChanges(array $stockContext, bool $isSale): void
    {
        foreach ($stockContext['physical_totals'] as $stockProductId => $physicalQuantity) {
            /** @var Product $product */
            $product = $stockContext['stock_products']->get($stockProductId);

            if ($isSale) {
                $product->stock = QuantityMath::subtract($product->stock, $physicalQuantity);
            } else {
                $product->stock_reserved = QuantityMath::add($product->stock_reserved, $physicalQuantity);
            }

            if (! Product::validateStockIntegrity($product->stock, $product->stock_reserved)) {
                throw new \RuntimeException("Stock integrity violation on product {$product->id}: stock={$product->stock}, reserved={$product->stock_reserved}");
            }

            $product->save();
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OperationItem>  $items
     */
    public function releaseReservedStockForOperationItems(string $storeId, $items): void
    {
        $itemQuantities = $items->map(fn (OperationItem $item): array => [
            'item' => $item,
            'quantity' => QuantityMath::normalize($item->quantity),
        ])->all();

        $this->releaseReservedStockForItemQuantities($storeId, $itemQuantities);
    }

    /**
     * @param  array<int, array{item: OperationItem, quantity: string}>  $itemQuantities
     */
    public function releaseReservedStockForItemQuantities(string $storeId, array $itemQuantities): void
    {
        $physicalTotals = $this->physicalTotalsForItemQuantities($itemQuantities);

        $this->releaseReservedStockForPhysicalTotals($storeId, $physicalTotals);
    }

    /** @param array<string, string> $physicalTotals */
    public function releaseReservedStockForPhysicalTotals(string $storeId, array $physicalTotals): void
    {
        if ($physicalTotals === []) {
            return;
        }

        $stockProducts = Product::forStore($storeId)
            ->whereIn('id', array_keys($physicalTotals))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($stockProducts->count() !== count($physicalTotals)) {
            throw new \RuntimeException('No se pudieron encontrar todas las fuentes de stock de la operación.');
        }

        foreach ($physicalTotals as $stockProductId => $physicalQuantity) {
            /** @var Product $product */
            $product = $stockProducts->get($stockProductId);
            if (QuantityMath::compare($physicalQuantity, $product->stock_reserved) > 0) {
                throw ValidationException::withMessages([
                    'stock_reserved' => ['La reserva física es insuficiente para liberar la obligación comercial.'],
                ]);
            }

            $product->stock_reserved = QuantityMath::subtract($product->stock_reserved, $physicalQuantity);
            $product->save();

            if (! Product::validateStockIntegrity($product->stock, $product->stock_reserved)) {
                throw new \RuntimeException(
                    "Stock integrity violation on product {$product->id}: stock={$product->stock}, reserved={$product->stock_reserved}"
                );
            }
        }
    }

    /**
     * @param  array<int, array{item: OperationItem, quantity: string}>  $itemQuantities
     * @return array<string, string>
     */
    public function physicalTotalsForItemQuantities(array $itemQuantities): array
    {
        $physicalTotals = [];
        $snapshotResolver = app(OperationItemStockConsumptionResolver::class);

        foreach ($itemQuantities as ['item' => $item, 'quantity' => $commercialQuantity]) {
            $consumption = $snapshotResolver->resolve($item);
            $physicalQuantity = QuantityMath::multiply($commercialQuantity, $consumption->consumptionFactor);
            $physicalTotals[$consumption->stockProductId] = QuantityMath::add(
                $physicalTotals[$consumption->stockProductId] ?? '0.0000',
                $physicalQuantity,
            );
        }

        return $physicalTotals;
    }

    private function calculateTotalPaid(array $payments): float
    {
        return array_reduce($payments, function ($carry, $payment) {
            return $carry + $payment['amount'];
        }, 0.0);
    }

    private function validatePayments(array $payments, float $total, float $totalPaid, string $storeId): void
    {
        foreach ($payments as $index => $payment) {
            $storePaymentMethod = StorePaymentMethod::forStore($storeId)
                ->where('is_enabled', true)
                ->whereHas('paymentMethod', fn ($query) => $query->where('is_active', true))
                ->find($payment['store_payment_method_id']);

            if (! $storePaymentMethod) {
                throw ValidationException::withMessages([
                    "payments.{$index}.store_payment_method_id" => 'El medio de pago no existe o no está configurado para tu tienda.',
                ]);
            }
        }
    }
}
