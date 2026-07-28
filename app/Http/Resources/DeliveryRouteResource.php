<?php

declare(strict_types=1);

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
            'planned_at' => $this->planned_at?->format('Y-m-d H:i:s'),
            'departure_time' => $this->departure_time,
            'encoded_polyline' => $this->encoded_polyline,
            'unload_time_minutes_snapshot' => $this->unload_time_minutes_snapshot,
            'requires_recalculation' => $this->requires_recalculation,
            'store' => $this->whenLoaded('store', fn () => [
                'id' => $this->store->id,
                'name' => $this->store->name,
                'latitude' => $this->store->latitude,
                'longitude' => $this->store->longitude,
            ]),
            'vehicle' => VehicleResource::make($this->whenLoaded('vehicle')),
            'driver' => DriverResource::make($this->whenLoaded('driver')),
            'stops' => RouteStopResource::collection($this->whenLoaded('stops')),
            'events' => DeliveryRouteEventResource::collection($this->whenLoaded('events')),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
