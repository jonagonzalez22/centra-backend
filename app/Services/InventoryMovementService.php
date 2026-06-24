<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InventoryMovementService
{
    public function recordMovement(
        Product $product,
        User $user,
        string $type,
        int $quantity,
        string $concept
    ): InventoryMovement {
        if (! in_array($type, ['input', 'output', 'adjustment'])) {
            throw new \InvalidArgumentException('Tipo de movimiento inválido.');
        }

        if ($type === 'input' && $quantity <= 0) {
            throw new \InvalidArgumentException('Para entradas, la cantidad debe ser mayor a cero.');
        }

        if ($type === 'output') {
            $quantity = abs($quantity) * -1;
        }

        return DB::transaction(function () use ($product, $user, $type, $quantity, $concept) {
            $product = Product::where('id', $product->id)->lockForUpdate()->first();

            $previousStock = $product->stock;
            $newStock = $product->stock + $quantity;

            if ($newStock < 0) {
                throw new \InvalidArgumentException('El stock resultante no puede ser negativo.');
            }

            $product->update(['stock' => $newStock]);

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
