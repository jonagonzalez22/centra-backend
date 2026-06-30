<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CommercialGroup',
    title: 'CommercialGroup',
    description: 'Representación de un grupo comercial'
)]
class CommercialGroupSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440001')]
    public string $store_id;

    #[OA\Property(example: 'Clientes VIP')]
    public string $name;

    #[OA\Property(example: 'Grupo de clientes con descuentos preferenciales', nullable: true)]
    public ?string $description;

    #[OA\Property(properties: [
        new OA\Property(property: 'discount_percent', type: 'number', example: 10),
    ], nullable: true)]
    public ?object $settings;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $updated_at;
}
