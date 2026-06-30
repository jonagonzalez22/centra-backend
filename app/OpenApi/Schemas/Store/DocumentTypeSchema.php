<?php

namespace App\OpenApi\Schemas\Store;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DocumentType',
    title: 'DocumentType',
    description: 'Representación de un tipo de documento'
)]
class DocumentTypeSchema
{
    #[OA\Property(format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000')]
    public string $id;

    #[OA\Property(example: 'DNI')]
    public string $code;

    #[OA\Property(example: 'DNI')]
    public string $name;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-06-30 12:00:00')]
    public string $updated_at;
}
