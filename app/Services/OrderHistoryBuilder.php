<?php

namespace App\Services;

use App\Models\CommercialOperation;
use App\Models\DeliveryRouteEvent;
use App\Models\RouteStop;
use Illuminate\Support\Collection;

class OrderHistoryBuilder
{
    public function attach(CommercialOperation $operation, string $storeId): CommercialOperation
    {
        $operation->load([
            'items.product' => fn ($query) => $query->where('store_id', $storeId),
            'events' => fn ($query) => $query->where('store_id', $storeId),
            'events.user',
            'routeStops.route' => fn ($query) => $query->where('store_id', $storeId),
            'routeStops.route.driver',
            'routeStops.route.processedBy',
            'routeStops.route.events.user',
            'routeStops.completedBy',
            'routeStops.items.product' => fn ($query) => $query->where('store_id', $storeId),
            'routeStops.items.discrepancy.resolvedBy',
        ]);
        $operation->setAttribute('history', $this->build($operation));

        return $operation;
    }

    public function build(CommercialOperation $operation): array
    {
        $history = collect([$this->createdEntry($operation)]);

        foreach ($operation->events as $event) {
            $history->push($this->commercialEventEntry($event));
        }

        $stops = $operation->routeStops
            ->filter(fn (RouteStop $stop) => $stop->route && $stop->route->store_id === $operation->store_id);

        $deliveryContext = $this->deliveryContext($operation, $stops);

        foreach ($stops as $stop) {
            $history->push($this->assignmentEntry($stop));

            if ($stop->status === 'completed' && $stop->items->sum('quantity_delivered') > 0) {
                $history->push($this->deliveryEntry($stop, $deliveryContext));
            }
        }

        return $history
            ->sortBy([
                ['occurred_at', 'asc'],
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->map(function (array $entry) {
                unset($entry['sort_order']);

                return $entry;
            })
            ->values()
            ->all();
    }

    private function createdEntry(CommercialOperation $operation): array
    {
        return [
            'id' => "order-created-{$operation->id}",
            'type' => 'order_created',
            'occurred_at' => $this->formatDateTime($operation->created_at),
            'title' => 'Pedido creado',
            'description' => null,
            'status' => 'confirmed',
            'user' => $this->userData($operation->user),
            'route' => null,
            'details' => null,
            'sort_order' => 0,
        ];
    }

    private function commercialEventEntry($event): array
    {
        $titles = [
            'delivery_date_changed' => 'Fecha de entrega reprogramada',
            'reschedule' => 'Fecha de entrega reprogramada',
            'order_cancelled' => 'Pedido cancelado',
        ];

        return [
            'id' => "commercial-event-{$event->id}",
            'type' => $event->event_type,
            'occurred_at' => $this->formatDateTime($event->created_at),
            'title' => $titles[$event->event_type] ?? $event->event_type,
            'description' => $event->observation ?: $event->reason_note,
            'status' => 'confirmed',
            'user' => $this->userData($event->user),
            'route' => null,
            'details' => [
                'previous_date' => $event->previous_date?->format('Y-m-d'),
                'new_date' => $event->new_date?->format('Y-m-d'),
                'previous_status' => $event->previous_status,
                'new_status' => $event->new_status,
                'reason' => $event->reason,
                'reason_code' => $event->reason_code,
                'reason_note' => $event->reason_note,
                'observation' => $event->observation,
            ],
            'sort_order' => 10,
        ];
    }

    private function assignmentEntry(RouteStop $stop): array
    {
        $event = $this->routeEvent($stop, 'stop_added');

        return [
            'id' => "route-assigned-{$stop->id}",
            'type' => 'route_assigned',
            'occurred_at' => $this->formatDateTime($event?->created_at ?? $stop->created_at),
            'title' => 'Asignado a ruta',
            'description' => null,
            'status' => 'confirmed',
            'user' => $this->userData($event?->user),
            'route' => $this->routeData($stop),
            'details' => null,
            'sort_order' => 20,
        ];
    }

    private function deliveryEntry(RouteStop $stop, array $context): array
    {
        $confirmed = $stop->route->status === 'completed';
        $event = $confirmed
            ? $this->reconciliationEvent($stop)
            : $this->routeEvent($stop, 'stop_completed');
        $isFinal = $confirmed && ($context['final_stop_id'] ?? null) === $stop->id;

        return [
            'id' => "delivery-{$stop->id}",
            'type' => $confirmed
                ? ($isFinal ? 'delivery_reconciled_final' : 'delivery_reconciled_partial')
                : 'delivery_reported',
            'occurred_at' => $this->formatDateTime(
                $confirmed
                    ? ($stop->route->processed_at ?? $event?->created_at ?? $stop->completed_at)
                    : ($stop->completed_at ?? $event?->created_at)
            ),
            'title' => $confirmed
                ? ($isFinal ? 'Entrega final conciliada' : 'Entrega parcial conciliada')
                : 'Entrega informada',
            'description' => $confirmed
                ? ($isFinal ? 'Pedido entregado' : 'Queda mercadería pendiente')
                : 'Pendiente de conciliación',
            'status' => $confirmed ? 'confirmed' : 'provisional',
            'user' => $this->userData($confirmed ? ($stop->route->processedBy ?? $event?->user) : $stop->completedBy),
            'route' => $this->routeData($stop),
            'details' => [
                'driver' => $this->userData($stop->route->driver),
                'reconciled_by' => $confirmed
                    ? $this->userData($stop->route->processedBy ?? $event?->user)
                    : null,
                'stop' => [
                    'id' => $stop->id,
                    'status' => $stop->status,
                    'completed_at' => $this->formatDateTime($stop->completed_at),
                ],
                'items' => $stop->items->filter(fn ($item) => $item->product)->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name,
                    'quantity_planned' => (int) $item->quantity_planned,
                    'quantity_loaded' => (int) $item->quantity_loaded,
                    'quantity_delivered' => (int) $item->quantity_delivered,
                    'discrepancies' => $item->discrepancy ? [[
                        'id' => $item->discrepancy->id,
                        'quantity' => (int) $item->discrepancy->difference_quantity,
                        'resolution_type' => $item->discrepancy->resolution_type,
                        'status' => $item->discrepancy->resolution_type ? 'resolved' : 'pending',
                        'notes' => $item->discrepancy->notes,
                        'resolved_by' => $this->userData($item->discrepancy->resolvedBy),
                        'resolved_at' => $this->formatDateTime($item->discrepancy->resolved_at),
                    ]] : [],
                ])->values()->all(),
            ],
            'sort_order' => 30,
        ];
    }

    /**
     * Determine which completed stop made the current commercial obligation whole.
     * Rejected/returned quantities are added back to reconstruct the obligation before
     * each reconciliation and then removed at the route where they were resolved.
     */
    private function deliveryContext(CommercialOperation $operation, Collection $stops): array
    {
        $currentOrdered = $operation->items
            ->groupBy('product_id')
            ->map(fn (Collection $items) => (int) $items->sum('quantity'));

        $reductions = $stops->flatMap->items
            ->filter(fn ($item) => in_array($item->discrepancy?->resolution_type, ['returned', 'rejected_by_customer'], true))
            ->groupBy('product_id')
            ->map(fn (Collection $items) => (int) $items->sum(fn ($item) => $item->discrepancy->difference_quantity));

        $initialOrdered = $currentOrdered->map(
            fn (int $quantity, string $productId) => $quantity + ($reductions[$productId] ?? 0)
        );
        foreach ($reductions as $productId => $quantity) {
            if (! $initialOrdered->has($productId)) {
                $initialOrdered[$productId] = $quantity;
            }
        }

        $delivered = collect();
        $resolvedReductions = collect();
        $finalStopId = null;

        $confirmedStops = $stops
            ->filter(fn (RouteStop $stop) => $stop->status === 'completed' && $stop->route->status === 'completed')
            ->sortBy(fn (RouteStop $stop) => $stop->route->processed_at ?? $stop->completed_at ?? $stop->created_at);

        foreach ($confirmedStops as $stop) {
            foreach ($stop->items as $item) {
                $delivered[$item->product_id] = ($delivered[$item->product_id] ?? 0) + (int) $item->quantity_delivered;

                if (in_array($item->discrepancy?->resolution_type, ['returned', 'rejected_by_customer'], true)) {
                    $resolvedReductions[$item->product_id] = ($resolvedReductions[$item->product_id] ?? 0)
                        + (int) $item->discrepancy->difference_quantity;
                }
            }

            $fulfilled = $initialOrdered->isNotEmpty() && $initialOrdered->every(
                fn (int $quantity, string $productId) => ($delivered[$productId] ?? 0)
                    >= $quantity - ($resolvedReductions[$productId] ?? 0)
            );

            if ($fulfilled) {
                $finalStopId = $stop->id;
                break;
            }
        }

        return ['final_stop_id' => $finalStopId];
    }

    private function routeEvent(RouteStop $stop, string $type): ?DeliveryRouteEvent
    {
        return $stop->route->events->first(function (DeliveryRouteEvent $event) use ($stop, $type) {
            return $event->event_type === $type
                && (($event->metadata['stop_id'] ?? null) === $stop->id);
        });
    }

    private function reconciliationEvent(RouteStop $stop): ?DeliveryRouteEvent
    {
        return $stop->route->events->first(
            fn (DeliveryRouteEvent $event) => in_array(
                $event->event_type,
                ['route_reconciliation_completed', 'route_processed'],
                true
            )
        );
    }

    private function routeData(RouteStop $stop): array
    {
        return [
            'id' => $stop->route->id,
            'label' => '#'.strtoupper(substr($stop->route->id, 0, 8)),
            'status' => $stop->route->status,
            'operational_date' => $stop->route->operational_date?->format('Y-m-d'),
        ];
    }

    private function userData($user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }

    private function formatDateTime($value): ?string
    {
        return $value?->format('Y-m-d H:i:s');
    }
}
