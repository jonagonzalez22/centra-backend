<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierProductCandidateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'is_active' => $this->is_active,
            'is_sellable' => $this->is_sellable,
            'is_associated' => (bool) ($this->is_associated ?? false),
            'supplier_product' => $this->candidate_supplier_product_id ? [
                'id' => $this->candidate_supplier_product_id,
                'supplier_code' => $this->candidate_supplier_code,
                'supplier_product_name' => $this->candidate_supplier_product_name,
                'is_preferred' => (bool) $this->candidate_supplier_is_preferred,
            ] : null,
            'stock_measurement_unit' => MeasurementUnitResource::make($this->whenLoaded('stockMeasurementUnit')),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ] : null),
        ];
    }
}
