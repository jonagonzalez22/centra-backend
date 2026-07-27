<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RouteStop',
    title: 'RouteStop',
    description: 'Representación de una parada en una ruta de entrega'
)]
class RouteStopSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440001')]
    public string $route_id;

    #[OA\Property(example: 1)]
    public int $sequence;

    #[OA\Property(example: 'pending', enum: ['pending', 'cancelled'])]
    public string $status;

    #[OA\Property(example: 'Entregar en recepción', nullable: true)]
    public ?string $logistics_notes;

    #[OA\Property(
        type: 'object',
        nullable: true,
        properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
            new OA\Property(property: 'operation_number', type: 'string', example: 'OP-000001'),
            new OA\Property(property: 'requested_delivery_date', type: 'string', format: 'date', example: '2026-07-30'),
            new OA\Property(property: 'customer', properties: [
                new OA\Property(property: 'name', type: 'string', example: 'Juan Pérez'),
                new OA\Property(property: 'document', type: 'string', example: '20-12345678-5'),
            ], type: 'object', nullable: true),
            new OA\Property(property: 'address', properties: [
                new OA\Property(property: 'street', type: 'string', example: 'Av. Corrientes'),
                new OA\Property(property: 'number', type: 'string', example: '1234'),
                new OA\Property(property: 'locality', type: 'string', example: 'CABA', nullable: true),
            ], type: 'object', nullable: true),
        ]
    )]
    public ?object $order;

    #[OA\Property(example: '2026-07-30 12:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-07-30 12:00:00')]
    public string $updated_at;
}
