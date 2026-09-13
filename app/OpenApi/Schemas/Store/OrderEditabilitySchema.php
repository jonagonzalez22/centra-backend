<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'OrderEditability',
    title: 'OrderEditability',
    description: 'Snapshot informativo de las restricciones de edición de un pedido'
)]
class OrderEditabilitySchema
{
    #[OA\Property(format: 'uuid')]
    public string $order_id;

    #[OA\Property(example: 'partially_delivered')]
    public string $status;

    #[OA\Property(example: true)]
    public bool $editable;

    #[OA\Property(nullable: true, enum: ['terminal_status', 'active_extra_sale'])]
    public ?string $block_reason;

    #[OA\Property(nullable: true)]
    public ?string $block_message;

    #[OA\Property(example: 6000.00)]
    public float $paid_amount;

    #[OA\Property(example: false)]
    public bool $delivery_date_editable;

    #[OA\Property(
        type: 'array',
        items: new OA\Items(
            properties: [
                new OA\Property(property: 'product_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'product_name', type: 'string', nullable: true),
                new OA\Property(property: 'current_quantity', type: 'integer', example: 10),
                new OA\Property(property: 'delivered_quantity', type: 'integer', example: 4),
                new OA\Property(property: 'active_committed_quantity', type: 'integer', example: 3, description: 'Planificado en rutas draft/planned; cargado en rutas loaded, dispatched o awaiting_reconciliation'),
                new OA\Property(property: 'minimum_quantity', type: 'integer', example: 7),
                new OA\Property(property: 'editable_quantity', type: 'integer', example: 3),
            ],
            type: 'object'
        )
    )]
    public array $items;
}
