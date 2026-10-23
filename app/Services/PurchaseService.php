<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseCounter;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Support\MoneyMath;
use App\Support\QuantityMath;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function create(array $data, string $storeId, string $userId): Purchase
    {
        return DB::transaction(function () use ($data, $storeId, $userId) {
            [$supplier, $lines, $total] = $this->validatedLines($data, $storeId);
            $purchase = Purchase::create([
                'store_id' => $storeId,
                'supplier_id' => $supplier->id,
                'purchase_number' => $this->nextNumber($storeId),
                'purchase_type' => $data['purchase_type'],
                'status' => 'draft',
                'supplier_business_name' => $supplier->business_name,
                'supplier_tax_id' => $supplier->tax_id,
                'notes' => $data['notes'] ?? null,
                'total' => $total,
                'created_by' => $userId,
            ]);
            $this->replaceItems($purchase, $lines);

            return $purchase->load('items');
        });
    }

    public function update(Purchase $purchase, array $data, string $storeId, string $userId): Purchase
    {
        return DB::transaction(function () use ($purchase, $data, $storeId, $userId) {
            $locked = Purchase::forStore($storeId)->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['purchase' => 'Solo se pueden editar compras en borrador.']);
            }
            [$supplier, $lines, $total] = $this->validatedLines($data, $storeId);
            $locked->update([
                'supplier_id' => $supplier->id,
                'purchase_type' => $data['purchase_type'],
                'supplier_business_name' => $supplier->business_name,
                'supplier_tax_id' => $supplier->tax_id,
                'notes' => $data['notes'] ?? null,
                'total' => $total,
                'updated_by' => $userId,
            ]);
            $locked->items()->lockForUpdate()->get();
            $locked->items()->delete();
            $this->replaceItems($locked, $lines);

            return $locked->fresh()->load('items');
        });
    }

    public function order(Purchase $purchase, string $storeId, string $userId): Purchase
    {
        return DB::transaction(function () use ($purchase, $storeId, $userId) {
            $locked = Purchase::forStore($storeId)->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->purchase_type !== 'order') {
                throw ValidationException::withMessages(['purchase' => 'Solo las compras planificadas pueden emitirse como orden.']);
            }
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['purchase' => 'La compra ya no puede emitirse como orden.']);
            }

            $supplier = Supplier::forStore($storeId)->whereKey($locked->supplier_id)->lockForUpdate()->first();
            if (! $supplier || ! $supplier->is_active) {
                throw ValidationException::withMessages(['supplier_id' => 'El proveedor debe existir y estar activo para emitir la orden.']);
            }
            $items = $locked->items()->orderBy('product_id')->lockForUpdate()->get();
            $productIds = $items->pluck('product_id')->all();
            $products = Product::forStore($storeId)->whereIn('id', $productIds)->with('stockMeasurementUnit')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $associations = SupplierProduct::forStore($storeId)->where('supplier_id', $supplier->id)->whereIn('product_id', $productIds)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
            $total = '0.00';
            foreach ($items as $item) {
                $product = $products->get($item->product_id);
                if (! $product || ! $product->is_active) {
                    throw ValidationException::withMessages(['items' => 'Todos los productos deben existir y estar activos para emitir la orden.']);
                }
                if ($product->stock_source_product_id !== null) {
                    throw ValidationException::withMessages(['items' => 'Los productos con stock compartido no pueden emitirse en una orden de compra.']);
                }
                $association = $associations->get($product->id);
                if (! $association) {
                    throw ValidationException::withMessages(['items' => 'Todos los productos deben seguir asociados al proveedor para emitir la orden.']);
                }
                $subtotal = MoneyMath::multiplyQuantityByPrice($item->quantity_ordered, $item->unit_price);
                $total = MoneyMath::add($total, $subtotal);
                $item->update([
                    'product_name' => $product->name,
                    'product_sku' => $product->sku,
                    'supplier_code' => $association->supplier_code,
                    'supplier_product_name' => $association->supplier_product_name,
                    'measurement_unit_code' => $product->stockMeasurementUnit->code,
                    'measurement_unit_symbol' => $product->stockMeasurementUnit->symbol,
                    'subtotal' => $subtotal,
                ]);
            }
            $locked->update([
                'status' => 'ordered',
                'ordered_at' => now(),
                'supplier_business_name' => $supplier->business_name,
                'supplier_tax_id' => $supplier->tax_id,
                'total' => $total,
                'updated_by' => $userId,
            ]);

            return $locked->fresh()->load('items');
        });
    }

    public function cancel(Purchase $purchase, string $storeId, string $userId): Purchase
    {
        return DB::transaction(function () use ($purchase, $storeId, $userId) {
            $locked = Purchase::forStore($storeId)->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, ['draft', 'ordered'], true)) {
                throw ValidationException::withMessages(['purchase' => 'Solo se pueden cancelar compras en borrador u ordenadas sin recepción.']);
            }
            $locked->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_by' => $userId]);

            return $locked->fresh()->load('items');
        });
    }

    public function close(Purchase $purchase, string $storeId, string $userId): Purchase
    {
        return DB::transaction(function () use ($purchase, $storeId, $userId) {
            $locked = Purchase::forStore($storeId)->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'partially_received') {
                throw ValidationException::withMessages(['purchase' => 'Esta compra ya no puede cerrarse porque su estado cambió.']);
            }

            $items = $locked->items()->orderBy('id')->lockForUpdate()->get();
            $hasPendingQuantity = $items->contains(fn (PurchaseItem $item) => QuantityMath::compare($item->quantity_received, $item->quantity_ordered) < 0
            );
            if (! $hasPendingQuantity) {
                throw ValidationException::withMessages(['purchase' => 'No se puede cerrar una compra sin cantidades pendientes de recibir.']);
            }

            $locked->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $userId,
                'updated_by' => $userId,
            ]);

            return $locked->fresh()->load(['items', 'receipts.items', 'receipts.createdBy', 'closedBy']);
        });
    }

    /**
     * Direct purchases remain mutable until their first physical receipt.
     * Refresh their current catalog/supplier snapshots immediately before that
     * receipt freezes the purchase, preserving the existing item identities.
     */
    public function refreshDirectDraftSnapshots(Purchase $purchase, string $storeId, User $user): void
    {
        if ($purchase->purchase_type !== 'direct' || $purchase->status !== 'draft') {
            throw ValidationException::withMessages(['purchase' => ['Solo una compra directa en borrador puede congelarse al recibirla.']]);
        }

        $items = PurchaseItem::query()
            ->where('purchase_id', $purchase->id)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get();
        $data = [
            'supplier_id' => $purchase->supplier_id,
            'purchase_type' => 'direct',
            'notes' => $purchase->notes,
            'items' => $items->map(fn (PurchaseItem $item) => [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity_ordered,
                'unit_price' => $item->unit_price,
            ])->all(),
        ];
        [$supplier, $lines, $total] = $this->validatedLines($data, $storeId);
        $linesByProduct = collect($lines)->keyBy(fn (array $line) => $line['product']->id);

        foreach ($items as $item) {
            $line = $linesByProduct->get($item->product_id);
            $product = $line['product'];
            $association = $line['association'];
            $item->update([
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'supplier_code' => $association->supplier_code,
                'supplier_product_name' => $association->supplier_product_name,
                'measurement_unit_code' => $product->stockMeasurementUnit->code,
                'measurement_unit_symbol' => $product->stockMeasurementUnit->symbol,
                'quantity_ordered' => $line['quantity'],
                'subtotal' => $line['subtotal'],
            ]);
        }

        $purchase->update([
            'supplier_business_name' => $supplier->business_name,
            'supplier_tax_id' => $supplier->tax_id,
            'total' => $total,
            'updated_by' => $user->id,
        ]);
    }

    /** @return array{Supplier, array<int, array<string, mixed>>, string} */
    private function validatedLines(array $data, string $storeId): array
    {
        $supplier = Supplier::forStore($storeId)->whereKey($data['supplier_id'])->lockForUpdate()->first();
        if (! $supplier) {
            throw ValidationException::withMessages(['supplier_id' => 'El proveedor no existe o no pertenece a tu tienda.']);
        }
        if (! $supplier->is_active) {
            throw ValidationException::withMessages(['supplier_id' => 'Solo podés crear compras para proveedores activos.']);
        }
        $items = $data['items'];
        usort($items, fn (array $a, array $b) => strcmp($a['product_id'], $b['product_id']));
        $ids = array_column($items, 'product_id');
        $products = Product::forStore($storeId)->whereIn('id', $ids)->with('stockMeasurementUnit')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($products->count() !== count($ids)) {
            throw ValidationException::withMessages(['items' => 'Uno o más productos no existen o no pertenecen a tu tienda.']);
        }
        $associations = SupplierProduct::forStore($storeId)->where('supplier_id', $supplier->id)->whereIn('product_id', $ids)->orderBy('product_id')->lockForUpdate()->get()->keyBy('product_id');
        $lines = [];
        $total = '0.00';
        foreach ($items as $item) {
            $product = $products[$item['product_id']];
            if (! $product->is_active) {
                throw ValidationException::withMessages(['items' => 'Solo podés agregar productos activos a una compra.']);
            }
            if ($product->stock_source_product_id !== null) {
                throw ValidationException::withMessages(['items' => 'Los productos con stock compartido no pueden comprarse directamente. Seleccioná el producto que administra el stock físico.']);
            }
            $association = $associations->get($product->id);
            if (! $association) {
                throw ValidationException::withMessages(['items' => 'El producto seleccionado no está asociado a este proveedor.']);
            }
            $quantity = QuantityMath::normalize($item['quantity']);
            if (! QuantityMath::isPositive($quantity)) {
                throw ValidationException::withMessages(['items' => 'La cantidad debe ser mayor a cero.']);
            }
            $unitPrice = MoneyMath::normalize($item['unit_price']);
            if (MoneyMath::compare($unitPrice, '0') < 0) {
                throw ValidationException::withMessages(['items' => 'El precio unitario no puede ser negativo.']);
            }
            $subtotal = MoneyMath::multiplyQuantityByPrice($quantity, $unitPrice);
            $total = MoneyMath::add($total, $subtotal);
            $lines[] = compact('product', 'association', 'quantity', 'unitPrice', 'subtotal');
        }

        return [$supplier, $lines, $total];
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function replaceItems(Purchase $purchase, array $lines): void
    {
        foreach ($lines as $line) {
            $product = $line['product'];
            $association = $line['association'];
            PurchaseItem::create([
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'supplier_code' => $association->supplier_code,
                'supplier_product_name' => $association->supplier_product_name,
                'measurement_unit_code' => $product->stockMeasurementUnit->code,
                'measurement_unit_symbol' => $product->stockMeasurementUnit->symbol,
                'quantity_ordered' => $line['quantity'],
                'quantity_received' => '0.0000',
                'unit_price' => $line['unitPrice'],
                'subtotal' => $line['subtotal'],
            ]);
        }
    }

    private function nextNumber(string $storeId): string
    {
        $counter = PurchaseCounter::where('store_id', $storeId)->lockForUpdate()->first();
        if (! $counter) {
            try {
                $counter = PurchaseCounter::create(['store_id' => $storeId, 'last_number' => 0]);
            } catch (QueryException) {
                $counter = PurchaseCounter::where('store_id', $storeId)->lockForUpdate()->firstOrFail();
            }
            $counter = PurchaseCounter::whereKey($counter->id)->lockForUpdate()->firstOrFail();
        }
        $counter->increment('last_number');

        return 'OC-'.str_pad((string) $counter->fresh()->last_number, 6, '0', STR_PAD_LEFT);
    }
}
