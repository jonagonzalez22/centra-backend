<?php

namespace App\Services\Geocoding\Providers;

use App\Services\Geocoding\Contracts\GeocodingProvider;
use App\Services\Geocoding\DTOs\GeocodingResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class GoogleGeocodingProvider implements GeocodingProvider
{
    private const BASE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

    public function search(string $address): GeocodingResult
    {
        $response = $this->http()->get(self::BASE_URL, [
            'address' => $address,
            'key' => config('services.google_maps.api_key'),
        ]);

        $data = $response->json();

        if ($response->successful() && ($data['status'] ?? null) === 'OK') {
            $result = $data['results'][0] ?? null;

            if ($result) {
                $location = $result['geometry']['location'];

                return new GeocodingResult(
                    latitude: (float) $location['lat'],
                    longitude: (float) $location['lng'],
                    formatted_address: $result['formatted_address'],
                    provider: 'google',
                );
            }
        }

        $status = $data['status'] ?? 'UNKNOWN';
        $errorMessage = $data['error_message'] ?? "Geocoding failed with status: {$status}";

        throw new \RuntimeException($errorMessage);
    }

    private function http(): PendingRequest
    {
        return Http::retry(2, 100)->timeout(10);
    }
}
