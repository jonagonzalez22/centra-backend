<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EligibleOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $customer = $this->relationLoaded('customer') ? $this->customer : null;
        $deliveryAddress = null;

        if ($customer && $customer->relationLoaded('addresses')) {
            $deliveryAddress = $customer->addresses->firstWhere('type', 'delivery');
        }

        return [
            'id' => $this->id,
            'operation_number' => $this->operation_number,
            'requested_delivery_date' => $this->requested_delivery_date?->format('Y-m-d'),
            'total' => $this->total,
            'customer' => $customer ? [
                'name' => $customer->display_name ?? $customer->name,
                'document' => $customer->document_number,
            ] : null,
            'address' => $deliveryAddress ? [
                'street' => $deliveryAddress->street,
                'number' => $deliveryAddress->number,
                'locality' => $deliveryAddress->relationLoaded('locality') && $deliveryAddress->locality
                    ? $deliveryAddress->locality->name
                    : null,
                'locality_id' => $deliveryAddress->locality_id,
                'has_coordinates' => ! is_null($deliveryAddress->latitude) && ! is_null($deliveryAddress->longitude),
            ] : null,
        ];
    }
}
