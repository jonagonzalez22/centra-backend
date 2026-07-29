<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RouteStopItem',
    title: 'RouteStopItem',
    description: 'Representación de un item asignado a una parada de ruta'
)]
class RouteStopItemSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440001')]
    public string $product_id;

    #[OA\Property(example: 'Leche entera 1L')]
    public string $product_name;

    #[OA\Property(example: 10)]
    public int $quantity_planned;

    #[OA\Property(example: 10)]
    public int $quantity_loaded;

    #[OA\Property(example: 0)]
    public int $quantity_delivered;
}
