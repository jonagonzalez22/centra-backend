<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Support\QuantityMath;
use Illuminate\Support\Facades\DB;

class InventoryMovementService
{
    public function recordMovement(
        Product $product,
        User $user,
        string $type,
        int|string $quantity,
        string $concept,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): InventoryMovement {
        if (! in_array($type, ['input', 'output', 'adjustment'])) {
            throw new \InvalidArgumentException('Tipo de movimiento inválido.');
        }

        $quantity = QuantityMath::normalize($quantity);

        if ($type === 'input' && ! QuantityMath::isPositive($quantity)) {
            throw new \InvalidArgumentException('Para entradas, la cantidad debe ser mayor a cero.');
        }

        if ($type === 'output') {
            $quantity = QuantityMath::isPositive($quantity)
                ? QuantityMath::subtract('0', $quantity)
                : $quantity;
        }

        return DB::transaction(function () use ($product, $user, $type, $quantity, $concept, $referenceType, $referenceId) {
            $product = Product::where('id', $product->id)->lockForUpdate()->first();

            return $this->recordMovementForLockedProduct($product, $user, $type, $quantity, $concept, $referenceType, $referenceId);
        });
    }

    /**
     * Records a movement using a product row already locked by the caller.
     * This lets route reconciliation lock all physical sources in a stable
     * order before applying grouped impacts.
     */
    public function recordMovementForLockedProduct(
        Product $product,
        User $user,
        string $type,
        int|string $quantity,
        string $concept,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): InventoryMovement {
        if (! in_array($type, ['input', 'output', 'adjustment'])) {
            throw new \InvalidArgumentException('Tipo de movimiento inválido.');
        }

        $quantity = QuantityMath::normalize($quantity);
        if ($type === 'input' && ! QuantityMath::isPositive($quantity)) {
            throw new \InvalidArgumentException('Para entradas, la cantidad debe ser mayor a cero.');
        }
        if ($type === 'output') {
            $quantity = QuantityMath::isPositive($quantity)
                ? QuantityMath::subtract('0', $quantity)
                : $quantity;
        }

        if ($product->stock_source_product_id !== null) {
            throw new \InvalidArgumentException('El stock de este producto se administra desde su producto fuente.');
        }

        $previousStock = $product->stock;
        $newStock = QuantityMath::add($product->stock, $quantity);

        if (QuantityMath::isNegative($newStock)) {
            throw new \InvalidArgumentException('El stock resultante no puede ser negativo.');
        }

        $product->update(['stock' => $newStock]);

        if (! Product::validateStockIntegrity($newStock, $product->stock_reserved)) {
            throw new \RuntimeException("Stock integrity violation on product {$product->id}.");
        }

        $movement = InventoryMovement::create([
            'store_id' => $product->store_id,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'type' => $type,
            'quantity' => $quantity,
            'previous_stock' => $previousStock,
            'current_stock' => $newStock,
            'concept' => $concept,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        $movement->load(['product.stockMeasurementUnit', 'user']);

        return $movement;
    }

    /**
     * Applies a delivered physical quantity to an already locked stock owner.
     * Stock and reservation are changed together so the transient state never
     * violates stock integrity for a legitimately reserved delivery.
     */
    public function recordDeliveredForLockedProduct(
        Product $product,
        User $user,
        int|string $quantity,
        string $concept
    ): InventoryMovement {
        $quantity = QuantityMath::normalize($quantity);
        if (! QuantityMath::isPositive($quantity)) {
            throw new \InvalidArgumentException('La cantidad entregada debe ser mayor a cero.');
        }
        if ($product->stock_source_product_id !== null) {
            throw new \InvalidArgumentException('El stock de este producto se administra desde su producto fuente.');
        }

        $previousStock = $product->stock;
        $newStock = QuantityMath::subtract($product->stock, $quantity);
        if (QuantityMath::isNegative($newStock)) {
            throw new \InvalidArgumentException('El stock resultante no puede ser negativo.');
        }

        if (QuantityMath::compare($quantity, $product->stock_reserved) > 0) {
            throw new \InvalidArgumentException('La reserva física es insuficiente para registrar la entrega.');
        }

        $newReserved = QuantityMath::subtract($product->stock_reserved, $quantity);
        if (! Product::validateStockIntegrity($newStock, $newReserved)) {
            throw new \RuntimeException("Stock integrity violation on product {$product->id}.");
        }

        $product->update([
            'stock' => $newStock,
            'stock_reserved' => $newReserved,
        ]);

        $movement = InventoryMovement::create([
            'store_id' => $product->store_id,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'type' => 'output',
            'quantity' => QuantityMath::subtract('0', $quantity),
            'previous_stock' => $previousStock,
            'current_stock' => $newStock,
            'concept' => $concept,
        ]);
        $movement->load(['product.stockMeasurementUnit', 'user']);

        return $movement;
    }
}
