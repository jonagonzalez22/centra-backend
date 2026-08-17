<?php

namespace App\Http\Resources;

use App\Services\RouteStopService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverStopResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $notificationWindow = (new RouteStopService())->calculateNotificationWindow($this->resource);
        $timezone = $this->resource->route?->store?->timezone ?? config('app.timezone', 'UTC');

        // Build contact info from loaded relations
        $contactName = null;
        $contactPhone = null;
        $address = null;

        if ($this->relationLoaded('order') && $this->order?->relationLoaded('customer')) {
            $customer = $this->order->customer;
            $contactName = $customer->display_name ?? $customer->name;
            if ($customer->relationLoaded('contacts')) {
                $contactPhone = $customer->contacts->first()?->phone;
            }
            if ($customer->relationLoaded('addresses')) {
                $mainAddress = $customer->addresses->firstWhere('is_main', true);
                if ($mainAddress) {
                    $address = trim("{$mainAddress->street} {$mainAddress->number}");
                }
            }
        }

        return [
            'id' => $this->id,
            'route_id' => $this->route_id,
            'sequence' => $this->sequence,
            'status' => $this->status,
            'address' => $address,
            'contact_name' => $contactName,
            'contact_phone' => $contactPhone,
            'timezone' => $timezone,
            'eta' => $this->estimated_arrival_at?->setTimezone($timezone)?->toIso8601String(),
            'notification_window_start' => $notificationWindow['start_rounded'],
            'notification_window_end' => $notificationWindow['end_rounded'],
            'notes' => $this->logistics_notes,
            'items' => DriverStopItemResource::collection($this->whenLoaded('items')),
            'collections' => DriverStopCollectionResource::collection($this->whenLoaded('collections')),
        ];
    }
}
