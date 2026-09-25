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
        string $concept
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

        return DB::transaction(function () use ($product, $user, $type, $quantity, $concept) {
            $product = Product::where('id', $product->id)->lockForUpdate()->first();

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
            ]);

            $movement->load(['product', 'user']);

            return $movement;
        });
    }
}
