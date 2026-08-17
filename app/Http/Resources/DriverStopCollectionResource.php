<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverStopCollectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'amount' => (float) $this->amount,
            'method' => $this->storePaymentMethod?->paymentMethod?->name ?? null,
            'declared_at' => $this->declared_at?->toIso8601String(),
        ];
    }
}
