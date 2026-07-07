<?php

namespace App\Services\Location\Resolvers;

use App\Services\Geocoding\DTOs\GeocodingResult;

class CoordinatesResolver extends LocationResolver
{
    private const COORDINATES_PATTERN = '/^(-?\d{1,3}\.?\d*),\s*(-?\d{1,3}\.?\d*)$/';

    public function canResolve(string $input): bool
    {
        if (! preg_match(self::COORDINATES_PATTERN, trim($input), $matches)) {
            return false;
        }

        $latitude = (float) $matches[1];
        $longitude = (float) $matches[2];

        return $this->isValidLatitude($latitude) && $this->isValidLongitude($longitude);
    }

    public function resolve(string $input): ?GeocodingResult
    {
        if (! $this->canResolve($input)) {
            return null;
        }

        preg_match(self::COORDINATES_PATTERN, trim($input), $matches);

        $latitude = (float) $matches[1];
        $longitude = (float) $matches[2];

        $formattedAddress = sprintf('%.6f, %.6f', $latitude, $longitude);

        return $this->createResult($latitude, $longitude, $formattedAddress, 'coordinates');
    }

    private function isValidLatitude(float $latitude): bool
    {
        return $latitude >= -90 && $latitude <= 90;
    }

    private function isValidLongitude(float $longitude): bool
    {
        return $longitude >= -180 && $longitude <= 180;
    }
}
