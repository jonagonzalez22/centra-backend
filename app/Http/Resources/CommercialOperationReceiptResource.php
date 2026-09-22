<?php

namespace App\Http\Resources;

use App\Models\OperationPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *   schema="CommercialOperationReceiptResource",
 *   type="object",
 *   title="CommercialOperationReceiptResource",
 *
 *   @OA\Property(property="store", type="object",
 *     @OA\Property(property="name", type="string", nullable=true),
 *     @OA\Property(property="cuit", type="string", nullable=true),
 *     @OA\Property(property="address", type="string", nullable=true),
 *     @OA\Property(property="city", type="string", nullable=true),
 *     @OA\Property(property="state", type="string", nullable=true),
 *     @OA\Property(property="timezone", type="string")
 *   ),
 *   @OA\Property(property="operation", type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="operation_number", type="string", example="V-000123"),
 *     @OA\Property(property="type", type="string", example="sale"),
 *     @OA\Property(property="status", type="string", example="confirmed"),
 *     @OA\Property(property="occurred_at", type="string", format="date-time"),
 *     @OA\Property(property="cashier", type="object", nullable=true,
 *       @OA\Property(property="id", type="string", format="uuid"),
 *       @OA\Property(property="name", type="string")
 *     )
 *   ),
 *   @OA\Property(property="customer", type="object", nullable=true,
 *     @OA\Property(property="display_name", type="string")
 *   ),
 *   @OA\Property(property="items", type="array", @OA\Items(type="object",
 *     @OA\Property(property="product_name", type="string"),
 *     @OA\Property(property="quantity", type="integer"),
 *     @OA\Property(property="unit_price", type="number", format="float"),
 *     @OA\Property(property="subtotal", type="number", format="float"),
 *     @OA\Property(property="discount_amount", type="number", format="float"),
 *     @OA\Property(property="tax_amount", type="number", format="float")
 *   )),
 *   @OA\Property(property="totals", type="object",
 *     @OA\Property(property="subtotal", type="number", format="float"),
 *     @OA\Property(property="tax", type="number", format="float"),
 *     @OA\Property(property="discount", type="number", format="float"),
 *     @OA\Property(property="total", type="number", format="float"),
 *     @OA\Property(property="paid_amount", type="number", format="float"),
 *     @OA\Property(property="pending_amount", type="number", format="float")
 *   ),
 *   @OA\Property(property="payments", type="array", @OA\Items(type="object",
 *     @OA\Property(property="method_name", type="string", nullable=true),
 *     @OA\Property(property="amount", type="number", format="float")
 *   ))
 * )
 */
class CommercialOperationReceiptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $store = $this->store;
        $timezone = $store?->timezone ?: config('app.timezone', 'UTC');
        $customerDisplayName = $this->customer_display_name ?? $this->customer?->display_name;
        $paidAmount = round((float) $this->payments->sum('amount'), 2);

        return [
            'store' => [
                'name' => $store?->name,
                'cuit' => $store?->cuit,
                'address' => $store?->address,
                'city' => $store?->city,
                'state' => $store?->state,
                'timezone' => $timezone,
            ],
            'operation' => [
                'id' => $this->id,
                'operation_number' => $this->operation_number,
                'type' => $this->type,
                'status' => $this->status,
                'occurred_at' => $this->created_at?->setTimezone($timezone)?->toIso8601String(),
                'cashier' => $this->user ? [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ] : null,
            ],
            'customer' => $customerDisplayName === null ? null : [
                'display_name' => $customerDisplayName,
            ],
            'items' => $this->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->price,
                'subtotal' => (float) $item->subtotal,
                'discount_amount' => (float) $item->discount_amount,
                'tax_amount' => (float) $item->tax_amount,
            ])->values(),
            'totals' => [
                'subtotal' => (float) $this->subtotal,
                'tax' => (float) $this->tax,
                'discount' => (float) $this->discount,
                'total' => (float) $this->total,
                'paid_amount' => $paidAmount,
                'pending_amount' => max(0, round((float) $this->total - $paidAmount, 2)),
            ],
            'payments' => $this->payments->map(fn (OperationPayment $payment) => [
                'method_name' => $payment->storePaymentMethod?->custom_name
                    ?? $payment->storePaymentMethod?->paymentMethod?->name,
                'amount' => (float) $payment->amount,
            ])->values(),
        ];
    }
}
