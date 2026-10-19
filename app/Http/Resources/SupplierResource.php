<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'business_name' => $this->business_name, 'tax_id' => $this->tax_id, 'contact_name' => $this->contact_name, 'phone' => $this->phone, 'email' => $this->email, 'address' => $this->address, 'notes' => $this->notes, 'is_active' => $this->is_active, 'created_at' => $this->created_at?->format('Y-m-d H:i:s'), 'updated_at' => $this->updated_at?->format('Y-m-d H:i:s')];
    }
}
