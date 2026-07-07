<?php

namespace App\Services\Location\Resolvers;

use App\Services\Geocoding\DTOs\GeocodingResult;
use Illuminate\Support\Facades\Http;

class GoogleMapsLinkResolver extends LocationResolver
{
    private const GOOGLE_MAPS_DOMAINS = [
        'maps.google.com',
        'goo.gl',
        'maps.app.goo.gl',
    ];

    private const COORDINATES_IN_URL_PATTERN = '/@(-?\d+\.?\d*),(-?\d+\.?\d*)/';
    private const COORDINATES_IN_QUERY_PATTERN = '/[?&]q=(-?\d+\.?\d*),(-?\d+\.?\d*)/';
    private const COORDINATES_IN_PATH_PATTERN = '#[/\-](\-?\d{1,3}\.?\d*)[,\s+]+(\-?\d{1,3}\.?\d*)#';
    private const COORDINATES_IN_DATA_PATTERN = '/!3d(-?\d+\.?\d*)!4d(-?\d+\.?\d*)/';

    public function canResolve(string $input): bool
    {
        $input = trim($input);

        if (! filter_var($input, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = parse_url($input, PHP_URL_HOST);

        if ($host === false) {
            return false;
        }

        foreach (self::GOOGLE_MAPS_DOMAINS as $domain) {
            if (str_ends_with($host, $domain) || $host === $domain) {
                return true;
            }
        }

        return false;
    }

    public function resolve(string $input): ?GeocodingResult
    {
        $input = trim($input);

        if (! $this->canResolve($input)) {
            return null;
        }

        $finalUrl = $this->followRedirect($input);

        if ($finalUrl === null) {
            return null;
        }

        return $this->extractCoordinatesFromUrl($finalUrl);
    }

    private function followRedirect(string $url): ?string
    {
        try {
            $response = Http::timeout(5)
                ->send('GET', $url, ['allow_redirects' => true]);

            return $response->effectiveUri();
        } catch (\Exception) {
            return null;
        }
    }

    private function extractCoordinatesFromUrl(string $url): ?GeocodingResult
    {
        if (preg_match(self::COORDINATES_IN_QUERY_PATTERN, $url, $matches)) {
            $latitude = (float) $matches[1];
            $longitude = (float) $matches[2];

            if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
                $formattedAddress = sprintf('%.6f, %.6f', $latitude, $longitude);

                return $this->createResult($latitude, $longitude, $formattedAddress, 'google_maps_link');
            }
        }

        if (preg_match(self::COORDINATES_IN_URL_PATTERN, $url, $matches)) {
            $latitude = (float) $matches[1];
            $longitude = (float) $matches[2];

            if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
                $formattedAddress = sprintf('%.6f, %.6f', $latitude, $longitude);

                return $this->createResult($latitude, $longitude, $formattedAddress, 'google_maps_link');
            }
        }

        if (preg_match(self::COORDINATES_IN_PATH_PATTERN, $url, $matches)) {
            $latitude = (float) $matches[1];
            $longitude = (float) $matches[2];

            if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
                $formattedAddress = sprintf('%.6f, %.6f', $latitude, $longitude);

                return $this->createResult($latitude, $longitude, $formattedAddress, 'google_maps_link');
            }
        }

        if (preg_match(self::COORDINATES_IN_DATA_PATTERN, $url, $matches)) {
            $latitude = (float) $matches[1];
            $longitude = (float) $matches[2];

            if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
                $formattedAddress = sprintf('%.6f, %.6f', $latitude, $longitude);

                return $this->createResult($latitude, $longitude, $formattedAddress, 'google_maps_link');
            }
        }

        return null;
    }
}
