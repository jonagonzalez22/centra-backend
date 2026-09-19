<?php

namespace App\Http\Resources;

use App\Models\OperationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

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
 *   @OA\Property(property="customer_display_name", type="string", nullable=true),
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
 *   @OA\Property(property="history", type="array", description="Historial funcional normalizado del pedido", @OA\Items(type="object")),
 *   @OA\Property(property="route_ids", type="array", @OA\Items(type="string", format="uuid"), description="IDs de rutas activas (no canceladas) donde está asignado este pedido"),
 *   @OA\Property(property="delivery_summary", type="object", description="Resumen operativo de mercadería pendiente")
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
            'pending_amount' => max(0, (float) $this->total - (float) ($this->payments_sum_amount ?? 0)),
            'completed_at' => $this->completed_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            'branch_id' => $this->branch_id,
            'customer_display_name' => $this->customer_display_name,
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
            'items' => $this->whenLoaded('items', fn () => $this->currentCommercialItems()),
            'payments' => OperationPaymentResource::collection($this->whenLoaded('payments')),
            'events' => CommercialOperationEventResource::collection($this->whenLoaded('events')),
            'history' => $this->history ?? [],
            'route_ids' => $this->whenLoaded('routeStops', fn () => $this->routeStops
                ->where('status', '!=', 'cancelled')
                ->pluck('route_id')
                ->unique()
                ->values()
                ->toArray(), []),
            'delivery_summary' => $this->when(
                $this->type === 'order',
                fn () => app(\App\Services\DeliverySummaryService::class)->summarize($this->resource)
            ),
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

    /**
     * OperationItem preserves commercial history, including lines reduced to
     * zero. The order response instead represents the products currently
     * owed: inactive lines are omitted and only economically equivalent lines
     * of the same product are combined.
     *
     * @return array<int, array<string, int|float|string>>
     */
    private function currentCommercialItems(): array
    {
        /** @var Collection<int, OperationItem> $items */
        $items = $this->items
            ->filter(fn (OperationItem $item): bool => (int) $item->quantity > 0)
            ->sortBy([
                ['product_id', 'asc'],
                ['created_at', 'asc'],
                ['id', 'asc'],
            ]);

        return $items
            ->groupBy('product_id')
            ->flatMap(function (Collection $productItems): array {
                $groups = [];

                foreach ($productItems as $item) {
                    $groupIndex = collect($groups)->search(
                        fn (array $group): bool => $this->hasSameCommercialTerms($group['source'], $item)
                    );

                    if ($groupIndex === false) {
                        $groups[] = [
                            'source' => $item,
                            'quantity' => (int) $item->quantity,
                            'subtotal' => (float) $item->subtotal,
                            'tax_amount' => (float) $item->tax_amount,
                            'discount_amount' => (float) $item->discount_amount,
                        ];

                        continue;
                    }

                    $groups[$groupIndex]['quantity'] += (int) $item->quantity;
                    $groups[$groupIndex]['subtotal'] += (float) $item->subtotal;
                    $groups[$groupIndex]['tax_amount'] += (float) $item->tax_amount;
                    $groups[$groupIndex]['discount_amount'] += (float) $item->discount_amount;
                }

                return collect($groups)->map(function (array $group): array {
                    /** @var OperationItem $source */
                    $source = $group['source'];

                    return [
                        // Stable representative key for the display line. The
                        // underlying OperationItems remain untouched.
                        'id' => $source->id,
                        'product_id' => $source->product_id,
                        'product_name' => $source->product_name,
                        'quantity' => $group['quantity'],
                        'price' => (float) $source->price,
                        'subtotal' => round($group['subtotal'], 2),
                        'tax_amount' => round($group['tax_amount'], 2),
                        'discount_amount' => round($group['discount_amount'], 2),
                    ];
                })->all();
            })
            ->values()
            ->all();
    }

    private function hasSameCommercialTerms(OperationItem $first, OperationItem $second): bool
    {
        return $this->amountInCents($first->price) === $this->amountInCents($second->price)
            && $this->amountInCents($first->tax_amount) * (int) $second->quantity
                === $this->amountInCents($second->tax_amount) * (int) $first->quantity
            && $this->amountInCents($first->discount_amount) * (int) $second->quantity
                === $this->amountInCents($second->discount_amount) * (int) $first->quantity;
    }

    private function amountInCents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
