<?php

use App\Services\Location\Resolvers\CoordinatesResolver;
use App\Services\Geocoding\DTOs\GeocodingResult;

describe('CoordinatesResolver', function () {
    beforeEach(function () {
        $this->resolver = new CoordinatesResolver();
    });

    it('returns true for valid coordinates format lat,lng', function () {
        expect($this->resolver->canResolve('-34.6037, -58.3816'))->toBeTrue();
        expect($this->resolver->canResolve('40.7128, -74.0060'))->toBeTrue();
        expect($this->resolver->canResolve('51.5074, -0.1278'))->toBeTrue();
        expect($this->resolver->canResolve('-33.8688, 151.2093'))->toBeTrue();
    });

    it('returns true for coordinates without spaces', function () {
        expect($this->resolver->canResolve('-34.6037,-58.3816'))->toBeTrue();
        expect($this->resolver->canResolve('40.7128,-74.0060'))->toBeTrue();
    });

    it('returns true for negative coordinates', function () {
        expect($this->resolver->canResolve('-90,-180'))->toBeTrue();
        expect($this->resolver->canResolve('90,180'))->toBeTrue();
    });

    it('returns false for invalid latitude (out of range)', function () {
        expect($this->resolver->canResolve('91, -58.3816'))->toBeFalse();
        expect($this->resolver->canResolve('-91, -58.3816'))->toBeFalse();
    });

    it('returns false for invalid longitude (out of range)', function () {
        expect($this->resolver->canResolve('-34.6037, 181'))->toBeFalse();
        expect($this->resolver->canResolve('-34.6037, -181'))->toBeFalse();
    });

    it('returns false for non-coordinate strings', function () {
        expect($this->resolver->canResolve('Av. Corrientes 1000'))->toBeFalse();
        expect($this->resolver->canResolve('Buenos Aires, Argentina'))->toBeFalse();
        expect($this->resolver->canResolve(''))->toBeFalse();
        expect($this->resolver->canResolve('not coordinates'))->toBeFalse();
    });

    it('returns false for invalid format', function () {
        expect($this->resolver->canResolve('-34.6037'))->toBeFalse();
        expect($this->resolver->canResolve('lat: -34.6037, lng: -58.3816'))->toBeFalse();
        expect($this->resolver->canResolve('-34.6037, -58.3816, -34.6037'))->toBeFalse();
    });

    it('resolves valid coordinates and returns GeocodingResult', function () {
        $result = $this->resolver->resolve('-34.6037, -58.3816');

        expect($result)->toBeInstanceOf(GeocodingResult::class);
        expect($result->latitude)->toBe(-34.6037);
        expect($result->longitude)->toBe(-58.3816);
        expect($result->provider)->toBe('coordinates');
        expect($result->formatted_address)->toBe('-34.603700, -58.381600');
    });

    it('resolves coordinates without spaces', function () {
        $result = $this->resolver->resolve('40.7128,-74.0060');

        expect($result)->toBeInstanceOf(GeocodingResult::class);
        expect($result->latitude)->toBe(40.7128);
        expect($result->longitude)->toBe(-74.0060);
    });

    it('resolves integer coordinates', function () {
        $result = $this->resolver->resolve('40, -74');

        expect($result)->toBeInstanceOf(GeocodingResult::class);
        expect($result->latitude)->toBe(40.0);
        expect($result->longitude)->toBe(-74.0);
    });

    it('returns null for invalid coordinates', function () {
        expect($this->resolver->resolve('not valid'))->toBeNull();
    });

    it('trims whitespace from input', function () {
        $result = $this->resolver->resolve('  -34.6037, -58.3816  ');

        expect($result)->toBeInstanceOf(GeocodingResult::class);
        expect($result->latitude)->toBe(-34.6037);
    });
});
