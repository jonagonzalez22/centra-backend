<?php

namespace App\Services;

use App\Models\CommercialOperation;
use Illuminate\Validation\ValidationException;

class OrderDeliveryDateService
{
    public function __construct(private readonly OrderEditabilityService $orderEditabilityService) {}

    /**
     * Validates and changes the date of an order already locked by its caller.
     * The CommercialOperation row is the mutex also used by route assignment.
     */
    public function change(CommercialOperation $order, string $newDate, string $dateField = 'requested_delivery_date'): string
    {
        if ($order->requested_delivery_date === null) {
            throw ValidationException::withMessages([
                $dateField => ['La operación no tiene fecha de entrega asignada.'],
            ]);
        }

        $currentDate = $order->requested_delivery_date->format('Y-m-d');

        if ($newDate === $currentDate) {
            throw ValidationException::withMessages([
                $dateField => ['La nueva fecha debe ser diferente a la actual.'],
            ]);
        }

        $editability = $this->orderEditabilityService->describe($order);

        if (! $editability['editable']) {
            throw ValidationException::withMessages([
                'order' => [$this->editabilityMessage($editability['block_reason'])],
            ]);
        }

        if (! $editability['delivery_date_editable']) {
            throw ValidationException::withMessages([
                $dateField => ['La fecha de entrega no puede modificarse porque el pedido tiene mercadería comprometida en una ruta activa.'],
            ]);
        }

        $order->update(['requested_delivery_date' => $newDate]);

        return $currentDate;
    }

    private function editabilityMessage(?string $blockReason): string
    {
        return match ($blockReason) {
            'terminal_status' => 'El pedido no puede editarse en su estado actual.',
            'active_extra_sale' => 'El pedido tiene una venta extra activa en una ruta operativa.',
            default => 'El pedido no puede editarse actualmente.',
        };
    }
}
