<?php

namespace App\Services\Location\Contracts;

use App\Services\Geocoding\DTOs\GeocodingResult;

interface LocationResolverInterface
{
    public function canResolve(string $input): bool;

    public function resolve(string $input): ?GeocodingResult;
}
