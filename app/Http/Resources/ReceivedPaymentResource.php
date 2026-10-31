<?php

namespace App\Http\Resources;

use App\Models\OperationPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OperationPayment */
class ReceivedPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $routeCollection = $this->routeStopCollection;
        $origin = $this->origin();
        $customer = $this->operation?->customer;

        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'reference' => $this->reference,
            'status' => $this->status,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'reversed_at' => $this->reversed_at?->format('Y-m-d H:i:s'),
            'store_payment_method_id' => $this->store_payment_method_id,
            'payment_method' => $this->storePaymentMethod ? [
                'id' => $this->storePaymentMethod->paymentMethod?->id,
                'name' => $this->storePaymentMethod->custom_name ?? $this->storePaymentMethod->paymentMethod?->name,
                'code' => $this->storePaymentMethod->paymentMethod?->code,
            ] : null,
            'operation' => $this->operation ? [
                'id' => $this->operation->id,
                'operation_number' => $this->operation->operation_number,
                'type' => $this->operation->type,
            ] : null,
            'customer' => [
                'id' => $customer?->id,
                'display_name' => $customer?->display_name ?? $this->operation?->customer_display_name,
            ],
            'origin' => $origin,
            'registered_by' => $this->user($this->registeredBy),
            'reversed_by' => $this->user($this->reversedBy),
            'route' => $origin === 'route_collection' && $routeCollection ? [
                'id' => $routeCollection->routeStop?->route?->id,
                'route_stop_id' => $routeCollection->route_stop_id,
                'route_stop_collection_id' => $routeCollection->id,
                'declared_by' => $this->user($routeCollection->declaredBy),
                'declared_at' => $routeCollection->declared_at?->format('Y-m-d H:i:s'),
                'verified_by' => $this->user($routeCollection->verifiedBy),
                'verified_at' => $routeCollection->verified_at?->format('Y-m-d H:i:s'),
                'driver' => $this->user($routeCollection->routeStop?->route?->driver),
            ] : null,
        ];
    }

    private function origin(): string
    {
        if ($this->routeStopCollection !== null) {
            return 'route_collection';
        }

        return in_array($this->payment_details['origin'] ?? null, ['pos_sale', 'order_deposit', 'manual_collection'], true)
            ? $this->payment_details['origin']
            : 'unknown';
    }

    private function user($user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
