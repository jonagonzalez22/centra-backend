<?php

namespace App\Http\Resources;

use App\Models\StorePaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StorePaymentMethod */
class CommercialPaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->custom_name ?? $this->paymentMethod?->name,
            'code' => $this->paymentMethod?->code,
            'requires_reference' => $this->requires_reference,
        ];
    }
}
