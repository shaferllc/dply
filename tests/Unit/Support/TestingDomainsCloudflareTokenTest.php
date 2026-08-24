<?php

declare(strict_types=1);

use App\Support\TestingDomains;

/**
 * One Cloudflare token, one config path.
 *
 * dply used to read four config paths and return the first non-empty one,
 * which meant a correctly-scoped token could lose to a stale CLOUDFLARE_KEY
 * and nothing in the failure said which had been used. These lock the
 * collapsed behaviour in place.
 */
test('the token comes from the single canonical config path', function () {
    config(['testing_domains.cloudflare_api_token' => 'the-one-token']);

    expect(TestingDomains::cloudflareApiToken())->toBe('the-one-token')
        ->and(TestingDomains::cloudflareIsConfigured())->toBeTrue();
});

test('legacy config paths no longer influence the choice', function () {
    config([
        'testing_domains.cloudflare_api_token' => 'the-one-token',
        // These used to outrank it. They must now be inert.
        'services.cloudflare.key' => 'stale-mail-token',
        'edge.cloudflare.api_token' => 'dead-edge-token',
        'serverless.testing_dns.cloudflare_api_token' => 'dead-serverless-token',
    ]);

    expect(TestingDomains::cloudflareApiToken())->toBe('the-one-token');
});

test('an unexpanded env placeholder is treated as no token, not sent as a bearer', function () {
    config(['testing_domains.cloudflare_api_token' => '${CLOUDFLARE_API_TOKEN}']);

    expect(TestingDomains::cloudflareApiToken())->toBe('')
        ->and(TestingDomains::cloudflareIsConfigured())->toBeFalse();
});

test('the zone-scoped accessor returns the same single token', function () {
    config(['testing_domains.cloudflare_api_token' => 'the-one-token']);

    expect(TestingDomains::cloudflareApiTokenForZone('on-dply.cc'))->toBe('the-one-token')
        ->and(TestingDomains::cloudflareApiTokenForZone(''))->toBe('the-one-token');
});

test('the token list contains exactly the one token, or nothing', function () {
    config(['testing_domains.cloudflare_api_token' => 'the-one-token']);
    expect(TestingDomains::cloudflareTokens())->toBe(['the-one-token']);

    config(['testing_domains.cloudflare_api_token' => '']);
    expect(TestingDomains::cloudflareTokens())->toBe([]);
});

test('the configured token is described as the platform token', function () {
    config(['testing_domains.cloudflare_api_token' => 'the-one-token']);

    expect(TestingDomains::describeCloudflareToken('the-one-token'))
        ->toContain('CLOUDFLARE_API_TOKEN');
});

test('any other token is flagged as not the configured one', function () {
    config(['testing_domains.cloudflare_api_token' => 'the-one-token']);

    expect(TestingDomains::describeCloudflareToken('something-else'))
        ->toContain('NOT the configured');
});
