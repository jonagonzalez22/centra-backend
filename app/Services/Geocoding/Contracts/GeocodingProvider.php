<?php

namespace App\Services\Geocoding\Contracts;

use App\Services\Geocoding\DTOs\GeocodingResult;

interface GeocodingProvider
{
    public function search(string $address): GeocodingResult;
}
