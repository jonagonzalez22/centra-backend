<?php

namespace App\Services\Location\Resolvers;

use App\Services\Geocoding\DTOs\GeocodingResult;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

class GoogleMapsLinkResolver extends LocationResolver
{
    public function __construct(
        private readonly ?Factory $httpClient = null,
    ) {}

    private const GOOGLE_MAPS_HOSTS = [
        'maps.google.com',
        'goo.gl',
        'maps.app.goo.gl',
        // These are valid final destinations of Google short links.
        'google.com',
        'www.google.com',
    ];

    // Google Maps short links commonly expand to Google country domains such
    // as www.google.com.ar. This is a hostname grammar for Google-owned
    // registrable domains, not an arbitrary suffix match.
    private const GOOGLE_REGIONAL_HOST_PATTERN = '/^(?:www\.)?google\.(?:com|com\.[a-z]{2}|co\.[a-z]{2}|[a-z]{2})$/';

    private const MAX_REDIRECTS = 3;

    private const COORDINATES_IN_URL_PATTERN = '/@(-?\d+\.?\d*),(-?\d+\.?\d*)/';
    private const COORDINATES_IN_QUERY_PATTERN = '/[?&]q=(-?\d+\.?\d*),(-?\d+\.?\d*)/';
    private const COORDINATES_IN_PATH_PATTERN = '#[/\-](\-?\d{1,3}\.?\d*)[,\s+]+(\-?\d{1,3}\.?\d*)#';
    private const COORDINATES_IN_DATA_PATTERN = '/!3d(-?\d+\.?\d*)!4d(-?\d+\.?\d*)/';

    public function canResolve(string $input): bool
    {
        return self::isSupportedUrl($input);
    }

    public static function isSupportedUrl(string $input): bool
    {
        $parts = parse_url(trim($input));
        if ($parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) && $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        $isKnownHost = in_array($host, self::GOOGLE_MAPS_HOSTS, true);
        $isRegionalGoogleHost = preg_match(self::GOOGLE_REGIONAL_HOST_PATTERN, $host) === 1;

        if (! $isKnownHost && ! $isRegionalGoogleHost) {
            return false;
        }

        // Google search domains are accepted only for their Maps paths.
        return ! ($host === 'google.com' || $host === 'www.google.com' || $isRegionalGoogleHost)
            || str_starts_with($parts['path'] ?? '/', '/maps');
    }

    public function resolve(string $input): ?GeocodingResult
    {
        $input = trim($input);

        if (! $this->canResolve($input)) {
            return null;
        }

        $localResult = $this->extractCoordinatesFromUrl($input);
        if ($localResult !== null) {
            return $localResult;
        }

        $finalUrl = $this->followRedirect($input);

        if ($finalUrl === null) {
            return null;
        }

        return $this->extractCoordinatesFromUrl($finalUrl);
    }

    public static function addressFromUrl(string $input): ?string
    {
        if (! self::isSupportedUrl($input)) {
            return null;
        }

        $query = parse_url($input, PHP_URL_QUERY);
        if (! is_string($query)) {
            return null;
        }
        parse_str($query, $parameters);
        $value = $parameters['q'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function followRedirect(string $url): ?string
    {
        try {
            for ($redirects = 0; $redirects < self::MAX_REDIRECTS; $redirects++) {
                if (! self::isSupportedUrl($url)) {
                    return null;
                }

                $response = $this->http()->timeout(5)->withoutRedirecting()->get($url);

                if (! $response->redirect()) {
                    return $response->successful() ? $url : null;
                }

                $location = $response->header('Location');
                if (! is_string($location) || $location === '') {
                    return null;
                }

                $url = $this->absoluteRedirectUrl($url, $location);
                if ($url === null || ! self::isSupportedUrl($url)) {
                    return null;
                }

                // A short-link Location header often already contains @lat,lng.
                // Resolve it locally instead of making an unnecessary request to
                // the final Google Maps page.
                if ($this->extractCoordinatesFromUrl($url) !== null) {
                    return $url;
                }
            }
        } catch (\Exception) {
            // Network failures are deliberately indistinguishable to callers.
        }

        return null;
    }

    private function absoluteRedirectUrl(string $currentUrl, string $location): ?string
    {
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $location;
        }

        if (! str_starts_with($location, '/')) {
            return null;
        }

        $parts = parse_url($currentUrl);
        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        return 'https://'.$parts['host'].$location;
    }

    private function http(): Factory
    {
        return $this->httpClient ?? Http::getFacadeRoot();
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
