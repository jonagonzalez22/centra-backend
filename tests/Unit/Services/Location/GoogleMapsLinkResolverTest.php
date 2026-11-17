<?php

use App\Services\Location\Resolvers\GoogleMapsLinkResolver;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

describe('GoogleMapsLinkResolver', function () {
    beforeEach(function () {
        $this->http = new Factory;
        $this->resolver = new GoogleMapsLinkResolver($this->http);
    });

    it('returns true for maps.google.com URLs', function () {
        expect($this->resolver->canResolve('https://maps.google.com/maps?q=Av.+Colon+77,+Cordoba'))->toBeTrue();
        expect($this->resolver->canResolve('http://maps.google.com/maps/place/Av.+Colon+77'))->toBeFalse();
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
        expect($this->resolver->canResolve('https://www.google.com/maps'))->toBeTrue();
        expect($this->resolver->canResolve('https://yandex.ru/maps/'))->toBeFalse();
        expect($this->resolver->canResolve('https://maps.google.com.evil.test/maps'))->toBeFalse();
        expect($this->resolver->canResolve('https://evilgoo.gl/maps'))->toBeFalse();
    });

    it('returns false for plain text addresses', function () {
        expect($this->resolver->canResolve('Av. Colón 77, Córdoba, Argentina'))->toBeFalse();
        expect($this->resolver->canResolve('-34.6037, -58.3816'))->toBeFalse();
    });

    it('returns false for invalid URLs', function () {
        expect($this->resolver->canResolve('not a url'))->toBeFalse();
        expect($this->resolver->canResolve(''))->toBeFalse();
    });

    it('extracts coordinates from a direct Google Maps URL without a network request', function () {
        $this->http->fake();

        $result = $this->resolver->resolve('https://maps.google.com/maps?q=-34.6037,-58.3816');

        expect($result?->latitude)->toBe(-34.6037)
            ->and($result?->longitude)->toBe(-58.3816)
            ->and($result?->provider)->toBe('google_maps_link');
        $this->http->assertNothingSent();
    });

    it('does not follow a redirect to an untrusted host', function () {
        $this->http->fake([
            'https://maps.app.goo.gl/*' => Factory::response('', 302, ['Location' => 'https://127.0.0.1/private']),
        ]);

        expect($this->resolver->resolve('https://maps.app.goo.gl/abc123'))->toBeNull();
        $this->http->assertSentCount(1);
    });

    it('resolves the reported Google short link through a regional Google Maps destination', function () {
        $shortUrl = 'https://maps.app.goo.gl/9CRq7SnxYwUHRVx26';
        $finalUrl = 'https://www.google.com.ar/maps/place//@-32.8894955,-68.8483749,17z';

        // An explicit callback makes the fixture fail loudly if the resolver
        // changes the URL it requests; an unmatched array fake returns an
        // empty response and can hide that mismatch as a null result.
        $this->http->preventStrayRequests();
        $this->http->fake(function (Request $request) use ($shortUrl, $finalUrl) {
            if ($request->url() === $shortUrl) {
                return Factory::response('', 302, ['Location' => $finalUrl]);
            }

            throw new RuntimeException('Solicitud HTTP no esperada: '.$request->url());
        });

        expect($this->resolver->canResolve($finalUrl))->toBeTrue();

        $result = $this->resolver->resolve($shortUrl);

        expect($result?->latitude)->toBe(-32.8894955)
            ->and($result?->longitude)->toBe(-68.8483749)
            ->and($result?->provider)->toBe('google_maps_link');
        $this->http->assertSent(fn (Request $request) => $request->url() === $shortUrl);
        $this->http->assertSentCount(1);
    });

    it('accepts only Google regional Maps paths, never lookalike hosts', function () {
        expect($this->resolver->canResolve('https://www.google.com.ar/maps/place/foo'))->toBeTrue()
            ->and($this->resolver->canResolve('https://www.google.com.ar/search?q=maps'))->toBeFalse()
            ->and($this->resolver->canResolve('https://www.google.com.ar.evil.test/maps/place/foo'))->toBeFalse()
            ->and($this->resolver->canResolve('https://www.google.com.evil/maps/place/foo'))->toBeFalse();
    });

    it('extracts the textual place from a direct Google Maps link', function () {
        expect(GoogleMapsLinkResolver::addressFromUrl('https://maps.google.com/maps?q=Av.+Col%C3%B3n+77,+C%C3%B3rdoba'))
            ->toBe('Av. Colón 77, Córdoba');
    });
});
