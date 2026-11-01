<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\CommercialOperation;
use App\Models\CommercialOperationEvent;
use App\Models\OperationPayment;
use App\Models\RouteStopCollection;
use App\Models\StorePaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentReversalService
{
    public function __construct(private readonly PaymentReversalEligibility $eligibility) {}

    public function reverse(string $paymentId, array $data, User $user): OperationPayment
    {
        return DB::transaction(function () use ($paymentId, $data, $user) {
            $paymentIdentity = OperationPayment::query()->find($paymentId);

            if (! $paymentIdentity) {
                throw (new ModelNotFoundException)->setModel(OperationPayment::class, [$paymentId]);
            }

            $operation = CommercialOperation::forStore($user->store_id)
                ->whereKey($paymentIdentity->operation_id)
                ->lockForUpdate()
                ->first();

            if (! $operation) {
                throw (new ModelNotFoundException)->setModel(OperationPayment::class, [$paymentId]);
            }

            $payment = OperationPayment::query()
                ->whereKey($paymentId)
                ->where('operation_id', $operation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $operation->payments()->orderBy('id')->lockForUpdate()->get();

            $paymentMethod = StorePaymentMethod::forStore($operation->store_id)
                ->whereKey($payment->store_payment_method_id)
                ->with('paymentMethod')
                ->firstOrFail();

            $session = CashSession::forStore($operation->store_id)
                ->whereKey($payment->cash_session_id)
                ->lockForUpdate()
                ->first();

            $hasDirectRouteLink = RouteStopCollection::query()
                ->where('operation_payment_id', $payment->id)
                ->lockForUpdate()
                ->exists();

            $decision = $this->eligibility->evaluate($payment, $operation, $session, $hasDirectRouteLink);
            if (! $decision['allowed']) {
                throw $this->validationError($this->eligibility->validationMessage($decision['reason']));
            }

            if ($paymentMethod->paymentMethod?->code === 'cash') {
                $expectedCents = $this->toCents($session->expected_amount);
                $updatedExpectedCents = $expectedCents - $this->toCents($payment->amount);

                if ($updatedExpectedCents < 0) {
                    throw $this->validationError("La caja {$session->id} tiene un importe esperado inconsistente.");
                }

                $session->update(['expected_amount' => $updatedExpectedCents / 100]);
            }

            $reversedAt = now();
            $payment->update([
                'status' => 'reversed',
                'reversed_at' => $reversedAt,
                'reversed_by' => $user->id,
            ]);

            CommercialOperationEvent::create([
                'store_id' => $operation->store_id,
                'operation_id' => $operation->id,
                'event_type' => 'payment_reversed',
                'previous_date' => $operation->created_at->toDateString(),
                'new_date' => null,
                'reason' => 'payment_reversal',
                'observation' => $data['reason_note'] ?? null,
                'metadata' => [
                    'payment_id' => $payment->id,
                    'origin' => $this->origin($payment),
                    'amount' => (float) $payment->amount,
                    'store_payment_method_id' => $payment->store_payment_method_id,
                    'payment_method_name' => $paymentMethod->custom_name ?? $paymentMethod->paymentMethod?->name,
                    'reference' => $payment->reference,
                    'cash_session_id' => $payment->cash_session_id,
                    'previous_payment_status' => 'active',
                    'new_payment_status' => 'reversed',
                ],
                'user_id' => $user->id,
                'previous_status' => $operation->status,
                'new_status' => $operation->status,
                'reason_code' => $data['reason_code'],
                'reason_note' => $data['reason_note'] ?? null,
            ]);

            return $payment->fresh();
        });
    }

    private function origin(OperationPayment $payment): ?string
    {
        return ($payment->payment_details ?? [])['origin'] ?? null;
    }

    private function toCents(float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function validationError(string $message): ValidationException
    {
        return ValidationException::withMessages(['payment' => [$message]]);
    }
}
