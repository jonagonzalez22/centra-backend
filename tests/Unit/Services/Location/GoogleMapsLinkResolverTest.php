<?php

use App\Services\Location\Resolvers\GoogleMapsLinkResolver;

describe('GoogleMapsLinkResolver', function () {
    beforeEach(function () {
        $this->resolver = new GoogleMapsLinkResolver();
    });

    it('returns true for maps.google.com URLs', function () {
        expect($this->resolver->canResolve('https://maps.google.com/maps?q=Av.+Colon+77,+Cordoba'))->toBeTrue();
        expect($this->resolver->canResolve('http://maps.google.com/maps/place/Av.+Colon+77'))->toBeTrue();
    });

    it('returns true for goo.gl short URLs', function () {
        expect($this->resolver->canResolve('https://goo.gl/maps/abc123'))->toBeTrue();
        expect($this->resolver->canResolve('https://goo.gl/xyz789'))->toBeTrue();
    });

    it('returns true for maps.app.goo.gl URLs (Google Maps app sharing)', function () {
        expect($this->resolver->canResolve('https://maps.app.goo.gl/abc123'))->toBeTrue();
        expect($this->resolver->canResolve('https://maps.app.goo.gl/xyz789'))->toBeTrue();
    });

    it('returns false for non-Google Maps URLs', function () {
        expect($this->resolver->canResolve('https://www.openstreetmap.org/'))->toBeFalse();
        expect($this->resolver->canResolve('https://www.google.com/maps'))->toBeFalse();
        expect($this->resolver->canResolve('https://yandex.ru/maps/'))->toBeFalse();
    });

    it('returns false for plain text addresses', function () {
        expect($this->resolver->canResolve('Av. Colón 77, Córdoba, Argentina'))->toBeFalse();
        expect($this->resolver->canResolve('-34.6037, -58.3816'))->toBeFalse();
    });

    it('returns false for invalid URLs', function () {
        expect($this->resolver->canResolve('not a url'))->toBeFalse();
        expect($this->resolver->canResolve(''))->toBeFalse();
    });
});
