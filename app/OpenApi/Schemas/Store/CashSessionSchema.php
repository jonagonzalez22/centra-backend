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

    #[OA\Property(ref: '#/components/schemas/StoreLight', nullable: true)]
    public ?object $store;

    #[OA\Property(ref: '#/components/schemas/User', nullable: true)]
    public ?object $user;

    #[OA\Property(example: 'open', enum: ['open', 'closed'])]
    public string $status;

    #[OA\Property(example: 1000.00, description: 'Monto inicial de apertura de caja')]
    public float $opening_amount;

    #[OA\Property(example: 1500.00, description: 'Monto esperado (apertura + ventas - egresos)')]
    public float $expected_amount;

    #[OA\Property(example: 1495.50, nullable: true, description: 'Monto real contado al cierre')]
    public ?float $real_amount;

    #[OA\Property(example: null, nullable: true, description: 'Notas u observaciones')]
    public ?string $notes;

    #[OA\Property(example: '2026-07-10 08:00:00')]
    public string $opened_at;

    #[OA\Property(example: null, nullable: true)]
    public ?string $closed_at;

    #[OA\Property(example: '2026-07-10 08:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-07-10 18:00:00')]
    public string $updated_at;
}
