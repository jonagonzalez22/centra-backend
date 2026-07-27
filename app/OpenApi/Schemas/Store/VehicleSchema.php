<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'VehicleResource',
    title: 'VehicleResource',
    description: 'Representación de un vehículo'
)]
class VehicleSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'Furgoneta Blanca')]
    public string $name;

    #[OA\Property(example: 'ABC-1234')]
    public string $plate;

    #[OA\Property(example: 'camioneta', enum: ['auto', 'moto', 'bicicleta', 'camioneta', 'camion'])]
    public string $type;

    #[OA\Property(example: 500, nullable: true)]
    public ?int $capacity_kg;

    #[OA\Property(example: true)]
    public bool $is_active;

    #[OA\Property(example: 'repair', enum: ['maintenance', 'repair', 'accident', 'unavailable', 'other'], nullable: true)]
    public ?string $inactivation_reason;

    #[OA\Property(example: 'En el taller por cambio de filtros', nullable: true)]
    public ?string $inactivation_notes;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $updated_at;
}
