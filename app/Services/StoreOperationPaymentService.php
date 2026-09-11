<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\CommercialOperation;
use App\Models\CommercialOperationEvent;
use App\Models\OperationPayment;
use App\Models\StorePaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreOperationPaymentService
{
    public function __construct(private readonly CashSessionService $cashSessionService) {}

    public function registerOrderPayment(CommercialOperation $operation, array $data, User $user): CommercialOperation
    {
        return DB::transaction(function () use ($operation, $data, $user) {
            $operation = CommercialOperation::forStore($user->store_id)
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($operation->type !== 'order') {
                throw ValidationException::withMessages(['order' => ['La operación indicada no es un pedido.']]);
            }

            if (in_array($operation->status, ['cancelled', 'closed'], true)) {
                throw ValidationException::withMessages(['status' => ['El pedido no admite nuevos pagos.']]);
            }

            $operation->payments()->orderBy('id')->lockForUpdate()->get();
            $paidInCents = (int) round((float) $operation->payments()->sum('amount') * 100);
            $totalInCents = (int) round((float) $operation->total * 100);
            $amountInCents = (int) round((float) $data['amount'] * 100);
            $pendingInCents = max(0, $totalInCents - $paidInCents);

            if ($amountInCents <= 0) {
                throw ValidationException::withMessages(['amount' => ['El monto debe ser mayor a cero.']]);
            }
            if ($amountInCents > $pendingInCents) {
                throw ValidationException::withMessages(['amount' => ['El monto no puede superar el saldo pendiente del pedido.']]);
            }

            $payment = $this->createForStore(
                $operation,
                $data,
                $user,
                ['origin' => 'manual_collection']
            );

            CommercialOperationEvent::create([
                'store_id' => $operation->store_id,
                'operation_id' => $operation->id,
                'event_type' => 'payment_registered',
                'previous_date' => $operation->created_at->toDateString(),
                'new_date' => null,
                'reason' => 'manual_collection',
                'observation' => null,
                'metadata' => [
                    'payment_id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'store_payment_method_id' => $payment->store_payment_method_id,
                    'payment_method_name' => $payment->storePaymentMethod->custom_name
                        ?? $payment->storePaymentMethod->paymentMethod->name,
                    'reference' => $payment->reference,
                    'origin' => 'manual_collection',
                ],
                'user_id' => $user->id,
                'previous_status' => $operation->status,
                'new_status' => $operation->status,
            ]);

            return $operation->fresh();
        });
    }

    public function createForStore(
        CommercialOperation $operation,
        array $data,
        User $user,
        array $paymentDetails = []
    ): OperationPayment {
        $session = $this->openSession($user);
        $method = StorePaymentMethod::forStore($user->store_id)
            ->whereKey($data['store_payment_method_id'])
            ->where('is_enabled', true)
            ->whereHas('paymentMethod', fn ($query) => $query->where('is_active', true))
            ->with('paymentMethod')
            ->first();

        if (! $method) {
            throw ValidationException::withMessages([
                'store_payment_method_id' => ['El medio de pago no está habilitado para la tienda.'],
            ]);
        }
        if ($method->requires_reference && empty($data['reference'])) {
            throw ValidationException::withMessages(['reference' => ['El medio de pago requiere una referencia.']]);
        }

        $payment = OperationPayment::create([
            'operation_id' => $operation->id,
            'store_payment_method_id' => $method->id,
            'cash_session_id' => $session->id,
            'registered_by' => $user->id,
            'amount' => $data['amount'],
            'reference' => $data['reference'] ?? null,
            'payment_details' => $paymentDetails ?: null,
        ]);

        if ($method->paymentMethod->code === 'cash') {
            $session->increment('expected_amount', $data['amount']);
        }

        return $payment->setRelation('storePaymentMethod', $method);
    }

    private function openSession(User $user): CashSession
    {
        $sessions = $this->cashSessionService->operationalSessions($user, true);

        if ($sessions->isEmpty()) {
            throw ValidationException::withMessages(['cash_session' => ['Debes abrir una caja antes de registrar cobros.']]);
        }
        if ($sessions->count() !== 1) {
            throw ValidationException::withMessages(['cash_session' => ['Se detectaron múltiples cajas abiertas. Cerrá las sesiones duplicadas antes de cobrar.']]);
        }

        return $sessions->sole();
    }
}
