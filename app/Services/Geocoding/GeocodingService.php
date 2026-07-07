<?php

namespace App\Services\Geocoding;

use App\Services\Geocoding\Contracts\GeocodingProvider;
use App\Services\Geocoding\DTOs\GeocodingResult;

class GeocodingService
{
    public function __construct(
        private readonly GeocodingProvider $provider,
    ) {}

    public function search(string $address): GeocodingResult
    {
        return $this->provider->search($address);
    }
}
