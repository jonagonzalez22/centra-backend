<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'EligibleOrder',
    title: 'EligibleOrder',
    description: 'Representación de un pedido elegible para ser asignado a una ruta'
)]
class EligibleOrderSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'OP-000001')]
    public string $operation_number;

    #[OA\Property(example: '2026-07-30', format: 'date')]
    public string $requested_delivery_date;

    #[OA\Property(example: 15000.00)]
    public float $total;

    #[OA\Property(example: 'Juan Pérez')]
    public string $customer_name;

    #[OA\Property(example: 'Av. Corrientes 1234')]
    public string $address;

    #[OA\Property(example: 'CABA', nullable: true)]
    public ?string $locality_name;

    #[OA\Property(example: true)]
    public bool $has_coordinates;
}
