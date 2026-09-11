<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CashSession',
    title: 'CashSession',
    description: 'Representación de una sesión de caja'
)]
class CashSessionSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'open', enum: ['open', 'pending_reconciliation', 'closed'])]
    public string $status;

    #[OA\Property(type: 'string', format: 'date', nullable: true)]
    public ?string $business_date;

    #[OA\Property(example: 1000.00, description: 'Monto inicial de apertura de caja')]
    public float $opening_amount;

    #[OA\Property(example: 1500.00, description: 'Monto esperado (apertura + pagos en efectivo)')]
    public float $expected_amount;

    #[OA\Property(example: 1495.50, nullable: true, description: 'Monto real contado al cierre')]
    public ?float $real_amount;

    #[OA\Property(example: 1495.00, nullable: true, description: 'Efectivo declarado por el cajero')]
    public ?float $declared_amount;

    #[OA\Property(type: 'object', nullable: true)]
    public ?object $closed_by;

    #[OA\Property(nullable: true)]
    public ?string $declaration_notes;

    #[OA\Property(nullable: true)]
    public ?string $reconciliation_notes;

    #[OA\Property(type: 'number', format: 'float', nullable: true)]
    public ?float $difference;

    #[OA\Property(example: null, nullable: true, description: 'Notas u observaciones')]
    public ?string $notes;

    #[OA\Property(example: '2026-07-10 08:00:00')]
    public string $opened_at;

    #[OA\Property(example: null, nullable: true)]
    public ?string $submitted_at;

    #[OA\Property(example: null, nullable: true)]
    public ?string $closed_at;
}
