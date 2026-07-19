<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\Product;
use App\Models\StorePaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommercialOperationService
{
    public function create(array $data, string $storeId, string $userId): CommercialOperation
    {
        return DB::transaction(function () use ($data, $storeId, $userId) {
            $type = $data['type'];
            $isSale = $type === 'sale';
            $items = $data['items'];
            $payments = $data['payments'] ?? [];

            $customerId = $data['customer_id'] ?? null;
            $requestedDeliveryDate = $data['requested_delivery_date'] ?? null;

            $this->validateBusinessRules($data, $storeId);

            usort($items, fn ($a, $b) => strcmp($a['product_id'], $b['product_id']));

            $products = $this->lockAndValidateProducts($items, $storeId);

            $subtotal = 0;
            $tax = 0;
            $discount = 0;
            $total = 0;

            foreach ($items as $index => $item) {
                $itemSubtotal = round($item['quantity'] * $item['price'], 2);
                $itemTax = round($item['tax_amount'] ?? 0, 2);
                $itemDiscount = round($item['discount_amount'] ?? 0, 2);
                $itemTotal = round($itemSubtotal + $itemTax - $itemDiscount, 2);

                $subtotal += $itemSubtotal;
                $tax += $itemTax;
                $discount += $itemDiscount;
                $total += $itemTotal;
            }

            $subtotal = round($subtotal, 2);
            $tax = round($tax, 2);
            $discount = round($discount, 2);
            $total = round($total, 2);

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

            $this->applyStockChanges($products, $items, $isSale);

            $status = $isSale ? 'confirmed' : 'open';

            $operationNumber = CommercialOperation::generateNumber($type, $storeId);

            $operation = CommercialOperation::create([
                'store_id' => $storeId,
                'user_id' => $userId,
                'customer_id' => $customerId,
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
                $itemSubtotal = round($item['quantity'] * $item['price'], 2);

                OperationItem::create([
                    'operation_id' => $operation->id,
                    'product_id' => $item['product_id'],
                    'product_name' => $product->name,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'subtotal' => $itemSubtotal,
                    'tax_amount' => round($item['tax_amount'] ?? 0, 2),
                    'discount_amount' => round($item['discount_amount'] ?? 0, 2),
                ]);
            }

            foreach ($payments as $payment) {
                OperationPayment::create([
                    'operation_id' => $operation->id,
                    'store_payment_method_id' => $payment['store_payment_method_id'],
                    'amount' => $payment['amount'],
                    'reference' => $payment['reference'] ?? null,
                ]);
            }

            return $operation;
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

    private function lockAndValidateProducts(array $items, string $storeId): array
    {
        $products = [];

        foreach ($items as $index => $item) {
            $product = Product::forStore($storeId)
                ->lockForUpdate()
                ->find($item['product_id']);

            if (! $product) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'El producto no existe o no pertenece a tu tienda.',
                ]);
            }

            $available = $product->stock - $product->stock_reserved;

            if ($item['quantity'] > $available) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => 'Stock insuficiente. Disponible: '.$available.' unidades.',
                ]);
            }

            $products[$index] = $product;
        }

        return $products;
    }

    private function applyStockChanges(array $products, array $items, bool $isSale): void
    {
        foreach ($items as $index => $item) {
            $product = $products[$index];

            if ($isSale) {
                $product->stock = $product->stock - $item['quantity'];
            } else {
                $product->stock_reserved = $product->stock_reserved + $item['quantity'];
            }

            $product->save();
        }
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
                ->find($payment['store_payment_method_id']);

            if (! $storePaymentMethod) {
                throw ValidationException::withMessages([
                    "payments.{$index}.store_payment_method_id" => 'El medio de pago no existe o no está configurado para tu tienda.',
                ]);
            }
        }
    }
}
