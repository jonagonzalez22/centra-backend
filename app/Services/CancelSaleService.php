<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\CommercialOperation;
use App\Models\CommercialOperationEvent;
use App\Models\OperationItem;
use App\Models\OperationPayment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelSaleService
{
    public function cancel(
        CommercialOperation $sale,
        string $reasonCode,
        ?string $reasonNote,
        User $user
    ): CommercialOperation {
        return DB::transaction(function () use ($sale, $reasonCode, $reasonNote, $user) {
            $sale = CommercialOperation::forStore($user->store_id)
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($sale->type !== 'sale' || $sale->status !== 'confirmed') {
                throw ValidationException::withMessages([
                    'status' => ['La venta no se puede cancelar en su estado actual.'],
                ]);
            }

            $payments = OperationPayment::query()
                ->where('operation_id', $sale->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->with('storePaymentMethod.paymentMethod')
                ->get();

            if ($payments->contains(fn (OperationPayment $payment) => $payment->status !== 'active')) {
                throw ValidationException::withMessages([
                    'status' => ['La venta no se puede cancelar en su estado actual.'],
                ]);
            }

            $sessions = $this->lockAndValidateCashSessions($payments, $user->store_id);
            $restoredStock = $this->restoreStock($sale, $user->store_id);

            $this->reversePayments($payments, $sessions, $user);

            CommercialOperationEvent::create([
                'store_id' => $sale->store_id,
                'operation_id' => $sale->id,
                'event_type' => 'sale_cancelled',
                'previous_date' => null,
                'new_date' => null,
                'reason' => $reasonCode,
                'observation' => $reasonNote,
                'metadata' => [
                    'payment_ids_reversed' => $payments->pluck('id')->values()->all(),
                    'cash_session_ids' => $sessions->keys()->values()->all(),
                    'restored_stock' => $restoredStock,
                ],
                'user_id' => $user->id,
                'previous_status' => 'confirmed',
                'new_status' => 'cancelled',
                'reason_code' => $reasonCode,
                'reason_note' => $reasonNote,
            ]);

            $sale->update(['status' => 'cancelled']);

            return $sale->fresh();
        });
    }

    /**
     * @return Collection<string, CashSession>
     */
    private function lockAndValidateCashSessions(Collection $payments, string $storeId): Collection
    {
        $sessionIds = $payments->pluck('cash_session_id')->filter()->unique()->sort()->values();

        if ($payments->contains(fn (OperationPayment $payment) => blank($payment->cash_session_id))) {
            throw ValidationException::withMessages([
                'cash' => ['La venta no puede cancelarse porque uno de sus pagos no está asociado a una caja operativa.'],
            ]);
        }

        $sessions = CashSession::forStore($storeId)
            ->whereIn('id', $sessionIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($sessions->count() !== $sessionIds->count()
            || $sessions->contains(fn (CashSession $session) => $session->status !== 'open')) {
            throw ValidationException::withMessages([
                'cash' => ['La venta no puede cancelarse porque la caja asociada ya está en proceso de cierre o fue cerrada.'],
            ]);
        }

        return $sessions;
    }

    /**
     * @return array<int, array{product_id: string, quantity: int}>
     */
    private function restoreStock(CommercialOperation $sale, string $storeId): array
    {
        $quantities = OperationItem::query()
            ->where('operation_id', $sale->id)
            ->orderBy('product_id')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id')
            ->map(fn (Collection $items) => (int) $items->sum('quantity'));

        $products = Product::forStore($storeId)
            ->whereIn('id', $quantities->keys())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($products->count() !== $quantities->count()) {
            throw new \RuntimeException('No se pudieron encontrar todos los productos de la venta para restaurar el stock.');
        }

        return $quantities->map(function (int $quantity, string $productId) use ($products): array {
            /** @var Product $product */
            $product = $products->get($productId);
            $product->stock += $quantity;

            if (! Product::validateStockIntegrity($product->stock, $product->stock_reserved)) {
                throw new \RuntimeException("Stock integrity violation on product {$product->id}.");
            }

            $product->save();

            return ['product_id' => $productId, 'quantity' => $quantity];
        })->values()->all();
    }

    /**
     * @param  Collection<int, OperationPayment>  $payments
     * @param  Collection<string, CashSession>  $sessions
     */
    private function reversePayments(Collection $payments, Collection $sessions, User $user): void
    {
        $cashTotalsBySession = [];

        foreach ($payments as $payment) {
            if ($payment->storePaymentMethod?->paymentMethod?->code === 'cash') {
                $cashTotalsBySession[$payment->cash_session_id] = ($cashTotalsBySession[$payment->cash_session_id] ?? 0)
                    + $this->toCents($payment->amount);
            }
        }

        foreach ($cashTotalsBySession as $sessionId => $cashTotal) {
            /** @var CashSession $session */
            $session = $sessions->get($sessionId);
            $expectedCents = $this->toCents($session->expected_amount);
            $updatedExpectedCents = $expectedCents - $cashTotal;

            if ($updatedExpectedCents < 0) {
                throw ValidationException::withMessages([
                    'cash' => ["La caja {$session->id} tiene un importe esperado inconsistente."],
                ]);
            }

            $session->expected_amount = $updatedExpectedCents / 100;
            $session->save();
        }

        $reversedAt = now();
        foreach ($payments as $payment) {
            $payment->update([
                'status' => 'reversed',
                'reversed_at' => $reversedAt,
                'reversed_by' => $user->id,
            ]);
        }
    }

    private function toCents(float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
