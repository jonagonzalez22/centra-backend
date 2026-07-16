<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *   schema="OperationPaymentResource",
 *   type="object",
 *   title="OperationPaymentResource",
 *   @OA\Property(property="id", type="string", format="uuid"),
 *   @OA\Property(property="amount", type="number", format="float"),
 *   @OA\Property(property="reference", type="string", nullable=true),
 *   @OA\Property(property="payment_details", type="object", nullable=true),
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
