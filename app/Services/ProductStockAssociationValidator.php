<?php

namespace App\Services;

use App\Models\Product;
use App\Support\QuantityMath;

class ProductStockAssociationValidator
{
    public function validate(Product $child, ?Product $source, int|string|null $factor): void
    {
        if ($source === null) {
            if ($factor !== null) {
                throw new \InvalidArgumentException('Un producto sin fuente de stock no puede tener factor de consumo.');
            }

            return;
        }

        if ($factor === null || ! QuantityMath::isPositive($factor)) {
            throw new \InvalidArgumentException('El factor de consumo debe ser mayor a cero.');
        }

        if ($source->store_id !== $child->store_id) {
            throw new \InvalidArgumentException('El producto fuente debe pertenecer a la misma tienda.');
        }

        if ($source->id === $child->id) {
            throw new \InvalidArgumentException('Un producto no puede ser su propia fuente de stock.');
        }

        if (! $source->is_active) {
            throw new \InvalidArgumentException('El producto fuente debe estar activo.');
        }

        if ($source->stock_source_product_id !== null) {
            throw new \InvalidArgumentException('El producto fuente no puede depender de otro producto.');
        }

        if (! QuantityMath::isZero($child->stock)
            || ! QuantityMath::isZero($child->stock_reserved)
            || ! QuantityMath::isZero($child->stock_min)) {
            throw new \InvalidArgumentException('Un producto asociado debe tener stock, reserva y stock mínimo en cero.');
        }

        try {
            QuantityMath::multiply($factor, $child->sale_quantity_step ?? '1');
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException(
                'El factor de consumo y el paso de venta exceden la precisión de cuatro decimales.'
            );
        }
    }
}
