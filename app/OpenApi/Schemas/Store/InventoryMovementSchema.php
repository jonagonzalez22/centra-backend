<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'InventoryMovementResource',
    title: 'InventoryMovementResource',
    description: 'Representación de un movimiento de inventario'
)]
class InventoryMovementSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(type: 'string', enum: ['input', 'output', 'adjustment'], example: 'input')]
    public string $type;

    #[OA\Property(type: 'integer', example: 10)]
    public int $quantity;

    #[OA\Property(type: 'integer', example: 100)]
    public int $previous_stock;

    #[OA\Property(type: 'integer', example: 110)]
    public int $current_stock;

    #[OA\Property(example: 'Carga inicial de stock')]
    public string $concept;

    #[OA\Property(example: '2026-06-21 12:00:00')]
    public string $created_at;

    #[OA\Property(
        type: 'object',
        description: 'Producto asociado al movimiento',
        properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
            new OA\Property(property: 'name', type: 'string', example: 'Pintura Látex Blanca'),
            new OA\Property(property: 'sku', type: 'string', example: 'PNT-LTX-001'),
        ]
    )]
    public object $product;

    #[OA\Property(
        type: 'object',
        description: 'Usuario que realizó el movimiento',
        properties: [
            new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440001'),
            new OA\Property(property: 'name', type: 'string', example: 'Juan Pérez'),
        ]
    )]
    public object $user;
}
