<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EligibleOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $customer = $this->relationLoaded('customer') ? $this->customer : null;
        $mainAddress = null;

        if ($customer && $customer->relationLoaded('addresses')) {
            $mainAddress = $customer->addresses->firstWhere('is_main', true);
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
            'address' => $mainAddress ? [
                'street' => $mainAddress->street,
                'number' => $mainAddress->number,
                'locality' => $mainAddress->relationLoaded('locality') && $mainAddress->locality
                    ? $mainAddress->locality->name
                    : null,
                'locality_id' => $mainAddress->locality_id,
                'has_coordinates' => ! is_null($mainAddress->latitude) && ! is_null($mainAddress->longitude),
            ] : null,
        ];
    }
}
