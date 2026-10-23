<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptCounter;
use App\Models\PurchaseReceiptItem;
use App\Models\User;
use App\Support\QuantityMath;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptService
{
    public function __construct(
        private readonly InventoryMovementService $movementService,
        private readonly PurchaseService $purchaseService,
    ) {}

    /** @param array{received_at?: string, notes?: string|null, items: array<int, array{purchase_item_id: string, quantity: string}>} $data */
    public function receive(Purchase $purchase, array $data, string $storeId, User $user): PurchaseReceipt
    {
        return DB::transaction(function () use ($purchase, $data, $storeId, $user) {
            $lockedPurchase = Purchase::forStore($storeId)->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $this->ensureReceivable($lockedPurchase);

            $purchaseItems = PurchaseItem::query()
                ->where('purchase_id', $lockedPurchase->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lockedPurchase->purchase_type === 'direct' && $lockedPurchase->status === 'draft') {
                $this->purchaseService->refreshDirectDraftSnapshots($lockedPurchase, $storeId, $user);
                $purchaseItems = PurchaseItem::query()
                    ->where('purchase_id', $lockedPurchase->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
            }

            $requested = collect($data['items'])->mapWithKeys(fn (array $item) => [
                $item['purchase_item_id'] => QuantityMath::normalize($item['quantity']),
            ]);
            if ($requested->count() !== count($data['items'])) {
                throw ValidationException::withMessages(['items' => ['No se puede recibir el mismo item más de una vez en una recepción.']]);
            }

            $receiptItems = $requested->map(function (string $quantity, string $purchaseItemId) use ($purchaseItems): array {
                $purchaseItem = $purchaseItems->get($purchaseItemId);
                if (! $purchaseItem) {
                    throw ValidationException::withMessages(['items' => ['Uno de los items no pertenece a esta compra.']]);
                }

                $pending = QuantityMath::subtract($purchaseItem->quantity_ordered, $purchaseItem->quantity_received);
                if (QuantityMath::compare($quantity, $pending) > 0) {
                    throw ValidationException::withMessages(['items' => ["La cantidad recibida de {$purchaseItem->product_name} supera la cantidad pendiente."]]);
                }

                return compact('purchaseItem', 'quantity');
            })->sortBy(fn (array $line) => $line['purchaseItem']->product_id)->values();

            $productIds = $receiptItems->pluck('purchaseItem.product_id')->unique()->sort()->values()->all();
            $products = Product::forStore($storeId)
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($products->count() !== count($productIds)) {
                throw ValidationException::withMessages(['items' => ['Uno de los productos recibidos ya no existe en esta tienda.']]);
            }
            foreach ($products as $product) {
                if ($product->stock_source_product_id !== null) {
                    throw ValidationException::withMessages(['items' => ['Los productos con stock compartido no pueden recibirse directamente.']]);
                }
            }

            $receipt = PurchaseReceipt::create([
                'store_id' => $storeId,
                'purchase_id' => $lockedPurchase->id,
                'receipt_number' => $this->nextNumber($storeId),
                'received_at' => isset($data['received_at']) ? Carbon::parse($data['received_at']) : now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            foreach ($receiptItems as ['purchaseItem' => $purchaseItem, 'quantity' => $quantity]) {
                /** @var PurchaseItem $purchaseItem */
                /** @var Product $product */
                $product = $products->get($purchaseItem->product_id);
                PurchaseReceiptItem::create([
                    'purchase_receipt_id' => $receipt->id,
                    'purchase_item_id' => $purchaseItem->id,
                    'product_id' => $product->id,
                    'product_name_snapshot' => $purchaseItem->product_name,
                    'product_sku_snapshot' => $purchaseItem->product_sku,
                    'measurement_unit_code_snapshot' => $purchaseItem->measurement_unit_code,
                    'measurement_unit_symbol_snapshot' => $purchaseItem->measurement_unit_symbol,
                    'quantity_received' => $quantity,
                ]);
                $purchaseItem->update(['quantity_received' => QuantityMath::add($purchaseItem->quantity_received, $quantity)]);
                $this->movementService->recordMovementForLockedProduct(
                    $product,
                    $user,
                    'input',
                    $quantity,
                    "Recepción {$receipt->receipt_number} de {$lockedPurchase->purchase_number}",
                    'purchase_receipt',
                    $receipt->id,
                );
            }

            $allReceived = $purchaseItems->every(fn (PurchaseItem $item) => QuantityMath::compare($item->quantity_received, $item->quantity_ordered) === 0);
            $lockedPurchase->update([
                'status' => $allReceived ? 'received' : 'partially_received',
                'updated_by' => $user->id,
            ]);

            return $receipt->load(['items', 'createdBy']);
        });
    }

    private function ensureReceivable(Purchase $purchase): void
    {
        $allowedStatuses = $purchase->purchase_type === 'order'
            ? ['ordered', 'partially_received']
            : ['draft', 'partially_received'];

        if (! in_array($purchase->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages(['purchase' => ['La compra no está en un estado que permita registrar recepciones.']]);
        }
    }

    private function nextNumber(string $storeId): string
    {
        $counter = PurchaseReceiptCounter::query()->where('store_id', $storeId)->lockForUpdate()->first();
        if (! $counter) {
            try {
                $counter = PurchaseReceiptCounter::create(['store_id' => $storeId, 'last_number' => 0]);
            } catch (QueryException) {
                $counter = PurchaseReceiptCounter::query()->where('store_id', $storeId)->lockForUpdate()->firstOrFail();
            }
            $counter = PurchaseReceiptCounter::query()->whereKey($counter->id)->lockForUpdate()->firstOrFail();
        }
        $counter->increment('last_number');

        return 'REC-'.str_pad((string) $counter->fresh()->last_number, 6, '0', STR_PAD_LEFT);
    }
}
