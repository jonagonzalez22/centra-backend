<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CategoryResource',
    title: 'CategoryResource',
    description: 'Representación de una categoría de tienda'
)]
class CategorySchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'Electrónica')]
    public string $name;

    #[OA\Property(example: 'Productos electrónicos y gadgets', nullable: true)]
    public ?string $description;

    #[OA\Property(example: true)]
    public bool $is_active;

    #[OA\Property(example: '2026-06-18 12:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-06-18 12:00:00')]
    public string $updated_at;

    #[OA\Property(ref: '#/components/schemas/StoreLight', description: 'Tienda a la que pertenece', nullable: true)]
    public ?object $store;
}
