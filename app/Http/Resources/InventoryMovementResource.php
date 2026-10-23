<?php

namespace App\Http\Resources;

use App\Support\QuantityMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'quantity' => QuantityMath::normalize($this->quantity),
            'previous_stock' => QuantityMath::normalize($this->previous_stock),
            'current_stock' => QuantityMath::normalize($this->current_stock),
            'concept' => $this->concept,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'product' => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'stock_measurement_unit' => $this->product->relationLoaded('stockMeasurementUnit')
                    ? MeasurementUnitResource::make($this->product->stockMeasurementUnit)
                    : null,
            ],
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ],
        ];
    }
}
