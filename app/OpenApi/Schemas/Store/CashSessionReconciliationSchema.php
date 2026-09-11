<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'CashSessionReconciliation', description: 'Detalle financiero para el arqueo de una sesión pendiente')]
class CashSessionReconciliationSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 'pending_reconciliation')]
    public string $status;

    #[OA\Property(type: 'number', format: 'float')]
    public float $opening_amount;

    #[OA\Property(type: 'number', format: 'float')]
    public float $expected_amount;

    #[OA\Property(type: 'number', format: 'float')]
    public float $declared_amount;

    #[OA\Property(type: 'number', format: 'float')]
    public float $cash_income;

    #[OA\Property(type: 'number', format: 'float')]
    public float $total_collected;

    #[OA\Property(type: 'integer')]
    public int $payment_count;

    #[OA\Property(type: 'integer')]
    public int $operation_count;

    #[OA\Property(type: 'array', items: new OA\Items(type: 'object'))]
    public array $totals_by_payment_method;
}
