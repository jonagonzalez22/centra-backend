<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeliveryRouteEvent',
    title: 'DeliveryRouteEvent',
    description: 'Representación de un evento en el historial de una ruta'
)]
class DeliveryRouteEventSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'created', enum: ['created', 'planned', 'reverted', 'cancelled', 'stop_added', 'stop_removed'])]
    public string $event_type;

    #[OA\Property(example: null, nullable: true)]
    public ?string $from_status;

    #[OA\Property(example: 'draft')]
    public string $to_status;

    #[OA\Property(example: 'Creación inicial de la ruta', nullable: true)]
    public ?string $reason;

    #[OA\Property(type: 'object', nullable: true)]
    public ?object $metadata;

    #[OA\Property(
        type: 'object',
        nullable: true,
        properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
            new OA\Property(property: 'name', type: 'string', example: 'Carlos López'),
        ]
    )]
    public ?object $user;

    #[OA\Property(example: '2026-07-30 12:00:00')]
    public string $created_at;
}
