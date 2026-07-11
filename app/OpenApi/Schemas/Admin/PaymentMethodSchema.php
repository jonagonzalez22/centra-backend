<?php

namespace App\OpenApi\Schemas\Admin;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'PaymentMethod',
    title: 'PaymentMethod',
    description: 'Método de pago global del sistema'
)]
class PaymentMethodSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'Transferencia Bancaria')]
    public string $name;

    #[OA\Property(example: 'transfer')]
    public string $code;

    #[OA\Property(example: 'bank-outline', nullable: true)]
    public ?string $icon;

    #[OA\Property(example: true)]
    public bool $is_active;

    #[OA\Property(example: '2026-07-11T00:00:00.000000Z')]
    public string $created_at;

    #[OA\Property(example: '2026-07-11T00:00:00.000000Z')]
    public string $updated_at;
}
