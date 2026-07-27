<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteStopListItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'status' => $this->status,
            'order_id' => $this->order_id,
            'operation_number' => $this->relationLoaded('order') && $this->order
                ? $this->order->operation_number
                : null,
        ];
    }
}
