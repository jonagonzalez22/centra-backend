<?php

namespace App\OpenApi\Schemas\Driver;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ExtraSaleItem',
    title: 'ExtraSaleItem',
    description: 'Item de venta extra agregado a una parada'
)]
class ExtraSaleItemSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440001')]
    public string $product_id;

    #[OA\Property(example: 'Leche entera 1L')]
    public string $product_name;

    #[OA\Property(type: 'integer', example: 3)]
    public int $quantity_planned;

    #[OA\Property(type: 'integer', example: 3)]
    public int $quantity_loaded;

    #[OA\Property(type: 'integer', example: 3)]
    public int $quantity_delivered;

    #[OA\Property(type: 'boolean', example: true)]
    public bool $is_extra;
}
