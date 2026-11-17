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
            // A map URL with a textual place is sent to the fixed geocoding
            // provider as its extracted address, never as an arbitrary URL.
            $address = AppleMapsLinkResolver::addressFromUrl($input)
                ?? GoogleMapsLinkResolver::addressFromUrl($input)
                ?? trim($input);

            return $this->geocodingService->search($address);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
