<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'limit_value' => $this->when($this->relationLoaded('pivot'), fn () => $this->pivot?->limit_value),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
