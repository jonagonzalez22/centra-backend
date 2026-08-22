<?php

namespace App\OpenApi\Schemas\Driver;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AvailableSurplus',
    title: 'AvailableSurplus',
    description: 'Producto con excedente disponible en la ruta'
)]
class AvailableSurplusSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $product_id;

    #[OA\Property(example: 'Leche entera 1L')]
    public string $product_name;

    #[OA\Property(example: 'LECHE001')]
    public string $sku;

    #[OA\Property(format: 'double', example: 150.00)]
    public float $unit_price;

    #[OA\Property(type: 'integer', example: 5)]
    public int $available_quantity;
}
