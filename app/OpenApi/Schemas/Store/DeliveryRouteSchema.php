<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeliveryRoute',
    title: 'DeliveryRoute',
    description: 'Representación de una ruta de entrega'
)]
class DeliveryRouteSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440001')]
    public string $store_id;

    #[OA\Property(example: '2026-07-30', format: 'date')]
    public string $operational_date;

    #[OA\Property(example: 'draft', enum: ['draft', 'planned', 'cancelled'])]
    public string $status;

    #[OA\Property(example: 'Ruta de reparto zona norte', nullable: true)]
    public ?string $observations;

    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440002', nullable: true)]
    public ?string $created_by;

    #[OA\Property(ref: '#/components/schemas/VehicleResource', nullable: true)]
    public ?object $vehicle;

    #[OA\Property(ref: '#/components/schemas/DriverResource', nullable: true)]
    public ?object $driver;

    #[OA\Property(type: 'array', items: new OA\Items(ref: '#/components/schemas/RouteStop'))]
    public array $stops;

    #[OA\Property(type: 'array', items: new OA\Items(ref: '#/components/schemas/DeliveryRouteEvent'))]
    public array $events;

    #[OA\Property(example: '2026-07-30 12:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-07-30 12:00:00')]
    public string $updated_at;
}
