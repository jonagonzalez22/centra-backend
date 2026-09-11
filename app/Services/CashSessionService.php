<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\StorePaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashSessionService
{
    public function __construct(private readonly CashBusinessDateService $businessDateService) {}

    public function open(User $actor, float $openingAmount, ?string $notes = null): CashSession
    {
        return DB::transaction(function () use ($actor, $openingAmount, $notes) {
            $user = User::whereKey($actor->id)
                ->where('store_id', $actor->store_id)
                ->with('store')
                ->lockForUpdate()
                ->firstOrFail();
            $businessDate = $this->businessDateService->currentForStore($user->store);
            $sessions = CashSession::forStore($user->store_id)
                ->operational($user->id, $businessDate)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($sessions->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'cash' => [$sessions->count() > 1
                        ? 'Se detectaron múltiples cajas operativas para la jornada actual.'
                        : 'El usuario ya tiene una sesión de caja abierta para la jornada actual.'],
                ]);
            }

            return CashSession::create([
                'store_id' => $user->store_id,
                'user_id' => $user->id,
                'business_date' => $businessDate,
                'status' => 'open',
                'opening_amount' => $openingAmount,
                'expected_amount' => $openingAmount,
                'real_amount' => null,
                'declared_amount' => null,
                'notes' => $notes,
                'opened_at' => now(),
                'submitted_at' => null,
                'closed_at' => null,
                'closed_by' => null,
            ]);
        });
    }

    public function submit(CashSession $session, User $actor, float $declaredAmount, ?string $notes = null): CashSession
    {
        return DB::transaction(function () use ($session, $actor, $declaredAmount, $notes) {
            $locked = CashSession::forStore($actor->store_id)
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->user_id !== $actor->id) {
                throw ValidationException::withMessages(['cash' => ['Sólo el propietario puede enviar esta caja a arqueo.']]);
            }
            if ($locked->status !== 'open') {
                throw ValidationException::withMessages(['cash' => ['La caja ya no está abierta.']]);
            }

            $locked->update([
                'status' => 'pending_reconciliation',
                'declared_amount' => $declaredAmount,
                'submitted_at' => now(),
                'declaration_notes' => $notes,
            ]);

            return $locked->fresh();
        });
    }

    public function close(CashSession $session, User $actor, float $realAmount, ?string $notes = null): CashSession
    {
        return DB::transaction(function () use ($session, $actor, $realAmount, $notes) {
            $locked = CashSession::forStore($actor->store_id)
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'pending_reconciliation') {
                throw ValidationException::withMessages(['cash' => ['La caja no está pendiente de arqueo.']]);
            }
            if ($locked->user_id === $actor->id) {
                throw ValidationException::withMessages(['cash' => ['No podés realizar el arqueo de tu propia caja.']]);
            }

            $expectedCents = (int) round((float) $locked->expected_amount * 100);
            $realCents = (int) round($realAmount * 100);
            if ($realCents !== $expectedCents && blank($notes)) {
                throw ValidationException::withMessages([
                    'reconciliation_notes' => ['La observación es obligatoria cuando existe una diferencia.'],
                ]);
            }

            $locked->update([
                'status' => 'closed',
                'real_amount' => $realAmount,
                'closed_by' => $actor->id,
                'reconciliation_notes' => $notes,
                'closed_at' => now(),
            ]);

            return $locked->fresh(['user', 'closedBy']);
        });
    }

    public function operationalSessions(User $user, bool $lock = false): Collection
    {
        $user->loadMissing('store');
        $businessDate = $this->businessDateService->currentForStore($user->store);
        $query = CashSession::forStore($user->store_id)
            ->operational($user->id, $businessDate)
            ->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    public function currentBusinessDate(User $user): string
    {
        $user->loadMissing('store');

        return $this->businessDateService->currentForStore($user->store);
    }

    public function reconciliationSummary(CashSession $session): array
    {
        $totals = $session->payments()
            ->join('store_payment_methods', 'store_payment_methods.id', '=', 'operation_payments.store_payment_method_id')
            ->join('payment_methods', 'payment_methods.id', '=', 'store_payment_methods.payment_method_id')
            ->selectRaw('store_payment_methods.id as store_payment_method_id')
            ->selectRaw('COALESCE(store_payment_methods.custom_name, payment_methods.name) as name')
            ->selectRaw('payment_methods.code as code')
            ->selectRaw('COUNT(operation_payments.id) as payment_count')
            ->selectRaw('SUM(operation_payments.amount) as total')
            ->groupBy('store_payment_methods.id', 'store_payment_methods.custom_name', 'payment_methods.name', 'payment_methods.code')
            ->orderBy('name')
            ->get()
            ->map(fn ($row) => [
                'store_payment_method_id' => $row->store_payment_method_id,
                'name' => $row->name,
                'code' => $row->code,
                'payment_count' => (int) $row->payment_count,
                'total' => (float) $row->total,
            ])
            ->values();

        return [
            'cash_income' => (float) $totals->where('code', 'cash')->sum('total'),
            'total_collected' => (float) $totals->sum('total'),
            'payment_count' => (int) $totals->sum('payment_count'),
            'operation_count' => $session->payments()->distinct()->count('operation_id'),
            'totals_by_payment_method' => $totals,
        ];
    }

    public function reconciliationPayments(
        CashSession $session,
        StorePaymentMethod $storePaymentMethod,
        int $perPage
    ): LengthAwarePaginator {
        return $session->payments()
            ->where('store_payment_method_id', $storePaymentMethod->id)
            ->with([
                'operation:id,operation_number,type',
                'registeredBy:id,name',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
