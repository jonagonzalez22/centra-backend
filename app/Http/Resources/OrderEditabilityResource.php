<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderEditabilityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
