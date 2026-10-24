<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'purchase_number' => $this->purchase_number, 'purchase_type' => $this->purchase_type, 'status' => $this->status, 'supplier' => ['id' => $this->supplier_id, 'business_name' => $this->supplier_business_name, 'tax_id' => $this->supplier_tax_id], 'total' => $this->total, 'documents_count' => $this->documents_count, 'ordered_at' => $this->ordered_at?->toDateTimeString(), 'cancelled_at' => $this->cancelled_at?->toDateTimeString(), 'created_at' => $this->created_at?->toDateTimeString()];
    }
}
