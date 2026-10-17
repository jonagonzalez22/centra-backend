<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *   schema="ProductSearchResource",
 *   type="object",
 *   title="ProductSearchResource",
 *
 *   @OA\Property(property="id", type="string", format="uuid"),
 *   @OA\Property(property="name", type="string"),
 *   @OA\Property(property="sku", type="string"),
 *   @OA\Property(property="commercial_available_quantity", type="string", example="10.0000"),
 *   @OA\Property(property="stock_source_product_id", type="string", format="uuid", nullable=true),
 *   @OA\Property(property="stock_measurement_unit", ref="#/components/schemas/MeasurementUnitResource", nullable=true)
 * )
 */
class ProductSearchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'commercial_available_quantity' => $this->commercial_available_quantity,
            'stock_source_product_id' => $this->stock_source_product_id,
            'stock_measurement_unit' => MeasurementUnitResource::make($this->whenLoaded('stockMeasurementUnit')),
        ];
    }
}
