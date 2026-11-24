<?php

namespace App\Services\Geocoding\Providers;

use App\Services\Geocoding\Contracts\GeocodingProvider;
use App\Services\Geocoding\DTOs\GeocodingResult;
use App\Services\Geocoding\Exceptions\GeocodingNotFoundException;
use App\Services\Geocoding\Exceptions\GeocodingProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GoogleGeocodingProvider implements GeocodingProvider
{
    private const BASE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function search(string $address): GeocodingResult
    {
        try {
            $parameters = [
                'address' => $address,
                'key' => config('services.google_maps.api_key'),
            ];

            // `region` is a bias, not a restriction. Only send the Argentine
            // context when the submitted text explicitly identifies Argentina,
            // so foreign addresses and coordinate/link resolvers keep their
            // existing behaviour.
            if ($this->isArgentineAddress($address)) {
                $parameters['region'] = 'ar';
                $parameters['language'] = 'es';
            }

            $response = $this->http()->get(self::BASE_URL, $parameters);
        } catch (\Throwable $exception) {
            throw new GeocodingProviderException('Google Geocoding request failed.', previous: $exception);
        }

        $data = $response->json();

        if ($response->successful() && ($data['status'] ?? null) === 'OK') {
            return $this->selectCandidate($data['results'] ?? [], $address);
        }

        $status = $data['status'] ?? 'UNKNOWN';
        if ($status === 'ZERO_RESULTS') {
            throw new GeocodingNotFoundException('No se encontró una ubicación utilizable para la dirección.');
        }

        throw new GeocodingProviderException("Geocoding failed with status: {$status}");
    }

    /** @param array<int, mixed> $results */
    private function selectCandidate(array $results, string $requestedAddress): GeocodingResult
    {
        $precise = [];
        $review = [];

        foreach ($results as $result) {
            if (! is_array($result) || ! isset($result['geometry']['location']['lat'], $result['geometry']['location']['lng'])) {
                continue;
            }

            $evaluation = $this->evaluateCandidate($result, $requestedAddress);
            if ($evaluation === 'precise') {
                $precise[] = $result;
            } elseif ($evaluation === 'review') {
                $review[] = $result;
            }
        }

        if ($precise !== []) {
            return $this->toResult($precise[0], count($precise) > 1 ? 'review' : 'precise');
        }

        if ($review !== []) {
            return $this->toResult($review[0], 'review');
        }

        throw new GeocodingNotFoundException('No se encontró una ubicación suficientemente precisa para la dirección.');
    }

    /** @param array<string, mixed> $result */
    private function evaluateCandidate(array $result, string $requestedAddress): string
    {
        $types = $result['types'] ?? [];
        $locationType = $result['geometry']['location_type'] ?? null;
        $components = $this->components($result['address_components'] ?? []);
        $requested = $this->requestedParts($requestedAddress);

        if (! is_array($types) || ! isset($components['route']) || $locationType === 'APPROXIMATE') {
            return 'reject';
        }
        if (($requested['number'] ?? null) !== null) {
            if (! isset($components['street_number'])) {
                return 'review';
            }
            if ($this->normalize($components['street_number']) !== $this->normalize($requested['number'])) {
                return 'reject';
            }
        }
        if (($requested['street'] ?? null) !== null && ! $this->sameStreetPart($components['route'], $requested['street'])) {
            return 'reject';
        }
        if (($requested['locality'] ?? null) !== null && isset($components['locality'])
            && ! $this->sameAddressPart($components['locality'], $requested['locality'])) {
            return 'reject';
        }
        if (($result['partial_match'] ?? false) === true || $locationType === 'GEOMETRIC_CENTER') {
            return 'review';
        }

        return in_array($locationType, ['ROOFTOP', 'RANGE_INTERPOLATED'], true) ? 'precise' : 'review';
    }

    /** @param array<int, mixed> $addressComponents @return array<string, string> */
    private function components(array $addressComponents): array
    {
        $components = [];
        foreach ($addressComponents as $component) {
            if (! is_array($component) || ! is_array($component['types'] ?? null) || ! is_string($component['long_name'] ?? null)) {
                continue;
            }
            foreach ($component['types'] as $type) {
                if (is_string($type) && ! isset($components[$type])) {
                    $components[$type] = $component['long_name'];
                }
            }
        }

        return $components;
    }

    /** @return array{street?: string, number?: string, locality?: string} */
    private function requestedParts(string $address): array
    {
        $parts = array_map('trim', explode(',', $address));
        $first = $parts[0] ?? '';
        $result = [];
        if (preg_match('/^(?<street>.+?)\s+(?<number>\d+[[:alnum:]\/-]*)$/u', $first, $matches) === 1) {
            $result['street'] = $matches['street'];
            $result['number'] = $matches['number'];
        } elseif ($first !== '') {
            $result['street'] = $first;
        }
        if (($parts[1] ?? '') !== '') {
            $result['locality'] = $parts[1];
        }

        return $result;
    }

    private function sameAddressPart(string $left, string $right): bool
    {
        $left = $this->normalize($left);
        $right = $this->normalize($right);

        return $left === $right || str_contains($left, $right) || str_contains($right, $left);
    }

    /**
     * Compare street names as complete, canonical tokens. This deliberately
     * avoids substring matching: "Doctor Moreno" must not match an unrelated
     * route which merely contains one of those words.
     */
    private function sameStreetPart(string $left, string $right): bool
    {
        return $this->streetTokens($left) === $this->streetTokens($right);
    }

    /** @return array<int, string> */
    private function streetTokens(string $value): array
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = strtr($normalized, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $tokens = preg_split('/[^[:alnum:]]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_map(
            static fn (string $token): string => $token === 'dr' ? 'doctor' : $token,
            $tokens,
        );
    }

    private function isArgentineAddress(string $address): bool
    {
        $parts = array_map('trim', explode(',', $address));
        $country = $parts[array_key_last($parts)] ?? '';

        return in_array($this->normalize($country), ['argentina', 'ar'], true);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return preg_replace('/[^[:alnum:]]+/u', '', $value) ?? '';
    }

    /** @param array<string, mixed> $result */
    private function toResult(array $result, string $quality): GeocodingResult
    {
        $location = $result['geometry']['location'];

        return new GeocodingResult((float) $location['lat'], (float) $location['lng'], (string) ($result['formatted_address'] ?? ''), 'google', $quality, true);
    }

    private function http(): PendingRequest
    {
        return Http::retry(2, 100)->timeout(10);
    }
}
