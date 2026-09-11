<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'CashSessionBlind', description: 'Vista operativa blind de una sesión de caja')]
class CashSessionBlindSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(example: 'open', enum: ['open', 'pending_reconciliation'])]
    public string $status;

    #[OA\Property(type: 'string', format: 'date', nullable: true)]
    public ?string $business_date;

    #[OA\Property(type: 'number', format: 'float')]
    public float $opening_amount;

    #[OA\Property(type: 'number', format: 'float', nullable: true)]
    public ?float $declared_amount;

    #[OA\Property(type: 'string', nullable: true)]
    public ?string $submitted_at;
}
