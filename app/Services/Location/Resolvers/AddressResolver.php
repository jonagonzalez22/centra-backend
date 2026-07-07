<?php

namespace App\Services\Location\Resolvers;

use App\Services\Geocoding\DTOs\GeocodingResult;
use App\Services\Geocoding\GeocodingService;

class AddressResolver extends LocationResolver
{
    public function __construct(
        private readonly GeocodingService $geocodingService,
    ) {}

    public function canResolve(string $input): bool
    {
        return true;
    }

    public function resolve(string $input): ?GeocodingResult
    {
        try {
            return $this->geocodingService->search(trim($input));
        } catch (\RuntimeException) {
            return null;
        }
    }
}
