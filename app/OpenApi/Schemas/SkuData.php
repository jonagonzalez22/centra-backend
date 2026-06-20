<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SkuData',
    title: 'Datos del SKU generado',
    type: 'object'
)]
class SkuData
{
    #[OA\Property(
        property: 'sku',
        type: 'string',
        example: 'FERR-000015'
    )]
    public string $sku;
}
