<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\CommercialOperationEvent;
use App\Models\OperationItem;
use App\Models\Product;
use App\Models\RouteStop;
use App\Models\RouteStopItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelPendingDeliveryService
{
    public function __construct(
        private readonly DeliverySummaryService $summaryService,
        private readonly CommercialOperationService $operationService,
    ) {}

    public function cancel(CommercialOperation $operation, string $reason, User $user): CommercialOperation
    {
        return DB::transaction(function () use ($operation, $reason, $user) {
            $operation = CommercialOperation::forStore($user->store_id)
                ->whereKey($operation->id)->lockForUpdate()->firstOrFail();

            if ($operation->type !== 'order' || $operation->status !== 'partially_delivered') {
                throw ValidationException::withMessages([
                    'status' => ['Solo puede cancelarse el pendiente de un pedido parcialmente entregado.'],
                ]);
            }

            OperationItem::where('operation_id', $operation->id)->orderBy('id')->lockForUpdate()->get();
            RouteStop::where('order_id', $operation->id)->orderBy('id')->lockForUpdate()->get();
            RouteStopItem::whereHas('routeStop', fn ($query) => $query->where('order_id', $operation->id))
                ->orderBy('id')->lockForUpdate()->get();
            $operation->payments()->lockForUpdate()->get();
            $operation->unsetRelation('items')->unsetRelation('routeStops');
            $summary = $this->summaryService->summarize($operation);
            $pending = collect($summary['items'])->where('pending_quantity', '>', 0)->values();

            if ($pending->isEmpty() || collect($summary['items'])->sum('delivered_quantity') <= 0) {
                throw ValidationException::withMessages([
                    'pending_delivery' => ['El pedido no tiene mercadería pendiente cancelable.'],
                ]);
            }

            if ($pending->contains(fn (array $item) => $item['planned_active_quantity'] > 0)) {
                throw ValidationException::withMessages([
                    'pending_delivery' => ['No se puede cancelar el pendiente porque parte de la mercadería está asignada a una ruta activa. Retirala o replanificá la asignación desde Logística antes de continuar.'],
                ]);
            }

            foreach ($pending as $item) {
                $this->operationService->reduceCommercialObligation(
                    $operation->id,
                    $item['product_id'],
                    $item['pending_quantity']
                );
            }

            $operation->refresh();
            $paidInCents = (int) round((float) $operation->payments()->sum('amount') * 100);
            $newTotalInCents = (int) round((float) $operation->total * 100);
            if ($newTotalInCents < $paidInCents) {
                throw ValidationException::withMessages([
                    'payments' => ['No se puede cancelar el pendiente porque el nuevo total del pedido quedaría por debajo de los pagos confirmados.'],
                ]);
            }

            foreach ($pending->sortBy('product_id') as $item) {
                $product = Product::forStore($operation->store_id)
                    ->whereKey($item['product_id'])->lockForUpdate()->firstOrFail();
                $product->update([
                    'stock_reserved' => max(0, $product->stock_reserved - $item['pending_quantity']),
                ]);
            }

            $operation->update(['status' => 'delivered']);
            CommercialOperationEvent::create([
                'store_id' => $operation->store_id,
                'operation_id' => $operation->id,
                'event_type' => 'remaining_delivery_cancelled',
                'previous_date' => $operation->requested_delivery_date ?? $operation->created_at->toDateString(),
                'new_date' => null,
                'reason' => 'remaining_delivery_cancelled',
                'observation' => $reason,
                'metadata' => ['items' => $pending->all()],
                'user_id' => $user->id,
                'previous_status' => 'partially_delivered',
                'new_status' => 'delivered',
            ]);

            return $operation->fresh();
        });
    }
}
