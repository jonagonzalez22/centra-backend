<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PriceList',
    title: 'PriceList',
    description: 'Lista de precios de una tienda basada en un porcentaje de descuento'
)]
class PriceListSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'Mayorista')]
    public string $name;

    #[OA\Property(type: 'string', format: 'decimal', example: '10.00', minimum: 0, maximum: 100)]
    public string $discount_percentage;

    #[OA\Property(example: true)]
    public bool $is_active;

    #[OA\Property(example: '2026-10-27 12:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-10-27 12:00:00')]
    public string $updated_at;
}
