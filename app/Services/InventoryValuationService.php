<?php

namespace App\Services;

use App\Models\InventoryValuationBalance;
use App\Models\InventoryValuationEntry;
use App\Models\Product;
use App\Models\User;
use App\Support\QuantityMath;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Historical, moving-weighted valuation of physical stock sources.
 * This service deliberately does not calculate commercial COGS.
 */
class InventoryValuationService
{
    private const MONEY_SCALE = 6;

    public function recordInitialStock(Product $source, ?string $unitCost, ?User $user = null): void
    {
        if (! QuantityMath::isPositive($source->stock)) {
            return;
        }

        $this->recordInput($source, 'initial_stock', 'product_initial_stock', $source->id, $source->stock, $unitCost, now(), $user);
    }

    public function recordPurchaseReceipt(Product $source, string $receiptItemId, string $quantity, string $unitCost, CarbonInterface $occurredAt, ?User $user): void
    {
        $this->recordInput($source, 'purchase_receipt', 'purchase_receipt_item', $receiptItemId, $quantity, $unitCost, $occurredAt, $user);
    }

    public function recordUnknownInput(Product $source, string $type, string $referenceType, string $referenceId, string $quantity, CarbonInterface $occurredAt, ?User $user): void
    {
        $this->recordInput($source, $type, $referenceType, $referenceId, $quantity, null, $occurredAt, $user);
    }

    /** Records a manual input as known only when the user explicitly supplied its unit cost. */
    public function recordManualInput(Product $source, string $type, string $referenceType, string $referenceId, string $quantity, ?string $unitCost, CarbonInterface $occurredAt, ?User $user): void
    {
        $this->recordInput($source, $type, $referenceType, $referenceId, $quantity, $unitCost, $occurredAt, $user);
    }

    public function recordOutput(Product $source, string $type, string $referenceType, string $referenceId, string $quantity, CarbonInterface $occurredAt, ?User $user, bool $physicalStockAlreadyDecremented = true): void
    {
        $quantity = QuantityMath::normalize($quantity);
        if (! QuantityMath::isPositive($quantity)) {
            return;
        }
        $this->assertSource($source);
        $physicalQuantityBeforeOutput = $physicalStockAlreadyDecremented
            ? QuantityMath::add($source->stock, $quantity)
            // Route execution historically keeps consolidated stock unchanged
            // until reconciliation. Legacy routes can also have been loaded
            // before valuation existed, so the delivered physical quantity is
            // the minimum unknown opening needed to keep valuation nonnegative.
            : QuantityMath::max($source->stock, $quantity);
        $this->bootstrapUnknownIfMissing($source, $physicalQuantityBeforeOutput, $occurredAt, $user);
        if (InventoryValuationEntry::query()->where([
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'stock_source_product_id' => $source->id,
        ])->where('quantity', '<', 0)->exists()) {
            return;
        }
        $balance = $this->lockedBalance($source);

        if (QuantityMath::compare($balance->quantity, $quantity) < 0) {
            throw ValidationException::withMessages(['inventory_valuation' => ['La valuación disponible es insuficiente para registrar la salida física.']]);
        }

        $unknownOut = QuantityMath::min($quantity, $balance->unknown_quantity);
        $knownOut = QuantityMath::subtract($quantity, $unknownOut);

        if (QuantityMath::isPositive($unknownOut)) {
            $this->appendIfAbsent($source, $type, $referenceType, $referenceId, QuantityMath::subtract('0', $unknownOut), null, null, 'unknown', $occurredAt, $user);
            $balance->unknown_quantity = QuantityMath::subtract($balance->unknown_quantity, $unknownOut);
        }

        if (QuantityMath::isPositive($knownOut)) {
            if ($balance->average_unit_cost === null) {
                throw new \LogicException('El saldo valorizado conocido no tiene costo promedio.');
            }
            $unitCost = $this->money($balance->average_unit_cost);
            $totalCost = $this->multiply($knownOut, $unitCost);
            $this->appendIfAbsent($source, $type, $referenceType, $referenceId, QuantityMath::subtract('0', $knownOut), $unitCost, $this->subtractMoney('0', $totalCost), 'known', $occurredAt, $user);
            $balance->known_quantity = QuantityMath::subtract($balance->known_quantity, $knownOut);
            $balance->total_value = $this->subtractMoney($balance->total_value, $totalCost);
        }

        $balance->quantity = QuantityMath::subtract($balance->quantity, $quantity);
        $this->refreshBalanceState($balance);
        $balance->save();
    }

    /** Restores exactly the original output amounts; it never uses today's average. */
    public function reverseOutput(Product $source, string $outputReferenceType, string $outputReferenceId, string $reversalReferenceType, string $reversalReferenceId, string $restoredQuantity, CarbonInterface $occurredAt, ?User $user): void
    {
        $this->assertSource($source);
        $entries = InventoryValuationEntry::query()
            ->where('store_id', $source->store_id)
            ->where('stock_source_product_id', $source->id)
            ->where('reference_type', $outputReferenceType)
            ->where('reference_id', $outputReferenceId)
            ->where('quantity', '<', 0)
            ->orderBy('cost_status')
            ->lockForUpdate()
            ->get();

        if ($entries->isEmpty()) {
            // Sales created before valuation have no historical unit cost. Their
            // cancellation still restores physical quantity, explicitly unknown.
            $this->recordInput($source, 'output_reversal', $reversalReferenceType, $reversalReferenceId, $restoredQuantity, null, $occurredAt, $user);

            return;
        }

        foreach ($entries as $entry) {
            $quantity = QuantityMath::subtract('0', $entry->quantity);
            $this->recordInput($source, 'output_reversal', $reversalReferenceType, $reversalReferenceId, $quantity, $entry->unit_cost, $occurredAt, $user, $entry->cost_status);
        }
    }

    private function recordInput(Product $source, string $type, string $referenceType, string $referenceId, string $quantity, ?string $unitCost, CarbonInterface $occurredAt, ?User $user, ?string $forcedStatus = null): void
    {
        $quantity = QuantityMath::normalize($quantity);
        if (! QuantityMath::isPositive($quantity)) {
            return;
        }
        $this->assertSource($source);
        // Existing installations are deliberately not backfilled by a migration.
        // On their first live valuation event, pre-existing physical units enter
        // explicitly as unknown so the balance remains physically synchronized.
        $this->bootstrapUnknownIfMissing($source, QuantityMath::subtract($source->stock, $quantity), $occurredAt, $user);
        $status = $forcedStatus ?? ($unitCost === null ? 'unknown' : 'known');
        $unitCost = $status === 'known' ? $this->money((string) $unitCost) : null;
        $totalCost = $unitCost === null ? null : $this->multiply($quantity, $unitCost);
        $balance = $this->lockedBalance($source);

        if (! $this->appendIfAbsent($source, $type, $referenceType, $referenceId, $quantity, $unitCost, $totalCost, $status, $occurredAt, $user)) {
            return;
        }

        $balance->quantity = QuantityMath::add($balance->quantity, $quantity);
        if ($status === 'known') {
            $balance->known_quantity = QuantityMath::add($balance->known_quantity, $quantity);
            $balance->total_value = $this->addMoney($balance->total_value, $totalCost);
        } else {
            $balance->unknown_quantity = QuantityMath::add($balance->unknown_quantity, $quantity);
        }
        $this->refreshBalanceState($balance);
        $balance->save();
    }

    /** @return bool whether an immutable event was appended */
    private function appendIfAbsent(Product $source, string $type, string $referenceType, string $referenceId, string $quantity, ?string $unitCost, ?string $totalCost, string $status, CarbonInterface $occurredAt, ?User $user): bool
    {
        $existing = InventoryValuationEntry::query()->where([
            'reference_type' => $referenceType, 'reference_id' => $referenceId,
            'stock_source_product_id' => $source->id, 'cost_status' => $status,
        ])->lockForUpdate()->exists();
        if ($existing) {
            return false;
        }

        try {
            InventoryValuationEntry::create([
                'store_id' => $source->store_id, 'stock_source_product_id' => $source->id,
                'type' => $type, 'reference_type' => $referenceType, 'reference_id' => $referenceId,
                'quantity' => $quantity, 'unit_cost' => $unitCost, 'total_cost' => $totalCost,
                'cost_status' => $status, 'occurred_at' => $occurredAt, 'created_by' => $user?->id,
            ]);
        } catch (QueryException $exception) {
            // A concurrent retry won the natural unique key. Its transaction owns the balance update.
            if (InventoryValuationEntry::query()->where([
                'reference_type' => $referenceType, 'reference_id' => $referenceId,
                'stock_source_product_id' => $source->id, 'cost_status' => $status,
            ])->exists()) {
                return false;
            }
            throw $exception;
        }

        return true;
    }

    private function lockedBalance(Product $source): InventoryValuationBalance
    {
        $balance = InventoryValuationBalance::query()
            ->where('store_id', $source->store_id)->where('stock_source_product_id', $source->id)
            ->lockForUpdate()->first();
        if ($balance) {
            return $balance;
        }

        try {
            InventoryValuationBalance::create([
                'store_id' => $source->store_id, 'stock_source_product_id' => $source->id,
                'quantity' => '0.0000', 'known_quantity' => '0.0000', 'unknown_quantity' => '0.0000',
                'total_value' => '0.000000', 'average_unit_cost' => null, 'cost_status' => 'known',
            ]);
        } catch (QueryException) {
            // Another transaction created it first; lock that canonical row.
        }

        return InventoryValuationBalance::query()
            ->where('store_id', $source->store_id)->where('stock_source_product_id', $source->id)
            ->lockForUpdate()->firstOrFail();
    }

    private function bootstrapUnknownIfMissing(Product $source, string $quantity, CarbonInterface $occurredAt, ?User $user): void
    {
        if (! QuantityMath::isPositive($quantity)
            || InventoryValuationBalance::query()->where('store_id', $source->store_id)->where('stock_source_product_id', $source->id)->exists()) {
            return;
        }

        $balance = $this->lockedBalance($source);
        if (! $this->appendIfAbsent($source, 'legacy_opening_unknown', 'inventory_valuation_bootstrap', $source->id, $quantity, null, null, 'unknown', $occurredAt, $user)) {
            return;
        }
        $balance->quantity = QuantityMath::add($balance->quantity, $quantity);
        $balance->unknown_quantity = QuantityMath::add($balance->unknown_quantity, $quantity);
        $this->refreshBalanceState($balance);
        $balance->save();
    }

    private function refreshBalanceState(InventoryValuationBalance $balance): void
    {
        if (QuantityMath::isZero($balance->quantity)) {
            $balance->known_quantity = '0.0000';
            $balance->unknown_quantity = '0.0000';
            $balance->total_value = '0.000000';
            $balance->average_unit_cost = null;
            $balance->cost_status = 'known';

            return;
        }
        if (QuantityMath::isPositive($balance->known_quantity)) {
            $balance->average_unit_cost = $this->divide($balance->total_value, $balance->known_quantity);
        } else {
            $balance->total_value = '0.000000';
            $balance->average_unit_cost = null;
        }
        $balance->cost_status = QuantityMath::isPositive($balance->unknown_quantity)
            ? (QuantityMath::isPositive($balance->known_quantity) ? 'mixed' : 'unknown')
            : 'known';
    }

    private function assertSource(Product $source): void
    {
        if ($source->stock_source_product_id !== null) {
            throw new \InvalidArgumentException('La valuación debe registrarse sobre la fuente física de stock.');
        }
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', self::MONEY_SCALE);
    }

    private function addMoney(string $left, string $right): string
    {
        return bcadd($this->money($left), $this->money($right), self::MONEY_SCALE);
    }

    private function subtractMoney(string $left, string $right): string
    {
        return bcsub($this->money($left), $this->money($right), self::MONEY_SCALE);
    }

    private function multiply(string $quantity, string $unitCost): string
    {
        return bcmul(QuantityMath::normalize($quantity), $this->money($unitCost), self::MONEY_SCALE);
    }

    private function divide(string $value, string $quantity): string
    {
        return bcdiv($this->money($value), QuantityMath::normalize($quantity), self::MONEY_SCALE);
    }
}
