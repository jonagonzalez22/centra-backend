<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CustomerAddress',
    title: 'CustomerAddress',
    description: 'Representación de una dirección de cliente'
)]
class CustomerAddressSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440001')]
    public string $customer_id;

    #[OA\Property(ref: '#/components/schemas/Locality', nullable: true)]
    public ?object $locality;

    #[OA\Property(example: 'Av. Corrientes')]
    public string $street;

    #[OA\Property(example: '1234')]
    public string $number;

    #[OA\Property(example: '3', nullable: true)]
    public ?string $floor;

    #[OA\Property(example: 'A', nullable: true)]
    public ?string $apartment;

    #[OA\Property(example: 'C1043AAN')]
    public string $postal_code;

    #[OA\Property(example: -34.603722, nullable: true)]
    public ?float $latitude;

    #[OA\Property(example: -58.381592, nullable: true)]
    public ?float $longitude;

    #[OA\Property(example: 'billing', enum: ['billing', 'delivery', 'other'])]
    public string $type;

    #[OA\Property(example: true)]
    public bool $is_main;

    #[OA\Property(example: 'Casa color roja', nullable: true)]
    public ?string $observations;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $updated_at;
}
