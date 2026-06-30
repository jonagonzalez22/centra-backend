<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProvinceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'iso_code' => $this->iso_code,
            'localities' => LocalityResource::collection($this->whenLoaded('localities')),
            'localities_count' => $this->when(
                $this->relationLoaded('localities'),
                fn () => $this->localities->count()
            ),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
