<?php

namespace App\OpenApi\Schemas\Geography;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Province',
    title: 'Province',
    description: 'Provincia de Argentina'
)]
class ProvinceSchema
{
    #[OA\Property(example: '019f1234-5678-90ab-cdef-1234567890ab')]
    public string $id;

    #[OA\Property(example: 'Mendoza')]
    public string $name;

    #[OA\Property(example: 'AR-M')]
    public string $iso_code;

    #[OA\Property(example: '2026-06-29 10:00:00')]
    public string $created_at;

    #[OA\Property(example: '2026-06-29 10:00:00')]
    public string $updated_at;

    #[OA\Property(
        property: 'localities',
        type: 'array',
        items: new OA\Items(ref: '#/components/schemas/Locality')
    )]
    public $localities;

    #[OA\Property(example: 202)]
    public ?int $localities_count;
}
