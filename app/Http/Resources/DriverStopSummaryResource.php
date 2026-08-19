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
            'sequence' => $this->sequence,
            'status' => $this->status,
            'address' => $address,
            'contact_name' => $contactName,
            'contact_phone' => $contactPhone,
            'notification_window_start' => $notificationWindow['start_rounded'],
            'notification_window_end' => $notificationWindow['end_rounded'],
            'items_count' => $this->when(
                $this->relationLoaded('items'),
                fn () => $this->items->count()
            ),
            'total_planned_items' => $this->when(
                $this->relationLoaded('items'),
                fn () => $this->items->sum('quantity_planned')
            ),
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
