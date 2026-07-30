<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteStopItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'route_stop_id' => $this->route_stop_id,
            'product_id' => $this->product_id,
            'product_name' => $this->whenLoaded('product', fn () => $this->product->name),
            'quantity_planned' => $this->quantity_planned,
            'quantity_loaded' => $this->quantity_loaded,
            'quantity_delivered' => $this->quantity_delivered,
        ];
    }
}
