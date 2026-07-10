<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CashSessionLight',
    title: 'CashSessionLight',
    description: 'Versión resumida de CashSession para contexto de autenticación'
)]
class CashSessionLightSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'open', enum: ['open', 'closed'])]
    public string $status;

    #[OA\Property(example: 1000.00)]
    public float $opening_amount;

    #[OA\Property(example: '2026-07-10 08:00:00')]
    public string $opened_at;
}
