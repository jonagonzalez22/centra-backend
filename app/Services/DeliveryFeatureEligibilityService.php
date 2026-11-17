<?php

namespace App\Services;

use App\Models\DeliveryRoute;
use App\Models\Plan;
use App\Models\Store;
use Illuminate\Http\Exceptions\HttpResponseException;

class DeliveryFeatureEligibilityService
{
    /**
     * A store may lose Deliveries only after every route has reached a
     * terminal state. DeliveryRoute::active() is the single source of truth
     * for the non-terminal route states.
     *
     * @param  iterable<string>  $storeIds
     */
    public function assertStoresCanLoseDeliveries(iterable $storeIds): void
    {
        $storeIds = collect($storeIds)->filter()->unique()->values();

        if ($storeIds->isEmpty()) {
            return;
        }

        if (DeliveryRoute::query()
            ->whereIn('store_id', $storeIds)
            ->active()
            ->exists()) {
            throw new HttpResponseException(response()->json([
                'status' => 'error',
                'message' => 'No se puede quitar Entregas porque la tienda tiene rutas pendientes. Finalizá o cancelá las rutas antes de cambiar el plan.',
                'data' => null,
                'errors' => [
                    'deliveries' => ['Existen rutas pendientes que requieren la funcionalidad Entregas.'],
                ],
            ], 422));
        }
    }

    public function planIncludesDeliveries(?Plan $plan): bool
    {
        return $plan !== null
            && $plan->features()->where('code', 'deliveries')->exists();
    }

    /**
     * Serializes delivery route creation/state transitions with plan changes.
     * A route writer that passed middleware before a concurrent downgrade must
     * recheck the effective feature after it owns the store row lock.
     */
    public function lockStoreForDeliveryWrite(string $storeId): Store
    {
        $store = Store::query()
            ->with('plan.features')
            ->whereKey($storeId)
            ->lockForUpdate()
            ->firstOrFail();

        // Do not use Store::hasFeature() here: its request cache may contain
        // the value read by feature middleware before this transaction waited
        // for a concurrent plan downgrade.
        if (! $this->planIncludesDeliveries($store->plan)) {
            throw new HttpResponseException(response()->json([
                'status' => 'error',
                'message' => 'Tu plan actual no incluye esta funcionalidad.',
                'data' => null,
                'errors' => ['feature' => ['El plan no incluye la funcionalidad: deliveries']],
            ], 403));
        }

        return $store;
    }
}
