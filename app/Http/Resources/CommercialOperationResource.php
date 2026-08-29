<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *   schema="CommercialOperationResource",
 *   type="object",
 *   title="CommercialOperationResource",
 *
 *   @OA\Property(property="id", type="string", format="uuid"),
 *   @OA\Property(property="operation_number", type="string"),
 *   @OA\Property(property="type", type="string"),
 *   @OA\Property(property="status", type="string"),
 *   @OA\Property(property="requested_delivery_date", type="string", format="date", nullable=true),
 *   @OA\Property(property="delivery_time_from", type="string", nullable=true, example=null),
 *   @OA\Property(property="delivery_time_to", type="string", nullable=true, example=null),
 *   @OA\Property(property="subtotal", type="number", format="float"),
 *   @OA\Property(property="tax", type="number", format="float"),
 *   @OA\Property(property="discount", type="number", format="float"),
 *   @OA\Property(property="total", type="number", format="float"),
 *   @OA\Property(property="paid_amount", type="number", format="float"),
 *   @OA\Property(property="pending_amount", type="number", format="float"),
 *   @OA\Property(property="completed_at", type="string", format="date-time", nullable=true),
 *   @OA\Property(property="created_at", type="string", format="date-time"),
 *   @OA\Property(property="updated_at", type="string", format="date-time"),
 *   @OA\Property(property="branch_id", type="string", format="uuid", nullable=true),
 *   @OA\Property(property="created_by", type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string")
 *   ),
 *   @OA\Property(property="customer", type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="phone", type="string", nullable=true),
 *     @OA\Property(property="email", type="string", nullable=true)
 *   ),
 *   @OA\Property(property="delivery_address", type="object", nullable=true,
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="street", type="string", nullable=true),
 *     @OA\Property(property="number", type="string", nullable=true),
 *     @OA\Property(property="locality", type="string", nullable=true),
 *     @OA\Property(property="province", type="string", nullable=true),
 *     @OA\Property(property="notes", type="string", nullable=true),
 *     @OA\Property(property="full_address", type="string", nullable=true)
 *   ),
 *   @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/OperationItemResource")),
 *   @OA\Property(property="payments", type="array", @OA\Items(ref="#/components/schemas/OperationPaymentResource")),
 *   @OA\Property(property="events", type="array", @OA\Items(ref="#/components/schemas/CommercialOperationEventResource")),
 *   @OA\Property(property="history", type="array", description="Historial funcional normalizado del pedido"),
 *   @OA\Property(property="route_ids", type="array", @OA\Items(type="string", format="uuid"), description="IDs de rutas activas (no canceladas) donde está asignado este pedido")
 * )
 */
class CommercialOperationResource extends JsonResource
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
            'subtotal' => (float) $this->subtotal,
            'tax' => (float) $this->tax,
            'discount' => (float) $this->discount,
            'total' => (float) $this->total,
            'paid_amount' => (float) ($this->payments_sum_amount ?? 0),
            'pending_amount' => (float) ($this->total - ($this->payments_sum_amount ?? 0)),
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            'branch_id' => $this->branch_id,
            'created_by' => $this->whenLoaded('user', fn () => [
                'id' => $this->user_id,
                'name' => $this->user?->name,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->display_name,
                'phone' => null,
                'email' => null,
            ]),
            'delivery_address' => $this->getDeliveryAddress(),
            'items' => OperationItemResource::collection($this->whenLoaded('items')),
            'payments' => OperationPaymentResource::collection($this->whenLoaded('payments')),
            'events' => CommercialOperationEventResource::collection($this->whenLoaded('events')),
            'history' => $this->history ?? [],
            'route_ids' => $this->whenLoaded('routeStops', fn () => $this->routeStops
                ->where('status', '!=', 'cancelled')
                ->pluck('route_id')
                ->unique()
                ->values()
                ->toArray(), []),
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
            'id' => $address->id,
            'street' => $address->street,
            'number' => $address->number,
            'locality' => $address->locality?->name,
            'province' => $address->locality?->province?->name,
            'notes' => $address->observations,
            'full_address' => $fullAddress ?: null,
        ];
    }
}
