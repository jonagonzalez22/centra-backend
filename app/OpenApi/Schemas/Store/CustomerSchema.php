<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Customer',
    title: 'Customer',
    description: 'Representación de un cliente'
)]
class CustomerSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'C-000001')]
    public string $customer_code;

    #[OA\Property(example: 'Juan Pérez')]
    public string $display_name;

    #[OA\Property(example: 'Juan', nullable: true)]
    public ?string $first_name;

    #[OA\Property(example: 'Pérez', nullable: true)]
    public ?string $last_name;

    #[OA\Property(example: 'Pérez S.A.', nullable: true)]
    public ?string $company_name;

    #[OA\Property(ref: '#/components/schemas/DocumentType', nullable: true)]
    public ?object $document_type;

    #[OA\Property(example: '20-12345678-5')]
    public string $document_number;

    #[OA\Property(ref: '#/components/schemas/CommercialGroup', nullable: true)]
    public ?object $commercial_group;

    #[OA\Property(example: 'active', enum: ['active', 'inactive'])]
    public string $status;

    #[OA\Property(example: null, nullable: true)]
    public ?string $blocked_at;

    #[OA\Property(example: 'Cliente frecuente', nullable: true)]
    public ?string $notes;

    #[OA\Property(example: '550e8400-e29b-41d4-a716-446655440002', nullable: true)]
    public ?string $created_by;

    #[OA\Property(example: '550e8400-e29b-41d4-a716-446655440003', nullable: true)]
    public ?string $updated_by;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $updated_at;
}
