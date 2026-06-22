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
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('La cantidad debe ser mayor a cero.');
        }

        if (! in_array($type, ['input', 'output', 'adjustment'])) {
            throw new \InvalidArgumentException('Tipo de movimiento inválido.');
        }

        return DB::transaction(function () use ($product, $user, $type, $quantity, $concept) {
            $product = Product::where('id', $product->id)->lockForUpdate()->first();

            $previousStock = $product->stock;

            if ($type === 'output' || $type === 'adjustment') {
                if ($product->stock < $quantity) {
                    throw new \InvalidArgumentException('Stock insuficiente para realizar el movimiento.');
                }
                $newStock = $product->stock - $quantity;
            } else {
                $newStock = $product->stock + $quantity;
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
