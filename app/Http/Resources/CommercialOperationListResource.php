<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *   schema="CommercialOperationListResource",
 *   type="object",
 *   title="CommercialOperationListResource",
 *
 *   @OA\Property(property="id", type="string", format="uuid"),
 *   @OA\Property(property="operation_number", type="string"),
 *   @OA\Property(property="type", type="string"),
 *   @OA\Property(property="status", type="string"),
 *   @OA\Property(property="requested_delivery_date", type="string", format="date", nullable=true),
 *   @OA\Property(property="delivery_time_from", type="string", nullable=true, example=null),
 *   @OA\Property(property="delivery_time_to", type="string", nullable=true, example=null),
 *   @OA\Property(property="total", type="number", format="float"),
 *   @OA\Property(property="paid_amount", type="number", format="float"),
 *   @OA\Property(property="pending_amount", type="number", format="float"),
 *   @OA\Property(property="items_count", type="integer"),
 *   @OA\Property(property="customer", type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="phone", type="string", nullable=true)
 *   ),
 *   @OA\Property(property="delivery_address", type="object", nullable=true,
 *     @OA\Property(property="locality", type="string", nullable=true),
 *     @OA\Property(property="street", type="string", nullable=true),
 *     @OA\Property(property="full_address", type="string", nullable=true)
 *   ),
 *   @OA\Property(property="branch_id", type="string", format="uuid", nullable=true)
 * )
 */
class CommercialOperationListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'operation_number' => $this->operation_number,
            'type' => $this->type,
            'status' => $this->status,
            'requested_delivery_date' => $this->requested_delivery_date?->format('Y-m-d'),
            'delivery_time_from' => null,
            'delivery_time_to' => null,
            'total' => (float) $this->total,
            'paid_amount' => (float) ($this->payments_sum_amount ?? 0),
            'pending_amount' => (float) ($this->total - ($this->payments_sum_amount ?? 0)),
            'items_count' => $this->whenLoaded('items', fn () => $this->items->count(), 0),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->display_name,
                'phone' => null,
            ]),
            'delivery_address' => $this->getDeliveryAddress(),
            'branch_id' => $this->branch_id,
        ];
    }

    private function getDeliveryAddress(): ?array
    {
        $address = $this->delivery_address;

        if (! $address) {
            return null;
        }

        $street = $address->street ?? '';
        $number = $address->number ?? '';
        $fullAddress = trim($street.' '.$number);

        return [
            'locality' => $address->locality?->name,
            'street' => $address->street,
            'full_address' => $fullAddress ?: null,
        ];
    }
}
