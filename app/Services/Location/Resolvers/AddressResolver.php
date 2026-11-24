<?php

namespace App\Services\Location\Resolvers;

use App\Services\Geocoding\DTOs\GeocodingResult;
use App\Services\Geocoding\Exceptions\GeocodingNotFoundException;
use App\Services\Geocoding\Exceptions\GeocodingProviderException;
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
        $address = AppleMapsLinkResolver::addressFromUrl($input)
            ?? GoogleMapsLinkResolver::addressFromUrl($input)
            ?? trim($input);

        try {
            return $this->geocodingService->search($address);
        } catch (GeocodingNotFoundException|GeocodingProviderException $exception) {
            throw $exception;
        } catch (\RuntimeException) {
            return null;
        }
    }
}
