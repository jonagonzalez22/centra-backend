<?php

use App\Services\Location\Resolvers\AppleMapsLinkResolver;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

describe('AppleMapsLinkResolver', function () {
    beforeEach(function () {
        $this->http = new Factory;
        $this->resolver = new AppleMapsLinkResolver($this->http);
    });

    it('accepts secure Apple Maps links and extracts coordinate variants locally', function () {
        expect($this->resolver->canResolve('https://maps.apple.com/?ll=-34.6037,-58.3816'))->toBeTrue()
            ->and($this->resolver->canResolve('https://maps.apple.com/?q=-34.6037,-58.3816'))->toBeTrue();

        $this->http->fake();
        $result = $this->resolver->resolve('https://maps.apple.com/?ll=-34.6037,-58.3816');

        expect($result?->latitude)->toBe(-34.6037)
            ->and($result?->longitude)->toBe(-58.3816)
            ->and($result?->provider)->toBe('apple_maps_link');
        $this->http->assertNothingSent();
    });

    it('rejects insecure and lookalike Apple Maps URLs', function () {
        expect($this->resolver->canResolve('http://maps.apple.com/?ll=1,2'))->toBeFalse()
            ->and($this->resolver->canResolve('https://maps.apple.com.evil.test/?ll=1,2'))->toBeFalse()
            ->and($this->resolver->canResolve('https://maps.apple.evil.test/p/token'))->toBeFalse()
            ->and($this->resolver->canResolve('https://maps.apple/not-a-short-link'))->toBeFalse()
            ->and($this->resolver->canResolve('https://127.0.0.1/?ll=1,2'))->toBeFalse();
    });

    it('does not follow redirects outside Apple Maps', function () {
        $this->http->fake([
            'https://maps.apple.com/*' => Factory::response('', 302, ['Location' => 'https://10.0.0.1/internal']),
        ]);

        expect($this->resolver->resolve('https://maps.apple.com/?q=place'))->toBeNull();
        $this->http->assertSentCount(1);
    });

    it('expands an official Apple short link and extracts the Location coordinate locally', function () {
        $shortUrl = 'https://maps.apple/p/ILVtVVY7vHAiJi';
        $finalUrl = 'https://maps.apple.com/place?coordinate=-32.854738,-68.842072&name=Patricias%20Mendocinas%20624&map=explore';
        $this->http->preventStrayRequests();
        $this->http->fake(function (Request $request) use ($shortUrl, $finalUrl) {
            if ($request->url() === $shortUrl) {
                return Factory::response('', 301, ['Location' => $finalUrl]);
            }

            throw new RuntimeException('Solicitud HTTP no esperada: '.$request->url());
        });

        expect($this->resolver->canResolve($finalUrl))->toBeTrue();

        $result = $this->resolver->resolve($shortUrl);

        expect($result?->latitude)->toBe(-32.854738)
            ->and($result?->longitude)->toBe(-68.842072)
            ->and($result?->provider)->toBe('apple_maps_link');
        $this->http->assertSent(fn (Request $request) => $request->url() === $shortUrl);
        $this->http->assertSentCount(1);
    });

    it('extracts Apple Maps textual location variants for fixed-provider geocoding', function () {
        expect(AppleMapsLinkResolver::addressFromUrl('https://maps.apple.com/?address=1+Infinite+Loop,+Cupertino'))
            ->toBe('1 Infinite Loop, Cupertino')
            ->and(AppleMapsLinkResolver::addressFromUrl('https://maps.apple.com/?q=Plaza+Independencia,+Mendoza'))
            ->toBe('Plaza Independencia, Mendoza');
    });
});
