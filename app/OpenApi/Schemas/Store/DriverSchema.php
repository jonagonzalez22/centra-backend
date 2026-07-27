<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DriverResource',
    title: 'DriverResource',
    description: 'Representación de un conductor'
)]
class DriverSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'Carlos López')]
    public string $name;

    #[OA\Property(example: 'carlos@tienda.com')]
    public string $email;

    #[OA\Property(example: true)]
    public bool $is_active;

    #[OA\Property(type: 'array', items: new OA\Items(type: 'string'), example: ['STORE_DRIVER'])]
    public array $roles;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $created_at;
}
