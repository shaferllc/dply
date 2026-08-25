<?php

declare(strict_types=1);

use App\Support\TestingDomains;

/**
 * One Cloudflare token, one config path.
 *
 * dply used to read four config paths and return the first non-empty one,
 * which meant the DNS token could lose to the MAIL credential
 * and nothing in the failure said which had been used. These lock the
 * collapsed behaviour in place.
 */
test('the token comes from the single canonical config path', function () {
    config(['services.cloudflare.key' => 'the-one-token']);

    expect(TestingDomains::cloudflareApiToken())->toBe('the-one-token')
        ->and(TestingDomains::cloudflareIsConfigured())->toBeTrue();
});

test('legacy config paths no longer influence the choice', function () {
    config([
        'services.cloudflare.key' => 'the-one-token',
        // These used to outrank it. They must now be inert.
        'testing_domains.cloudflare_api_token' => 'dead-product-token',
        'edge.cloudflare.api_token' => 'dead-edge-token',
        'serverless.testing_dns.cloudflare_api_token' => 'dead-serverless-token',
    ]);

    expect(TestingDomains::cloudflareApiToken())->toBe('the-one-token');
});

test('an unexpanded env placeholder is treated as no token, not sent as a bearer', function () {
    config(['services.cloudflare.key' => '${CLOUDFLARE_DNS_API_TOKEN}']);

    expect(TestingDomains::cloudflareApiToken())->toBe('')
        ->and(TestingDomains::cloudflareIsConfigured())->toBeFalse();
});

test('the zone-scoped accessor returns the same single token', function () {
    config(['services.cloudflare.key' => 'the-one-token']);

    expect(TestingDomains::cloudflareApiTokenForZone('on-dply.cc'))->toBe('the-one-token')
        ->and(TestingDomains::cloudflareApiTokenForZone(''))->toBe('the-one-token');
});

test('the token list contains exactly the one token, or nothing', function () {
    config(['services.cloudflare.key' => 'the-one-token']);
    expect(TestingDomains::cloudflareTokens())->toBe(['the-one-token']);

    config(['services.cloudflare.key' => '']);
    expect(TestingDomains::cloudflareTokens())->toBe([]);
});

test('the configured token is described as the platform token', function () {
    config(['services.cloudflare.key' => 'the-one-token']);

    expect(TestingDomains::describeCloudflareToken('the-one-token'))
        ->toContain('CLOUDFLARE_DNS_API_TOKEN');
});

test('any other token is flagged as not the configured one', function () {
    config(['services.cloudflare.key' => 'the-one-token']);

    expect(TestingDomains::describeCloudflareToken('something-else'))
        ->toContain('NOT the configured');
});

test('DNS and mail are separate env vars with no shared fallback', function () {
    // The original bug: Laravel's MailManager falls back to
    // services.cloudflare.account_id/.key, so one CLOUDFLARE_KEY fed BOTH the
    // Email Sending transport and the DNS client. An Email Sending credential
    // sent to the DNS API authenticates as nobody and lists zero zones.
    $services = file_get_contents(config_path('services.php'));
    $mail = file_get_contents(config_path('mail.php'));

    // DNS reads only its own var.
    expect($services)->toContain("env('CLOUDFLARE_DNS_API_TOKEN')")
        ->and($services)->not->toContain("env('CLOUDFLARE_DNS_API_TOKEN', env('CLOUDFLARE_KEY'))");

    // Mail is pinned in the mailer config so the framework never reaches into
    // services.cloudflare for it.
    expect($mail)->toContain("env('CLOUDFLARE_MAIL_KEY')")
        ->and($mail)->toContain("env('CLOUDFLARE_MAIL_ACCOUNT_ID')");
});
