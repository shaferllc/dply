<?php

declare(strict_types=1);

use App\Support\TestingDomains;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Zone-aware Cloudflare token selection.
 *
 * The bug this guards: the first configured token is CLOUDFLARE_KEY, which was
 * historically provisioned for the MAIL transport and often belongs to a
 * different Cloudflare account than the one owning the testing zones. Picking
 * by position produced "Zone [on-dply.cc] was not found in this Cloudflare
 * account" on an otherwise correct setup.
 */
beforeEach(function () {
    Cache::flush();

    config([
        // Deliberately the WRONG account, and deliberately first.
        'services.cloudflare.key' => 'mail-token',
        'testing_domains.cloudflare_api_token' => 'zone-owner-token',
        'edge.cloudflare.api_token' => '',
        'serverless.testing_dns.cloudflare_api_token' => '',
    ]);
});

/** Only `zone-owner-token` can see on-dply.cc. */
function fakeCloudflareZoneLookup(): void
{
    Http::fake(function ($request) {
        $ownsZone = $request->hasHeader('Authorization', 'Bearer zone-owner-token');

        return Http::response([
            'success' => true,
            'result' => $ownsZone
                ? [['id' => 'zone123', 'name' => 'on-dply.cc', 'status' => 'active']]
                : [],
        ]);
    });
}

test('the token that can see the zone wins over the one that merely comes first', function () {
    fakeCloudflareZoneLookup();

    expect(TestingDomains::cloudflareApiTokenForZone('on-dply.cc'))->toBe('zone-owner-token');
});

test('the blind accessor still returns the first token — which is the bug being routed around', function () {
    fakeCloudflareZoneLookup();

    expect(TestingDomains::cloudflareApiToken())->toBe('mail-token');
});

test('the decision is cached, so provisioning does not re-probe every token', function () {
    fakeCloudflareZoneLookup();

    TestingDomains::cloudflareApiTokenForZone('on-dply.cc');
    $afterFirst = count(Http::recorded());

    TestingDomains::cloudflareApiTokenForZone('on-dply.cc');

    expect(count(Http::recorded()))->toBe($afterFirst)
        ->and($afterFirst)->toBeGreaterThan(0);
});

test('clearing the cache makes it probe again', function () {
    fakeCloudflareZoneLookup();

    TestingDomains::cloudflareApiTokenForZone('on-dply.cc');
    $afterFirst = count(Http::recorded());

    TestingDomains::forgetCloudflareTokenForZone('on-dply.cc');
    TestingDomains::cloudflareApiTokenForZone('on-dply.cc');

    expect(count(Http::recorded()))->toBeGreaterThan($afterFirst);
});

test('when no token can see the zone it falls back to the first, so the caller gets a real API error', function () {
    Http::fake(fn () => Http::response(['success' => true, 'result' => []]));

    // Not silently empty: an empty token would surface as a confusing
    // "no token configured" instead of Cloudflare's own zone-not-found.
    expect(TestingDomains::cloudflareApiTokenForZone('on-dply.cc'))->toBe('mail-token');
});

test('a blank zone short-circuits without any API call', function () {
    Http::fake();

    expect(TestingDomains::cloudflareApiTokenForZone(''))->toBe('mail-token');

    Http::assertNothingSent();
});
