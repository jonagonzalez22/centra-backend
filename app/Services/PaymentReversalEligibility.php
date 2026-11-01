<?php

namespace App\Services;

use App\Models\CashSession;
use App\Models\CommercialOperation;
use App\Models\OperationPayment;

class PaymentReversalEligibility
{
    private const REVERSIBLE_ORIGINS = ['manual_collection', 'order_deposit'];

    /**
     * @return array{allowed: bool, reason: ?string}
     */
    public function evaluate(
        OperationPayment $payment,
        CommercialOperation $operation,
        ?CashSession $cashSession = null,
        ?bool $hasDirectRouteLink = null
    ): array {
        if ($payment->status !== 'active') {
            return $this->blocked('already_reversed');
        }

        $origin = $this->origin($payment);
        if ($origin === 'pos_sale' || $operation->type !== 'order') {
            return $this->blocked('pos_sale');
        }

        if ($hasDirectRouteLink ?? $payment->routeStopCollection !== null) {
            return $this->blocked('route_collection');
        }

        if (filled(($payment->payment_details ?? [])['route_stop_collection_id'] ?? null)) {
            return $this->blocked('route_collection_linked');
        }

        if ($operation->status === 'cancelled') {
            return $this->blocked('operation_cancelled');
        }

        if ($operation->status === 'closed') {
            return $this->blocked('operation_closed');
        }

        if (! in_array($origin, self::REVERSIBLE_ORIGINS, true)) {
            return $this->blocked('origin_not_reversible');
        }

        if (blank($payment->cash_session_id) || $cashSession === null) {
            return $this->blocked('cash_session_not_operational');
        }

        if ($cashSession->status === 'closed') {
            return $this->blocked('cash_session_closed');
        }

        if ($cashSession->status !== 'open') {
            return $this->blocked('cash_session_not_operational');
        }

        return ['allowed' => true, 'reason' => null];
    }

    public function validationMessage(string $reason): string
    {
        return match ($reason) {
            'already_reversed' => 'El pago ya fue anulado.',
            'pos_sale' => 'Solo se pueden anular pagos de pedidos. Para una venta utilizá Cancelar venta.',
            'route_collection', 'route_collection_linked' => 'Los pagos de cobranza de ruta no se pueden anular individualmente.',
            'cash_session_closed', 'cash_session_not_operational' => 'El pago no se puede anular porque su caja ya no está abierta.',
            'operation_cancelled', 'operation_closed' => 'El pedido no admite anulación de pagos en su estado actual.',
            default => 'El origen de este pago no admite anulación individual.',
        };
    }

    private function origin(OperationPayment $payment): ?string
    {
        return ($payment->payment_details ?? [])['origin'] ?? null;
    }

    /**
     * @return array{allowed: false, reason: string}
     */
    private function blocked(string $reason): array
    {
        return ['allowed' => false, 'reason' => $reason];
    }
}
