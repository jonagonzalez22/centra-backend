<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *   schema="CommercialOperationResource",
 *   type="object",
 *   title="CommercialOperationResource",
 *   @OA\Property(property="id", type="string", format="uuid"),
 *   @OA\Property(property="operation_number", type="string"),
 *   @OA\Property(property="type", type="string"),
 *   @OA\Property(property="status", type="string"),
 *   @OA\Property(property="customer", type="object", nullable=true),
 *   @OA\Property(property="user", type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string")
 *   ),
 *   @OA\Property(property="branch_id", type="string", format="uuid", nullable=true),
 *   @OA\Property(property="subtotal", type="number", format="float"),
 *   @OA\Property(property="tax", type="number", format="float"),
 *   @OA\Property(property="discount", type="number", format="float"),
 *   @OA\Property(property="total", type="number", format="float"),
 *   @OA\Property(property="delivery_date", type="string", format="date", nullable=true),
 *   @OA\Property(property="completed_at", type="string", format="date-time", nullable=true),
 *   @OA\Property(property="items", type="array", @OA\Items(ref="#/components/schemas/OperationItemResource")),
 *   @OA\Property(property="payments", type="array", @OA\Items(ref="#/components/schemas/OperationPaymentResource")),
 *   @OA\Property(property="created_at", type="string", format="date-time"),
 *   @OA\Property(property="updated_at", type="string", format="date-time")
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
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'user' => [
                'id' => $this->user_id,
                'name' => $this->user?->name,
            ],
            'branch_id' => $this->branch_id,
            'subtotal' => (float) $this->subtotal,
            'tax' => (float) $this->tax,
            'discount' => (float) $this->discount,
            'total' => (float) $this->total,
            'delivery_date' => $this->delivery_date?->format('Y-m-d'),
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
            'items' => OperationItemResource::collection($this->whenLoaded('items')),
            'payments' => OperationPaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
