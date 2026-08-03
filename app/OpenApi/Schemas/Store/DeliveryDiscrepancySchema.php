<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeliveryDiscrepancy',
    title: 'DeliveryDiscrepancy',
    description: 'Discrepancia de entrega detectada durante la conciliación de una ruta'
)]
class DeliveryDiscrepancySchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440001')]
    public string $route_stop_item_id;

    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440002')]
    public string $product_id;

    #[OA\Property(example: 'Leche entera 1L')]
    public string $product_name;

    #[OA\Property(example: 10)]
    public int $quantity_loaded;

    #[OA\Property(example: 8)]
    public int $quantity_delivered;

    #[OA\Property(example: 2)]
    public int $difference_quantity;

    #[OA\Property(example: 'returned', enum: ['returned', 'pending_redelivery', 'missing', 'damaged', 'rejected_by_customer', 'other'], nullable: true)]
    public ?string $resolution_type;

    #[OA\Property(example: 'El cliente rechazó 2 unidades por vencimiento', nullable: true)]
    public ?string $notes;

    #[OA\Property(format: 'date-time', example: '2026-07-30 12:00:00', nullable: true)]
    public ?string $resolved_at;

    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440003', nullable: true)]
    public ?string $resolved_by;
}
