<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashSessionPaymentDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'reference' => $this->reference,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'origin' => $this->payment_details['origin']
                ?? ($this->payment_details['route_stop_collection_id'] ?? null ? 'route_collection' : null),
            'payment_details' => $this->payment_details,
            'operation' => $this->whenLoaded('operation', fn () => $this->operation ? [
                'id' => $this->operation->id,
                'operation_number' => $this->operation->operation_number,
                'type' => $this->operation->type,
            ] : null),
            'registered_by' => $this->whenLoaded('registeredBy', fn () => $this->registeredBy ? [
                'id' => $this->registeredBy->id,
                'name' => $this->registeredBy->name,
            ] : null),
        ];
    }
}
