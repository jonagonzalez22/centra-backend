<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'CashSessionPaymentDetail', description: 'Pago individual informativo de un arqueo')]
class CashSessionPaymentDetailSchema
{
    #[OA\Property(format: 'uuid')]
    public string $id;

    #[OA\Property(type: 'number', format: 'float')]
    public float $amount;

    #[OA\Property(nullable: true)]
    public ?string $reference;

    #[OA\Property(type: 'string', format: 'date-time')]
    public string $created_at;

    #[OA\Property(nullable: true)]
    public ?string $origin;

    #[OA\Property(type: 'object', nullable: true)]
    public ?object $payment_details;

    #[OA\Property(type: 'object', properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'operation_number', type: 'string'),
        new OA\Property(property: 'type', type: 'string'),
    ])]
    public object $operation;

    #[OA\Property(type: 'object', nullable: true, properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'name', type: 'string'),
    ])]
    public ?object $registered_by;
}
