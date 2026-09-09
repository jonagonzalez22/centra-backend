<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *   schema="OperationPaymentResource",
 *   type="object",
 *   title="OperationPaymentResource",
 *
 *   @OA\Property(property="id", type="string", format="uuid"),
 *   @OA\Property(property="amount", type="number", format="float"),
 *   @OA\Property(property="reference", type="string", nullable=true),
 *   @OA\Property(property="payment_details", type="object", nullable=true),
 *   @OA\Property(property="created_at", type="string", format="date-time"),
 *   @OA\Property(property="origin", type="string", nullable=true),
 *   @OA\Property(property="registered_by", type="object", nullable=true,
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string")
 *   ),
 *   @OA\Property(property="cash_session", type="object", nullable=true,
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="status", type="string")
 *   ),
 *   @OA\Property(property="store_payment_method", type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="name", type="string"),
 *     @OA\Property(property="code", type="string")
 *   )
 * )
 */
class OperationPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'reference' => $this->reference,
            'payment_details' => $this->payment_details,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'origin' => $this->payment_details['origin'] ?? ($this->payment_details['route_stop_collection_id'] ?? null ? 'route_collection' : null),
            'registered_by' => $this->whenLoaded('registeredBy', fn () => $this->registeredBy ? [
                'id' => $this->registeredBy->id,
                'name' => $this->registeredBy->name,
            ] : null),
            'cash_session' => $this->whenLoaded('cashSession', fn () => $this->cashSession ? [
                'id' => $this->cashSession->id,
                'status' => $this->cashSession->status,
            ] : null),
            'store_payment_method' => $this->whenLoaded('storePaymentMethod', function () {
                return [
                    'id' => $this->storePaymentMethod?->id,
                    'name' => $this->storePaymentMethod?->custom_name ?? $this->storePaymentMethod?->paymentMethod?->name,
                    'code' => $this->storePaymentMethod?->paymentMethod?->code,
                ];
            }),
        ];
    }
}
