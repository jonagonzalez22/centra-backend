<?php

namespace App\Services\Location\Resolvers;

use App\Services\Geocoding\DTOs\GeocodingResult;
use App\Services\Location\Contracts\LocationResolverInterface;

abstract class LocationResolver implements LocationResolverInterface
{
    abstract public function canResolve(string $input): bool;

    abstract public function resolve(string $input): ?GeocodingResult;

    protected function createResult(float $latitude, float $longitude, string $formattedAddress, string $provider): GeocodingResult
    {
        return new GeocodingResult(
            latitude: $latitude,
            longitude: $longitude,
            formatted_address: $formattedAddress,
            provider: $provider,
        );
    }
}
