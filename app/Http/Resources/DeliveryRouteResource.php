<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryRouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'operational_date' => $this->operational_date?->format('Y-m-d'),
            'status' => $this->status,
            'observations' => $this->observations,
            'created_by' => $this->created_by,
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'driver' => DriverResource::make($this->whenLoaded('driver')),
            'stops' => RouteStopResource::collection($this->whenLoaded('stops')),
            'events' => DeliveryRouteEventResource::collection($this->whenLoaded('events')),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
