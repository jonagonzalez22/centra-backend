<?php

namespace App\Services;

use App\Models\OperationPayment;
use App\Models\RouteStopCollection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ReceivedPaymentQueryService
{
    private const EXPLICIT_ORIGINS = ['pos_sale', 'order_deposit', 'manual_collection'];

    public function paginate(string $storeId, array $filters): LengthAwarePaginator
    {
        $payments = OperationPayment::query()
            ->whereHas('operation', fn (Builder $query) => $query->forStore($storeId))
            ->with([
                'operation.customer',
                'storePaymentMethod.paymentMethod',
                'registeredBy',
                'reversedBy',
                'routeStopCollection.routeStop.route.driver',
                'routeStopCollection.declaredBy',
                'routeStopCollection.verifiedBy',
            ])
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('operation_payments.created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('operation_payments.created_at', '<=', $date))
            ->when($filters['operation_number'] ?? null, fn (Builder $query, string $operationNumber) => $query->whereHas('operation', fn (Builder $operation) => $operation->where('operation_number', 'like', '%'.trim($operationNumber).'%')))
            ->when($filters['customer'] ?? null, function (Builder $query, string $customer): void {
                $query->whereHas('operation', function (Builder $operation) use ($customer): void {
                    $operation->where('customer_display_name', 'like', '%'.trim($customer).'%')
                        ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('display_name', 'like', '%'.trim($customer).'%'));
                });
            })
            ->when($filters['store_payment_method_id'] ?? null, fn (Builder $query, string $id) => $query->where('store_payment_method_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status));

        if ($collectorId = $filters['collected_by'] ?? null) {
            $payments->where(function (Builder $query) use ($collectorId): void {
                // Route payments are collected by the declarant, never by the payment registrar.
                $query->where(function (Builder $normalPayments) use ($collectorId): void {
                    $normalPayments->where('operation_payments.registered_by', $collectorId)
                        ->whereDoesntHave('routeStopCollection')
                        ->whereNull('payment_details->route_stop_collection_id');
                })
                    ->orWhereHas('routeStopCollection', fn (Builder $route) => $route->where('declared_by', $collectorId))
                    ->orWhereIn('id', function ($subquery) use ($collectorId): void {
                        $subquery->select('operation_payment_id')
                            ->from('route_stop_collections')
                            ->where('declared_by', $collectorId)
                            ->whereNotNull('operation_payment_id');
                    });
            });
        }

        if (isset($filters['origin'])) {
            $this->applyOriginFilter($payments, $filters['origin']);
        }

        $paginator = $payments
            ->orderByDesc('operation_payments.created_at')
            ->orderByDesc('operation_payments.id')
            ->paginate($filters['per_page'] ?? 15);

        $this->hydrateLegacyRouteCollections($paginator);

        return $paginator;
    }

    private function applyOriginFilter(Builder $query, string $origin): void
    {
        if ($origin === 'route_collection') {
            $query->where(function (Builder $query): void {
                $query->whereHas('routeStopCollection')
                    ->orWhereNotNull('payment_details->route_stop_collection_id');
            });

            return;
        }

        if ($origin === 'unknown') {
            $query->whereDoesntHave('routeStopCollection')
                ->whereNull('payment_details->route_stop_collection_id')
                ->where(function (Builder $query): void {
                    $query->whereNull('payment_details->origin')
                        ->orWhereNotIn('payment_details->origin', self::EXPLICIT_ORIGINS);
                });

            return;
        }

        $query->where('payment_details->origin', $origin);
    }

    private function hydrateLegacyRouteCollections(LengthAwarePaginator $paginator): void
    {
        $legacyIds = collect($paginator->items())
            ->filter(fn (OperationPayment $payment) => $payment->routeStopCollection === null)
            ->map(fn (OperationPayment $payment) => $payment->payment_details['route_stop_collection_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($legacyIds->isEmpty()) {
            return;
        }

        $collections = RouteStopCollection::query()
            ->whereIn('id', $legacyIds)
            ->with(['routeStop.route.driver', 'declaredBy', 'verifiedBy'])
            ->get()
            ->keyBy('id');

        foreach ($paginator->items() as $payment) {
            if ($payment->routeStopCollection !== null) {
                continue;
            }

            $legacyId = $payment->payment_details['route_stop_collection_id'] ?? null;
            if ($legacyId && $collections->has($legacyId)) {
                $payment->setRelation('routeStopCollection', $collections->get($legacyId));
            }
        }
    }
}
