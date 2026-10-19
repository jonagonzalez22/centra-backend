<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_code' => $this->supplier_code,
            'supplier_product_name' => $this->supplier_product_name,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'is_active' => $this->product->is_active,
                'is_sellable' => $this->product->is_sellable,
                'stock_source_product_id' => $this->product->stock_source_product_id,
                'stock_measurement_unit' => MeasurementUnitResource::make($this->product->stockMeasurementUnit),
            ]),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
