<?php

namespace App\Services\Location;

use App\Services\Geocoding\DTOs\GeocodingResult;
use App\Services\Location\Contracts\LocationResolverInterface;
use App\Services\Location\Resolvers\AddressResolver;
use App\Services\Location\Resolvers\CoordinatesResolver;
use App\Services\Location\Resolvers\GoogleMapsLinkResolver;

class LocationManager
{
    /** @var array<string, LocationResolverInterface> */
    private array $resolvers = [];

    public function __construct()
    {
        $this->registerDefaultResolvers();
    }

    public function registerResolver(LocationResolverInterface $resolver, string $alias): void
    {
        $this->resolvers[$alias] = $resolver;
    }

    public function resolve(string $input): ?GeocodingResult
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->canResolve($input)) {
                $result = $resolver->resolve($input);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }

    private function registerDefaultResolvers(): void
    {
        $this->resolvers['coordinates'] = new CoordinatesResolver;
        $this->resolvers['google_maps_link'] = new GoogleMapsLinkResolver;
        $this->resolvers['address'] = new AddressResolver(
            app(\App\Services\Geocoding\GeocodingService::class)
        );
    }
}
