<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SkuResponse',
    title: 'Respuesta de generación de SKU',
    type: 'object'
)]
class SkuResponse
{
    #[OA\Property(property: 'status', type: 'string', example: 'success')]
    public string $status;

    #[OA\Property(property: 'message', type: 'string', example: 'SKU generado exitosamente.')]
    public string $message;

    #[OA\Property(property: 'data', type: 'object', ref: '#/components/schemas/SkuData')]
    public object $data;

    #[OA\Property(property: 'errors', type: 'object', nullable: true)]
    public mixed $errors;
}
