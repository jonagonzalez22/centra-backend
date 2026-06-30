<?php

namespace App\OpenApi\Schemas\Geography;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Locality',
    title: 'Locality',
    description: 'Localidad de Argentina'
)]
class LocalitySchema
{
    #[OA\Property(example: '019f1234-5678-90ab-cdef-1234567890ab')]
    public string $id;

    #[OA\Property(example: 'Godoy Cruz')]
    public string $name;

    #[OA\Property(example: '5501', nullable: true)]
    public ?string $zip_code;

    #[OA\Property(example: '2026-06-29 10:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-06-29 10:00:00')]
    public string $updated_at;

    #[OA\Property(
        property: 'province',
        ref: '#/components/schemas/Province'
    )]
    public $province;
}
