<?php

namespace App\OpenApi\Schemas\Driver;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DriverPaymentMethod',
    title: 'DriverPaymentMethod',
    description: 'Método de pago disponible para el conductor'
)]
class DriverPaymentMethodSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'Transferencia Bco. Chile', nullable: true)]
    public ?string $custom_name;

    #[OA\Property(example: true)]
    public bool $is_enabled;

    #[OA\Property(example: true)]
    public bool $requires_reference;

    #[OA\Property(example: 1)]
    public int $sort_order;

    #[OA\Property(ref: '#/components/schemas/PaymentMethod', nullable: true)]
    public ?object $payment_method;
}
