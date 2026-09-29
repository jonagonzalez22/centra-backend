<?php

namespace App\Http\Resources;

use App\Support\QuantityMath;
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
            'available_stock' => QuantityMath::normalize($this->available_stock),
            'commercial_available_quantity' => QuantityMath::normalize($this->commercial_available_quantity),
            'stock_measurement_unit_id' => $this->stock_measurement_unit_id,
            'stock_measurement_unit' => MeasurementUnitResource::make($this->whenLoaded('stockMeasurementUnit')),
            'sale_quantity_step' => QuantityMath::normalize($this->sale_quantity_step),
        ];
    }
}
