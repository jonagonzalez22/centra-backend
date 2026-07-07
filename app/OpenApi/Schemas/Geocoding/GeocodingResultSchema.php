<?php

namespace App\OpenApi\Schemas\Geocoding;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'GeocodingResult',
    title: 'GeocodingResult',
    description: 'Resultado de la geocodificación de una dirección'
)]
class GeocodingResultSchema
{
    #[OA\Property(format: 'float', example: -31.4201)]
    public float $latitude;

    #[OA\Property(format: 'float', example: -64.1888)]
    public float $longitude;

    #[OA\Property(type: 'string', example: 'Av. Colón 77, Córdoba, X5000JJB, Argentina')]
    public string $formatted_address;

    #[OA\Property(type: 'string', example: 'google')]
    public string $provider;
}
