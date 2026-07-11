<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StorePaymentMethod',
    title: 'StorePaymentMethod',
    description: 'Método de pago configurado a nivel tienda (incluye datos del catálogo global)'
)]
class StorePaymentMethodSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440010')]
    public string $id;

    #[OA\Property(example: 'Transferencia Bancaria')]
    public string $name;

    #[OA\Property(example: 'transfer')]
    public string $code;

    #[OA\Property(example: 'bank-outline', nullable: true)]
    public ?string $icon;

    #[OA\Property(example: true)]
    public bool $is_active;

    #[OA\Property(example: true, description: 'Indica si la tienda habilitó este método de pago')]
    public bool $is_enabled;

    #[OA\Property(example: 'Transferencia Bco. Chile', nullable: true)]
    public ?string $custom_name;

    #[OA\Property(example: true, description: 'Indica si el vendedor debe ingresar una referencia al cobrar')]
    public bool $requires_reference;

    #[OA\Property(
        example: '{"bank":"Banco Chile","account_number":"123-456-789","alias":"mi.alias","cuit_rut":"12.345.678-9","holder_name":"Mi Tienda SPA"}',
        nullable: true
    )]
    public ?object $account_details;

    #[OA\Property(example: 1)]
    public int $sort_order;
}
