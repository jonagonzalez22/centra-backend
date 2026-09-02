<?php

namespace App\OpenApi\Schemas\Logistics;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'RejectionReason',
    title: 'RejectionReason',
    description: 'Motivo de rechazo de una entrega'
)]
class RejectionReasonSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'CUSTOMER_NOT_HOME')]
    public string $code;

    #[OA\Property(example: 'Cliente no se encontraba en el domicilio')]
    public string $label;

    #[OA\Property(example: true)]
    public bool $is_active;

    #[OA\Property(example: false, description: 'Solo sugiere el valor inicial de disponibilidad en la UX')]
    public bool $suggest_extra_sale;
}
