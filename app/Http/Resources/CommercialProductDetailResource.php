<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommercialProductDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'price' => (float) $this->price,
            'available_stock' => (int) $this->available_stock,
        ];
    }
}
