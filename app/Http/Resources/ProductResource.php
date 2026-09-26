<?php

namespace App\Http\Resources;

use App\Support\QuantityMath;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *   schema="ProductResource",
 *   type="object",
 *   title="ProductResource",
 *
 *   @OA\Property(property="id", type="string", format="uuid"),
 *   @OA\Property(property="name", type="string"),
 *   @OA\Property(property="sku", type="string"),
 *   @OA\Property(property="barcode", type="string", nullable=true),
 *   @OA\Property(property="description", type="string", nullable=true),
 *   @OA\Property(property="price", type="number", format="float"),
 *   @OA\Property(property="cost", type="number", format="float", nullable=true),
 *   @OA\Property(property="stock", type="string", pattern="^\\d+\\.\\d{4}$", example="10.0000"),
 *   @OA\Property(property="stock_reserved", type="string", pattern="^\\d+\\.\\d{4}$", example="2.0000"),
 *   @OA\Property(property="available_stock", type="string", pattern="^\\d+\\.\\d{4}$", example="8.0000"),
 *   @OA\Property(property="stock_min", type="string", pattern="^\\d+\\.\\d{4}$", example="1.0000"),
 *   @OA\Property(property="is_active", type="boolean"),
 *   @OA\Property(property="parent_product_id", type="string", format="uuid", nullable=true),
 *   @OA\Property(property="category", ref="#/components/schemas/ProductCategoryResource", nullable=true),
 *   @OA\Property(property="created_at", type="string", format="date-time"),
 *   @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'description' => $this->description,
            'price' => (float) $this->price,
            'cost' => $this->cost ? (float) $this->cost : null,
            'stock' => QuantityMath::normalize($this->stock),
            'stock_reserved' => QuantityMath::normalize($this->stock_reserved),
            'available_stock' => QuantityMath::normalize($this->available_stock),
            'stock_min' => QuantityMath::normalize($this->stock_min),
            'is_active' => $this->is_active,
            'parent_product_id' => $this->parent_product_id,
            'category' => ProductCategoryResource::make($this->whenLoaded('category')),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
