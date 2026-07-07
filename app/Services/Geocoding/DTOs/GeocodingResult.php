<?php

namespace App\Services\Geocoding\DTOs;

final readonly class GeocodingResult
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public string $formatted_address,
        public string $provider,
    ) {}
}
