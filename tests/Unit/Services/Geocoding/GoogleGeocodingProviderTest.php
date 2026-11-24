<?php

use App\Services\Geocoding\Exceptions\GeocodingNotFoundException;
use App\Services\Geocoding\Exceptions\GeocodingProviderException;
use App\Services\Geocoding\Providers\GoogleGeocodingProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

function googleResult(array $overrides = []): array
{
    return array_replace_recursive([
        'formatted_address' => 'Portugal 1570, Godoy Cruz, Mendoza, Argentina',
        'geometry' => ['location' => ['lat' => -32.9, 'lng' => -68.8], 'location_type' => 'ROOFTOP'],
        'types' => ['street_address'],
        'address_components' => [
            ['long_name' => '1570', 'types' => ['street_number']],
            ['long_name' => 'Portugal', 'types' => ['route']],
            ['long_name' => 'Godoy Cruz', 'types' => ['locality', 'political']],
        ],
    ], $overrides);
}

function fakeGoogle(array $body, int $status = 200): void
{
    Http::fake(['https://maps.googleapis.com/maps/api/geocode/json*' => Http::response($body, $status)]);
}

function googleRequestQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

test('accepts a compatible rooftop result', function () {
    fakeGoogle(['status' => 'OK', 'results' => [googleResult()]]);
    $result = (new GoogleGeocodingProvider)->search('Portugal 1570, Godoy Cruz, Mendoza, Argentina');
    expect($result->quality)->toBe('precise')->and($result->latitude)->toBe(-32.9);
});

test('accepts Sarmiento 1188 as a compatible rooftop result', function () {
    fakeGoogle(['status' => 'OK', 'results' => [googleResult([
        'formatted_address' => 'Sarmiento 1188, Las Heras, Mendoza, Argentina',
        'address_components' => [
            ['long_name' => '1188', 'types' => ['street_number']],
            ['long_name' => 'Sarmiento', 'types' => ['route']],
            ['long_name' => 'Las Heras', 'types' => ['locality']],
        ],
    ])]]);

    $result = (new GoogleGeocodingProvider)->search('Sarmiento 1188, Las Heras, Mendoza, Argentina');

    expect($result->quality)->toBe('precise');
});

test('adds Argentine regional context to an explicitly Argentine address', function () {
    fakeGoogle(['status' => 'OK', 'results' => [googleResult()]]);

    (new GoogleGeocodingProvider)->search('Portugal 1570, Godoy Cruz, Mendoza, Argentina');

    Http::assertSent(function (Request $request): bool {
        $query = googleRequestQuery($request);

        return $request->url() !== ''
            && ($query['address'] ?? null) === 'Portugal 1570, Godoy Cruz, Mendoza, Argentina'
            && ($query['region'] ?? null) === 'ar'
            && ($query['language'] ?? null) === 'es'
            && isset($query['key']);
    });
});

test('does not apply Argentine regional context to a foreign address', function () {
    fakeGoogle(['status' => 'OK', 'results' => [googleResult([
        'formatted_address' => '1600 Amphitheatre Parkway, Mountain View, CA, USA',
        'address_components' => [
            ['long_name' => '1600', 'types' => ['street_number']],
            ['long_name' => 'Amphitheatre Parkway', 'types' => ['route']],
            ['long_name' => 'Mountain View', 'types' => ['locality']],
            ['long_name' => 'United States', 'types' => ['country']],
        ],
    ])]]);

    (new GoogleGeocodingProvider)->search('Amphitheatre Parkway 1600, Mountain View, California, United States');

    Http::assertSent(function (Request $request): bool {
        $query = googleRequestQuery($request);

        return ! isset($query['region']) && ! isset($query['language']);
    });
});

test('accepts compatible interpolation but sends it to confirmation', function () {
    fakeGoogle(['status' => 'OK', 'results' => [googleResult(['geometry' => ['location_type' => 'RANGE_INTERPOLATED']])]]);
    expect((new GoogleGeocodingProvider)->search('Portugal 1570, Godoy Cruz, Mendoza, Argentina')->quality)->toBe('precise');
});

test('requires review for incomplete interpolation and partial match', function (array $overrides) {
    fakeGoogle(['status' => 'OK', 'results' => [googleResult($overrides)]]);
    expect((new GoogleGeocodingProvider)->search('Portugal 1570, Godoy Cruz, Mendoza, Argentina')->quality)->toBe('review');
})->with([
    [['geometry' => ['location_type' => 'RANGE_INTERPOLATED'], 'address_components' => [['long_name' => 'Portugal', 'types' => ['route']]]]],
    [['partial_match' => true]],
    [['geometry' => ['location_type' => 'GEOMETRIC_CENTER']]],
]);

test('treats Doctor and Dr street tokens as equivalent', function (string $requestedStreet) {
    fakeGoogle(['status' => 'OK', 'results' => [googleResult([
        'formatted_address' => 'Dr. Moreno 2564, Las Heras, Mendoza, Argentina',
        'address_components' => [
            ['long_name' => '2564', 'types' => ['street_number']],
            ['long_name' => 'Doctor Moreno', 'types' => ['route']],
            ['long_name' => 'Las Heras', 'types' => ['locality']],
        ],
    ])]]);

    $result = (new GoogleGeocodingProvider)->search("{$requestedStreet} 2564, Las Heras, Mendoza, Argentina");

    expect($result->quality)->toBe('precise');
})->with(['Dr. Moreno', 'Dr Moreno', 'Doctor Moreno']);

test('continues rejecting genuinely different street names', function () {
    fakeGoogle(['status' => 'OK', 'results' => [googleResult([
        'address_components' => [
            ['long_name' => '2564', 'types' => ['street_number']],
            ['long_name' => 'Doctor Moreno', 'types' => ['route']],
            ['long_name' => 'Las Heras', 'types' => ['locality']],
        ],
    ])]]);

    expect(fn () => (new GoogleGeocodingProvider)->search('Moreno Sur 2564, Las Heras, Mendoza, Argentina'))
        ->toThrow(GeocodingNotFoundException::class);
});

test('rejects locality only approximate and incompatible candidates', function (array $result) {
    fakeGoogle(['status' => 'OK', 'results' => [$result]]);
    (new GoogleGeocodingProvider)->search('Portugal 1570, Godoy Cruz, Mendoza, Argentina');
})->with([
    [[['formatted_address' => 'Godoy Cruz, Mendoza, Argentina', 'geometry' => ['location' => ['lat' => -32.92, 'lng' => -68.85], 'location_type' => 'APPROXIMATE'], 'types' => ['locality', 'political'], 'address_components' => [['long_name' => 'Godoy Cruz', 'types' => ['locality']]]]]],
    [[googleResult(['geometry' => ['location_type' => 'APPROXIMATE']])]],
    [[googleResult(['address_components' => [['long_name' => '1571', 'types' => ['street_number']], ['long_name' => 'Portugal', 'types' => ['route']]]])]],
    [[googleResult(['address_components' => [['long_name' => '1570', 'types' => ['street_number']], ['long_name' => 'Otra Calle', 'types' => ['route']]]])]],
])->throws(GeocodingNotFoundException::class);

test('marks multiple exact candidates for review', function () {
    fakeGoogle(['status' => 'OK', 'results' => [googleResult(), googleResult(['formatted_address' => 'Portugal 1570 bis, Godoy Cruz, Mendoza, Argentina'])]]);
    expect((new GoogleGeocodingProvider)->search('Portugal 1570, Godoy Cruz, Mendoza, Argentina')->quality)->toBe('review');
});

test('handles zero results', function () {
    fakeGoogle(['status' => 'ZERO_RESULTS']);
    expect(fn () => (new GoogleGeocodingProvider)->search('Inexistente 1, Mendoza'))->toThrow(GeocodingNotFoundException::class);
});

test('handles provider failures', function () {
    fakeGoogle(['status' => 'OVER_QUERY_LIMIT']);
    expect(fn () => (new GoogleGeocodingProvider)->search('Portugal 1570, Godoy Cruz, Mendoza, Argentina'))->toThrow(GeocodingProviderException::class);
});

test('handles provider timeouts', function () {
    Http::fake(['https://maps.googleapis.com/maps/api/geocode/json*' => Http::failedConnection()]);
    expect(fn () => (new GoogleGeocodingProvider)->search('Portugal 1570, Godoy Cruz, Mendoza, Argentina'))->toThrow(GeocodingProviderException::class);
});
