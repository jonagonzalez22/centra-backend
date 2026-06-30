<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomerContact',
    title: 'CustomerContact',
    description: 'Representación de un contacto de cliente'
)]
class CustomerContactSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440001')]
    public string $customer_id;

    #[OA\Property(example: 'Juan Pérez')]
    public string $name;

    #[OA\Property(example: 'Gerente', nullable: true)]
    public ?string $position;

    #[OA\Property(example: 'juan@ejemplo.com', nullable: true)]
    public ?string $email;

    #[OA\Property(example: '+54 11 1234-5678', nullable: true)]
    public ?string $phone;

    #[OA\Property(example: true)]
    public bool $is_main;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $updated_at;
}
