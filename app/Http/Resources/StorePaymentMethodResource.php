<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StorePaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'icon' => $this->icon,
            'is_active' => $this->is_active,
            'is_enabled' => (bool) ($this->is_enabled ?? false),
            'custom_name' => $this->custom_name,
            'requires_reference' => (bool) ($this->requires_reference ?? false),
            'account_details' => $this->account_details,
            'sort_order' => (int) ($this->sort_order ?? 0),
        ];
    }
}
