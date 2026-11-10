<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Support\QuantityMath;
use Illuminate\Support\Facades\DB;

class InventoryMovementService
{
    public function __construct(private readonly InventoryValuationService $inventoryValuationService) {}

    public function recordMovement(
        Product $product,
        User $user,
        string $type,
        int|string $quantity,
        string $concept,
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $valuationType = null,
        ?string $unitCost = null,
        bool $updateProductCost = false,
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

        return DB::transaction(function () use ($product, $user, $type, $quantity, $concept, $referenceType, $referenceId, $valuationType, $unitCost, $updateProductCost) {
            $product = Product::where('id', $product->id)->lockForUpdate()->first();

            return $this->recordMovementForLockedProduct($product, $user, $type, $quantity, $concept, $referenceType, $referenceId, $valuationType, $unitCost, $updateProductCost);
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
        ?string $valuationType = null,
        ?string $unitCost = null,
        bool $updateProductCost = false,
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

        $isPositiveInput = $type === 'input' || ($type === 'adjustment' && QuantityMath::isPositive($quantity));
        if ($unitCost !== null && ! $isPositiveInput) {
            throw new \InvalidArgumentException('El precio de costo solo puede informarse en entradas de inventario.');
        }
        if ($updateProductCost && $unitCost === null) {
            throw new \InvalidArgumentException('El precio de costo es obligatorio para actualizar el costo del producto.');
        }

        if ($product->stock_source_product_id !== null) {
            throw new \InvalidArgumentException('El stock de este producto se administra desde su producto fuente.');
        }

        $previousStock = $product->stock;
        $newStock = QuantityMath::add($product->stock, $quantity);

        if (QuantityMath::isNegative($newStock)) {
            throw new \InvalidArgumentException('El stock resultante no puede ser negativo.');
        }

        $productUpdates = ['stock' => $newStock];
        if ($updateProductCost) {
            $productUpdates['cost'] = $unitCost;
        }
        $product->update($productUpdates);

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

        if ($isPositiveInput && $referenceType !== 'purchase_receipt') {
            $this->inventoryValuationService->recordManualInput(
                $product,
                $valuationType ?? 'manual_adjustment',
                'inventory_movement',
                $movement->id,
                $quantity,
                $unitCost,
                $movement->created_at,
                $user,
            );
        }
        if ($type === 'output' || ($type === 'adjustment' && QuantityMath::isNegative($quantity))) {
            $this->inventoryValuationService->recordOutput(
                $product,
                $valuationType ?? 'adjustment_output',
                'inventory_movement',
                $movement->id,
                QuantityMath::subtract('0', $quantity),
                $movement->created_at,
                $user,
            );
        }

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
        string $concept,
        bool $recordValuation = true,
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
        if ($recordValuation) {
            $this->inventoryValuationService->recordOutput(
                $product,
                'delivery_output',
                'inventory_movement',
                $movement->id,
                $quantity,
                $movement->created_at,
                $user,
            );
        }

        return $movement;
    }
}
