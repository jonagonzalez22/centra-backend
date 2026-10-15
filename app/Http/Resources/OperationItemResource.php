<?php

namespace App\Http\Resources;

use App\Support\OperationItemCommercialConfiguration;
use App\Support\QuantityMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *   schema="OperationItemResource",
 *   type="object",
 *   title="OperationItemResource",
 *
 *   @OA\Property(property="id", type="string", format="uuid"),
 *   @OA\Property(property="product_id", type="string", format="uuid"),
 *   @OA\Property(property="product_name", type="string"),
 *   @OA\Property(property="quantity", type="string", pattern="^\\d+\\.\\d{4}$", example="3.0000"),
 *   @OA\Property(property="price", type="number", format="float"),
 *   @OA\Property(property="subtotal", type="number", format="float"),
 *   @OA\Property(property="tax_amount", type="number", format="float"),
 *   @OA\Property(property="discount_amount", type="number", format="float"),
 *   @OA\Property(property="stock_measurement_unit", type="object", nullable=true,
 *     @OA\Property(property="code", type="string", example="kg"),
 *     @OA\Property(property="symbol", type="string", example="kg")
 *   ),
 *   @OA\Property(property="sale_quantity_step", type="string", pattern="^\\d+\\.\\d{4}$", nullable=true, example="0.2500")
 * )
 */
class OperationItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $configuration = OperationItemCommercialConfiguration::resolve($this->resource);

        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'quantity' => QuantityMath::normalize($this->quantity),
            'price' => (float) $this->price,
            'subtotal' => (float) $this->subtotal,
            'tax_amount' => (float) $this->tax_amount,
            'discount_amount' => (float) $this->discount_amount,
            'stock_measurement_unit' => $configuration['stock_measurement_unit'],
            'sale_quantity_step' => $configuration['sale_quantity_step'],
        ];
    }
}
