<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RouteStopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'route_id' => $this->route_id,
            'sequence' => $this->sequence,
            'status' => $this->status,
            'logistics_notes' => $this->logistics_notes,
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->id,
                'operation_number' => $this->order->operation_number,
                'requested_delivery_date' => $this->order->requested_delivery_date?->format('Y-m-d'),
                'customer' => $this->order->relationLoaded('customer') && $this->order->customer ? [
                    'name' => $this->order->customer->display_name ?? $this->order->customer->name,
                    'document' => $this->order->customer->document_number,
                ] : null,
                'address' => $this->when(
                    $this->order->relationLoaded('customer') &&
                    $this->order->customer &&
                    $this->order->customer->relationLoaded('addresses'),
                    function () {
                        $mainAddress = $this->order->customer->addresses->firstWhere('is_main', true);
                        return $mainAddress ? [
                            'street' => $mainAddress->street,
                            'number' => $mainAddress->number,
                            'locality' => $mainAddress->relationLoaded('locality') && $mainAddress->locality
                                ? $mainAddress->locality->name
                                : null,
                        ] : null;
                    }
                ),
            ]),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
