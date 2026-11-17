<?php

namespace App\Services\Location\Resolvers;

use App\Services\Geocoding\DTOs\GeocodingResult;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/**
 * Resolves the coordinate-bearing variants of Apple Maps share links.
 * Only Apple Maps share hosts are trusted and every redirect is checked manually.
 */
class AppleMapsLinkResolver extends LocationResolver
{
    public function __construct(
        private readonly ?Factory $httpClient = null,
    ) {}

    private const HOST = 'maps.apple.com';
    private const SHORT_HOST = 'maps.apple';
    private const MAX_REDIRECTS = 3;

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
        $path = $parts['path'] ?? '/';

        if ($host === self::SHORT_HOST) {
            // maps.apple is accepted solely for official opaque short links.
            return preg_match('~^/p/[^/?#]+$~', $path) === 1;
        }

        // Direct Apple Maps links use the root query form or /place.
        return $host === self::HOST
            && ($path === '/' || str_starts_with($path, '/place'));
    }

    public function resolve(string $input): ?GeocodingResult
    {
        if (! $this->canResolve($input)) {
            return null;
        }

        $localResult = $this->extractCoordinatesFromUrl($input);
        if ($localResult !== null) {
            return $localResult;
        }

        $finalUrl = $this->followRedirect($input);

        return $finalUrl === null ? null : $this->extractCoordinatesFromUrl($finalUrl);
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
        foreach (['address', 'q', 'name'] as $parameter) {
            $value = $parameters[$parameter] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
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

                // Apple short links commonly expose coordinate=lat,lng in the
                // Location header; resolve it without fetching the destination.
                if ($this->extractCoordinatesFromUrl($url) !== null) {
                    return $url;
                }
            }
        } catch (\Exception) {
            // Do not reveal network details or follow an untrusted destination.
        }

        return null;
    }

    private function http(): Factory
    {
        return $this->httpClient ?? Http::getFacadeRoot();
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

    private function extractCoordinatesFromUrl(string $url): ?GeocodingResult
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query)) {
            return null;
        }

        parse_str($query, $parameters);
        foreach (['ll', 'sll', 'q', 'coordinate'] as $parameter) {
            $value = $parameters[$parameter] ?? null;
            if (! is_string($value)
                || preg_match('/^(-?\d{1,3}\.?\d*),\s*(-?\d{1,3}\.?\d*)$/', $value, $matches) !== 1) {
                continue;
            }

            $latitude = (float) $matches[1];
            $longitude = (float) $matches[2];
            if ($latitude >= -90 && $latitude <= 90 && $longitude >= -180 && $longitude <= 180) {
                return $this->createResult(
                    $latitude,
                    $longitude,
                    sprintf('%.6f, %.6f', $latitude, $longitude),
                    'apple_maps_link',
                );
            }
        }

        return null;
    }
}
