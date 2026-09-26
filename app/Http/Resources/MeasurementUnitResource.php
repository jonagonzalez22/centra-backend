<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *   schema="MeasurementUnitResource",
 *   type="object",
 *
 *   @OA\Property(property="id", type="string", format="uuid"),
 *   @OA\Property(property="code", type="string", example="kg"),
 *   @OA\Property(property="name", type="string", example="Kilogramo"),
 *   @OA\Property(property="symbol", type="string", example="kg"),
 *   @OA\Property(property="category", type="string", example="weight")
 * )
 */
class MeasurementUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'category' => $this->category,
        ];
    }
}
