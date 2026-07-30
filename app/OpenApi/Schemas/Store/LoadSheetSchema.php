<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'LoadSheet',
    title: 'LoadSheet',
    description: 'Hoja de carga consolidada para picking en depósito'
)]
class LoadSheetSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $route_id;

    #[OA\Property(example: 'planned')]
    public string $status;

    #[OA\Property(example: '2026-07-30', format: 'date')]
    public string $operational_date;

    #[OA\Property(
        type: 'array',
        items: new OA\Items(
            properties: [
                new OA\Property(property: 'product_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440001'),
                new OA\Property(property: 'product_name', type: 'string', example: 'Leche entera 1L'),
                new OA\Property(property: 'total_planned', type: 'integer', example: 30),
                new OA\Property(property: 'total_loaded', type: 'integer', example: 0),
            ],
            type: 'object'
        )
    )]
    public array $by_product;

    #[OA\Property(
        type: 'array',
        items: new OA\Items(
            properties: [
                new OA\Property(property: 'stop_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440002'),
                new OA\Property(property: 'sequence', type: 'integer', example: 1),
                new OA\Property(property: 'order_number', type: 'string', example: 'OP-000001', nullable: true),
                new OA\Property(property: 'customer_name', type: 'string', example: 'Juan Pérez', nullable: true),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'route_stop_item_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440003'),
                        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440001'),
                        new OA\Property(property: 'product_name', type: 'string', example: 'Leche entera 1L'),
                        new OA\Property(property: 'quantity_planned', type: 'integer', example: 10),
                        new OA\Property(property: 'quantity_loaded', type: 'integer', example: 0),
                    ],
                    type: 'object'
                )),
            ],
            type: 'object'
        )
    )]
    public array $by_stop;

    #[OA\Property(example: 30)]
    public int $total_items;
}
