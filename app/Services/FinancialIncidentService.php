<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\FinancialIncidentFollowup;
use App\Models\FinancialIncidentFollowupHistory;
use App\Models\RouteStopCollection;
use App\Models\User;
use App\Support\MoneyMath;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read models are derived live from their accounting/operational sources.
 * Follow-ups only contain administrative tracking, never a financial snapshot.
 */
class FinancialIncidentService
{
    /**
     * This is deliberately the same predicate used by assign(): an active user
     * belonging to the authenticated user's store. Keep it independent from
     * multi_user because the incident feature itself authorizes this workflow.
     */
    public function assignees(User $actor)
    {
        return User::query()
            ->where('store_id', $actor->store_id)
            ->active()
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);
    }

    public function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $storeId = $actor->store_id;
        $sources = $this->sourceUnion($storeId);
        $query = DB::query()->fromSub($sources, 'incidents')
            ->leftJoin('financial_incident_followups as followups', function ($join) use ($storeId): void {
                $join->on('followups.source_type', '=', 'incidents.source_type')
                    ->on('followups.source_id', '=', 'incidents.source_id')
                    ->where('followups.store_id', '=', $storeId);
            })
            ->select('incidents.*', 'followups.id as followup_id', 'followups.status as followup_status', 'followups.assigned_to', 'followups.assigned_at', 'followups.resolution_type', 'followups.resolved_at')
            ->selectRaw("COALESCE(followups.status, 'detected') as status");

        if ($type = $filters['source_type'] ?? null) {
            $query->where('incidents.source_type', $type);
        }
        if ($status = $filters['status'] ?? null) {
            $query->whereRaw("COALESCE(followups.status, 'detected') = ?", [$status]);
        }
        if ($assigned = $filters['assigned_to'] ?? null) {
            $query->where('followups.assigned_to', $assigned);
        }
        [$from, $to] = $this->localDateBounds($actor, $filters['date_from'] ?? null, $filters['date_to'] ?? null);
        if ($from) {
            $query->where('incidents.occurred_at', '>=', $from);
        }
        if ($to) {
            $query->where('incidents.occurred_at', '<', $to);
        }

        return $query->orderByDesc('incidents.occurred_at')->orderBy('incidents.source_type')->orderBy('incidents.source_id')
            ->paginate($filters['per_page'] ?? 20);
    }

    public function counters(User $actor, array $filters): array
    {
        $filters['per_page'] = 1;
        $sources = $this->sourceUnion($actor->store_id);
        $query = DB::query()->fromSub($sources, 'incidents')
            ->leftJoin('financial_incident_followups as followups', function ($join) use ($actor): void {
                $join->on('followups.source_type', '=', 'incidents.source_type')->on('followups.source_id', '=', 'incidents.source_id')->where('followups.store_id', '=', $actor->store_id);
            });
        if ($type = $filters['source_type'] ?? null) {
            $query->where('incidents.source_type', $type);
        }
        [$from, $to] = $this->localDateBounds($actor, $filters['date_from'] ?? null, $filters['date_to'] ?? null);
        if ($from) {
            $query->where('incidents.occurred_at', '>=', $from);
        }
        if ($to) {
            $query->where('incidents.occurred_at', '<', $to);
        }

        return $query->selectRaw("COALESCE(followups.status, 'detected') as status, COUNT(*) as total")->groupBy('status')->pluck('total', 'status')->all();
    }

    public function incident(User $actor, string $sourceType, string $sourceId): array
    {
        $source = $this->source($actor->store_id, $sourceType, $sourceId);
        $followup = FinancialIncidentFollowup::query()->where('store_id', $actor->store_id)->where('source_type', $sourceType)->where('source_id', $sourceId)->with(['assignedTo', 'resolvedBy'])->first();

        return $this->present($source, $followup);
    }

    public function startReview(User $actor, string $type, string $id, ?string $comment): FinancialIncidentFollowup
    {
        return $this->mutate($actor, $type, $id, function (FinancialIncidentFollowup $followup) use ($comment, $actor): array {
            $this->requireStatus($followup, ['detected']);
            $previous = $followup->status;
            $followup->update(['status' => 'in_review']);

            return ['action' => 'review_started', 'previous_status' => $previous, 'new_status' => 'in_review', 'comment' => $comment, 'actor_id' => $actor->id];
        });
    }

    public function assign(User $actor, string $type, string $id, string $assigneeId, ?string $comment): FinancialIncidentFollowup
    {
        $assignee = $this->eligibleAssignee($actor, $assigneeId);
        if (! $assignee) {
            throw ValidationException::withMessages(['assigned_to' => ['El responsable debe ser un usuario activo de la misma tienda.']]);
        }

        return $this->mutate($actor, $type, $id, function (FinancialIncidentFollowup $followup) use ($actor, $assignee, $comment): array {
            $this->requireStatus($followup, ['in_review']);
            $previous = $followup->assigned_to;
            $followup->update(['assigned_to' => $assignee->id, 'assigned_at' => now()]);

            return ['action' => 'assigned', 'previous_status' => $followup->status, 'new_status' => $followup->status, 'previous_assigned_to' => $previous, 'new_assigned_to' => $assignee->id, 'comment' => $comment, 'actor_id' => $actor->id];
        });
    }

    public function comment(User $actor, string $type, string $id, string $comment): FinancialIncidentFollowup
    {
        return $this->mutate($actor, $type, $id, function (FinancialIncidentFollowup $followup) use ($comment, $actor): array {
            $this->requireStatus($followup, ['in_review']);

            return ['action' => 'commented', 'previous_status' => $followup->status, 'new_status' => $followup->status, 'comment' => $comment, 'actor_id' => $actor->id];
        });
    }

    public function resolve(User $actor, string $type, string $id, string $resolutionType, ?string $note): FinancialIncidentFollowup
    {
        return $this->mutate($actor, $type, $id, function (FinancialIncidentFollowup $followup) use ($actor, $resolutionType, $note): array {
            $this->requireStatus($followup, ['in_review']);
            if (blank($note)) {
                throw ValidationException::withMessages(['resolution_note' => ['La observación de resolución es obligatoria.']]);
            }
            $followup->update(['status' => 'resolved', 'resolution_type' => $resolutionType, 'resolution_note' => $note, 'resolved_by' => $actor->id, 'resolved_at' => now()]);

            return ['action' => 'resolved', 'previous_status' => 'in_review', 'new_status' => 'resolved', 'comment' => $note, 'actor_id' => $actor->id, 'metadata' => ['resolution_type' => $resolutionType]];
        });
    }

    public function dismiss(User $actor, string $type, string $id, string $reason): FinancialIncidentFollowup
    {
        return $this->mutate($actor, $type, $id, function (FinancialIncidentFollowup $followup) use ($actor, $reason): array {
            $this->requireStatus($followup, ['in_review']);
            $previous = $followup->status;
            $followup->update(['status' => 'dismissed', 'resolution_note' => $reason, 'resolved_by' => $actor->id, 'resolved_at' => now()]);

            return ['action' => 'dismissed', 'previous_status' => $previous, 'new_status' => 'dismissed', 'comment' => $reason, 'actor_id' => $actor->id];
        });
    }

    public function history(User $actor, string $type, string $id)
    {
        $this->source($actor->store_id, $type, $id);

        return FinancialIncidentFollowup::query()->where('store_id', $actor->store_id)->where('source_type', $type)->where('source_id', $id)->first()?->histories()->with(['actor', 'previousAssignedTo', 'newAssignedTo'])->orderBy('created_at')->orderBy('id')->get() ?? collect();
    }

    private function mutate(User $actor, string $type, string $id, callable $mutation): FinancialIncidentFollowup
    {
        return DB::transaction(function () use ($actor, $type, $id, $mutation) {
            $this->source($actor->store_id, $type, $id);
            $followup = $this->lockedFollowup($actor->store_id, $type, $id);
            $event = $mutation($followup);
            FinancialIncidentFollowupHistory::create(['financial_incident_followup_id' => $followup->id, ...$event]);

            return $followup->fresh(['assignedTo', 'resolvedBy']);
        });
    }

    private function lockedFollowup(string $storeId, string $type, string $id): FinancialIncidentFollowup
    {
        $followup = FinancialIncidentFollowup::query()->where('store_id', $storeId)->where('source_type', $type)->where('source_id', $id)->lockForUpdate()->first();
        if ($followup) {
            return $followup;
        }
        try {
            FinancialIncidentFollowup::create(['store_id' => $storeId, 'source_type' => $type, 'source_id' => $id, 'status' => 'detected']);
        } catch (QueryException) {
            // The unique source key is the concurrency boundary; lock the winner.
        }

        return FinancialIncidentFollowup::query()->where('store_id', $storeId)->where('source_type', $type)->where('source_id', $id)->lockForUpdate()->firstOrFail();
    }

    private function eligibleAssignee(User $actor, string $assigneeId): ?User
    {
        return User::query()
            ->whereKey($assigneeId)
            ->where('store_id', $actor->store_id)
            ->active()
            ->first();
    }

    private function source(string $storeId, string $type, string $id): CashSession|RouteStopCollection
    {
        return match ($type) {
            FinancialIncidentFollowup::SOURCE_CASH_DIFFERENCE => CashSession::forStore($storeId)->whereKey($id)->where('status', 'closed')->whereNotNull('real_amount')->whereNotNull('expected_amount')->whereColumn('real_amount', '<', 'expected_amount')->with(['user', 'closedBy'])->firstOrFail(),
            FinancialIncidentFollowup::SOURCE_ROUTE_COLLECTION_REJECTED => RouteStopCollection::forStore($storeId)->whereKey($id)->where('status', 'rejected')->with(['declaredBy', 'verifiedBy', 'routeStop.route', 'commercialOperation', 'storePaymentMethod.paymentMethod'])->firstOrFail(),
            default => throw ValidationException::withMessages(['source_type' => ['Tipo de incidencia inválido.']]),
        };
    }

    private function sourceUnion(string $storeId)
    {
        $cash = DB::table('cash_sessions')->where('store_id', $storeId)->where('status', 'closed')->whereNotNull('real_amount')->whereNotNull('expected_amount')->whereColumn('real_amount', '<', 'expected_amount')
            ->selectRaw("'cash_difference' as source_type, id as source_id, (expected_amount - real_amount) as amount, (real_amount - expected_amount) as source_difference, closed_at as occurred_at");
        $route = DB::table('route_stop_collections')->where('store_id', $storeId)->where('status', 'rejected')
            ->selectRaw("'route_collection_rejected' as source_type, id as source_id, amount, NULL as source_difference, COALESCE(verified_at, updated_at, declared_at) as occurred_at");

        return $cash->unionAll($route);
    }

    private function present(CashSession|RouteStopCollection $source, ?FinancialIncidentFollowup $followup): array
    {
        if ($source instanceof CashSession) {
            $difference = MoneyMath::subtract((string) $source->real_amount, (string) $source->expected_amount);

            return ['source_type' => FinancialIncidentFollowup::SOURCE_CASH_DIFFERENCE, 'source_id' => $source->id, 'status' => $followup?->status ?? 'detected', 'amount' => MoneyMath::subtract((string) $source->expected_amount, (string) $source->real_amount), 'source_difference' => $difference, 'occurred_at' => $this->timestamp($source->closed_at), 'assigned_to' => $this->userData($followup?->assignedTo), 'reference' => ['cash_session_id' => $source->id], 'source_summary' => ['business_date' => $source->business_date?->format('Y-m-d'), 'expected_amount' => MoneyMath::normalize((string) $source->expected_amount), 'real_amount' => MoneyMath::normalize((string) $source->real_amount), 'cashier' => $this->userData($source->user), 'closed_by' => $this->userData($source->closedBy), 'notes' => $source->reconciliation_notes], 'tracking' => $this->tracking($followup)];
        }

        return ['source_type' => FinancialIncidentFollowup::SOURCE_ROUTE_COLLECTION_REJECTED, 'source_id' => $source->id, 'status' => $followup?->status ?? 'detected', 'amount' => MoneyMath::normalize((string) $source->amount), 'source_difference' => null, 'occurred_at' => $this->timestamp($source->verified_at ?? $source->updated_at ?? $source->declared_at), 'assigned_to' => $this->userData($followup?->assignedTo), 'reference' => ['route_id' => $source->routeStop?->route_id, 'route_stop_id' => $source->route_stop_id, 'commercial_operation_id' => $source->commercial_operation_id], 'source_summary' => ['operation_number' => $source->commercialOperation?->operation_number, 'payment_method' => $source->storePaymentMethod?->custom_name ?? $source->storePaymentMethod?->paymentMethod?->name, 'declared_by' => $this->userData($source->declaredBy), 'verified_by' => $this->userData($source->verifiedBy), 'rejection_reason' => $source->rejection_reason], 'tracking' => $this->tracking($followup)];
    }

    private function tracking(?FinancialIncidentFollowup $f): ?array
    {
        return $f ? ['id' => $f->id, 'assigned_at' => $this->timestamp($f->assigned_at), 'resolution_type' => $f->resolution_type, 'resolution_note' => $f->resolution_note, 'resolved_at' => $this->timestamp($f->resolved_at), 'resolved_by' => $this->userData($f->resolvedBy)] : null;
    }

    private function userData(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    private function timestamp($value): ?string
    {
        return $value?->utc()->toIso8601String();
    }

    private function requireStatus(FinancialIncidentFollowup $f, array $allowed): void
    {
        if (! in_array($f->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => ['La incidencia está en un estado terminal o no admite esta transición.']]);
        }
    }

    private function localDateBounds(User $actor, ?string $from, ?string $to): array
    {
        $tz = $actor->store?->timezone ?: config('app.timezone', 'UTC');

        return [$from ? CarbonImmutable::parse($from, $tz)->startOfDay()->utc() : null, $to ? CarbonImmutable::parse($to, $tz)->addDay()->startOfDay()->utc() : null];
    }
}
