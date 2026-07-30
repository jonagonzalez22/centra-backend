<?php

namespace App\OpenApi\Schemas\Driver;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DriverActiveRoute',
    title: 'DriverActiveRoute',
    description: 'Ruta activa del conductor con vehiculo, conductor y paradas'
)]
class DriverActiveRouteSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'dispatched', enum: ['draft', 'planned', 'dispatched', 'cancelled'])]
    public string $status;

    #[OA\Property(ref: '#/components/schemas/VehicleResource')]
    public object $vehicle;

    #[OA\Property(ref: '#/components/schemas/DriverResource')]
    public object $driver;

    #[OA\Property(
        type: 'array',
        items: new OA\Items(
            properties: [
                new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440010'),
                new OA\Property(property: 'sequence', type: 'integer', example: 1),
                new OA\Property(property: 'status', type: 'string', example: 'pending'),
                new OA\Property(property: 'client', type: 'object', properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Juan Pérez'),
                    new OA\Property(property: 'address', type: 'string', example: 'Av. Corrientes 1234, CABA'),
                ]),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'product_name', type: 'string', example: 'Leche entera 1L'),
                        new OA\Property(property: 'quantity_loaded', type: 'integer', example: 10),
                        new OA\Property(property: 'quantity_delivered', type: 'integer', example: 0),
                    ],
                    type: 'object'
                )),
            ],
            type: 'object'
        )
    )]
    public array $stops;
}
