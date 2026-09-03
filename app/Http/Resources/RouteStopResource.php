<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Services\RouteStopService;

class RouteStopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $notificationWindow = (new RouteStopService())->calculateNotificationWindow($this->resource);

        return [
            'id' => $this->id,
            'route_id' => $this->route_id,
            'sequence' => $this->sequence,
            'status' => $this->status,
            'logistics_notes' => $this->logistics_notes,
            'estimated_arrival_at' => $this->estimated_arrival_at?->format('Y-m-d H:i:s'),
            'travel_duration_seconds' => $this->travel_duration_seconds,
            'order' => $this->whenLoaded('order', function () {
                $data = [
                    'id' => $this->order->id,
                    'operation_number' => $this->order->operation_number,
                    'requested_delivery_date' => $this->order->requested_delivery_date?->format('Y-m-d'),
                    'customer' => $this->order->relationLoaded('customer') && $this->order->customer ? [
                        'name' => $this->order->customer->display_name ?? $this->order->customer->name,
                        'document' => $this->order->customer->document_number,
                        'phone' => $this->order->customer->relationLoaded('contacts')
                            ? $this->order->customer->contacts->first()?->phone
                            : null,
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
                                'latitude' => (float) $mainAddress->latitude,
                                'longitude' => (float) $mainAddress->longitude,
                                'locality' => $mainAddress->relationLoaded('locality') && $mainAddress->locality
                                    ? $mainAddress->locality->name
                                    : null,
                            ] : null;
                        }
                    ),
                ];

                // Add financial data when items and payments are loaded
                if ($this->order->relationLoaded('items') && $this->order->relationLoaded('payments')) {
                    $totalAmount = (float) $this->order->total;
                    $paidAmount = (float) $this->order->payments->sum('amount');
                    $data['total_amount'] = $totalAmount;
                    $data['paid_amount'] = $paidAmount;
                    $data['pending_balance'] = $totalAmount - $paidAmount;
                }

                return $data;
            }),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            'items' => RouteStopItemResource::collection($this->whenLoaded('items')),
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
            'gps_lat' => $this->gps_lat,
            'gps_lon' => $this->gps_lon,
            'signature_uri' => $this->signature_uri,
            'evidence_uris' => $this->evidence_uris,
            'notified_at' => $this->notified_at?->format('Y-m-d H:i:s'),
            'notification_window_start' => $notificationWindow['start_rounded'],
            'notification_window_end' => $notificationWindow['end_rounded'],
            'notification_window_start_raw_iso' => $notificationWindow['start_raw'],
            'notification_window_end_raw_iso' => $notificationWindow['end_raw'],
            'notification_window_day' => $notificationWindow['day_label'],
            'notification_window_raw_eta' => $notificationWindow['eta'],
        ];
    }
}
