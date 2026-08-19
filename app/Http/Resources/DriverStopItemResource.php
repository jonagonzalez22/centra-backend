<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverStopItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'route_stop_item_id' => $this->id, // mismo valor, alias para el POST /complete
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name ?? $this->product_name,
            'sku' => $this->product?->sku ?? null,
            'quantity_planned' => $this->quantity_planned,
            'quantity_loaded' => $this->quantity_loaded,
            'quantity_delivered' => $this->quantity_delivered,
            'original_route_stop_id' => $this->original_route_stop_id,
            'is_extra' => false, // lectura-only: no se crean extras desde la app driver
            'notes' => $this->notes,
        ];
    }
}
