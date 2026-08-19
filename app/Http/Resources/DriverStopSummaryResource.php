<?php

namespace App\Http\Resources;

use App\Services\RouteStopService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverStopSummaryResource extends JsonResource
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

        // Build customer info from loaded relations
        $customerName = null;
        $customerPhone = null;
        $addressStreet = null;
        $addressLocality = null;
        $addressLatitude = null;
        $addressLongitude = null;

        if ($this->relationLoaded('order') && $this->order?->relationLoaded('customer')) {
            $customer = $this->order->customer;
            $customerName = $customer->display_name ?? $customer->name;
            if ($customer->relationLoaded('contacts')) {
                $customerPhone = $customer->contacts->first()?->phone;
            }
            if ($customer->relationLoaded('addresses')) {
                $mainAddress = $customer->addresses->firstWhere('is_main', true);
                if ($mainAddress) {
                    $addressStreet = trim("{$mainAddress->street} {$mainAddress->number}");
                    $addressLocality = $mainAddress->locality;
                    $addressLatitude = $mainAddress->latitude;
                    $addressLongitude = $mainAddress->longitude;
                }
            }
        }

        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'status' => $this->status,
            'customer' => [
                'name' => $customerName,
                'phone' => $customerPhone,
            ],
            'address' => [
                'street' => $addressStreet,
                'locality' => $addressLocality,
                'latitude' => $addressLatitude,
                'longitude' => $addressLongitude,
                'notes' => $this->logistics_notes, // notas de logística de la parada
            ],
            'notification_window_start' => $notificationWindow['start_rounded'],
            'notification_window_end' => $notificationWindow['end_rounded'],
            'order' => $this->when(
                $this->relationLoaded('order'),
                function () {
                    $order = $this->order;
                    $total = (float) $order->total;
                    $paidAmount = $order->relationLoaded('payments')
                        ? (float) $order->payments->sum('amount')
                        : 0.0;
                    $pendingAmount = max(0, $total - $paidAmount);

                    return [
                        'total' => $total,
                        'paid_amount' => $paidAmount,
                        'pending_amount' => $pendingAmount,
                    ];
                }
            ),
        ];
    }
}
