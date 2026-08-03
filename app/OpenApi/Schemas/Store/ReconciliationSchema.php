<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Reconciliation',
    title: 'Reconciliation',
    description: 'Resumen de conciliación de una ruta de entrega'
)]
class ReconciliationSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $route_id;

    #[OA\Property(example: 'dispatched')]
    public string $status;

    #[OA\Property(example: '2026-07-30', format: 'date')]
    public string $operational_date;

    #[OA\Property(
        type: 'object',
        nullable: true,
        properties: [
            new OA\Property(property: 'plate', type: 'string', example: 'ABC123'),
            new OA\Property(property: 'description', type: 'string', example: 'Ford Transit Blanca'),
        ]
    )]
    public ?object $vehicle;

    #[OA\Property(
        type: 'object',
        nullable: true,
        properties: [
            new OA\Property(property: 'name', type: 'string', example: 'Carlos Rodríguez'),
        ]
    )]
    public ?object $driver;

    #[OA\Property(
        type: 'array',
        items: new OA\Items(
            properties: [
                new OA\Property(property: 'stop_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440001'),
                new OA\Property(property: 'sequence', type: 'integer', example: 1),
                new OA\Property(property: 'customer_name', type: 'string', example: 'Juan Pérez'),
                new OA\Property(property: 'address', type: 'string', example: 'Av. Corrientes 1234'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'route_stop_item_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440002'),
                        new OA\Property(property: 'product_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440003'),
                        new OA\Property(property: 'product_name', type: 'string', example: 'Leche entera 1L'),
                        new OA\Property(property: 'quantity_loaded', type: 'integer', example: 10),
                        new OA\Property(property: 'quantity_delivered', type: 'integer', example: 8),
                    ],
                    type: 'object'
                )),
                new OA\Property(property: 'discrepancies', type: 'array', items: new OA\Items(ref: '#/components/schemas/DeliveryDiscrepancy')),
                new OA\Property(property: 'collections', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440004'),
                        new OA\Property(property: 'status', type: 'string', example: 'pending'),
                        new OA\Property(property: 'declared_amount', type: 'number', format: 'float', example: 2500.50),
                    ],
                    type: 'object'
                )),
            ],
            type: 'object'
        )
    )]
    public array $stops;

    #[OA\Property(
        type: 'object',
        properties: [
            new OA\Property(property: 'declared_amount', type: 'number', format: 'float', example: 5000.00),
            new OA\Property(property: 'verified_amount', type: 'number', format: 'float', example: 2500.50),
            new OA\Property(property: 'rejected_amount', type: 'number', format: 'float', example: 2499.50),
        ]
    )]
    public object $totals;

    #[OA\Property(example: true)]
    public bool $can_close;
}
