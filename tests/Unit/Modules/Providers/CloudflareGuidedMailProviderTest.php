<?php

declare(strict_types=1);

use App\Models\Site;
use App\Modules\Providers\Cloudflare\CloudflareDnsService;
use App\Modules\Providers\Cloudflare\CloudflareGuidedMailProvider;
use Illuminate\Support\Facades\Http;

/**
 * Guided Cloudflare email on a subdomain (edge.dply.io in a dply.io zone):
 * verify must name the real problem instead of echoing Cloudflare's opaque
 * `email.sending.error.email.invalid`, and the record pre-flight must look where
 * Cloudflare actually puts records for that subdomain.
 */
function verifyGuidedSend(string $fromAddress): ?string
{
    return (new CloudflareGuidedMailProvider)->verify(
        new Site,
        'edge.dply.io',
        ['account_id' => 'acct', 'key' => 'send-token', 'from_address' => $fromAddress],
        'tj@tjshafer.com',
    )->error;
}

test('a From address on another domain fails before calling Cloudflare', function () {
    Http::fake();

    $error = verifyGuidedSend('hello@dply.io');

    expect($error)->toContain('hello@dply.io isn\'t on edge.dply.io')
        ->and($error)->toContain('hello@edge.dply.io');
    Http::assertNothingSent();
});

test('an unresolved placeholder From address is called out as invalid', function () {
    Http::fake();

    expect(verifyGuidedSend('hello@${APP_DOMAIN}'))->toContain('isn\'t a valid email address');
    Http::assertNothingSent();
});

test('email.invalid is explained with the addresses involved and keeps the raw code', function () {
    Http::fake(['*' => Http::response([
        'success' => false,
        'errors' => [['code' => 10001, 'message' => 'email.sending.error.email.invalid']],
    ], 400)]);

    $error = verifyGuidedSend('hello@edge.dply.io');

    expect($error)->toStartWith('Cloudflare rejected sending from hello@edge.dply.io to tj@tjshafer.com')
        ->and($error)->toContain('subdomain needs its own entry')
        ->and($error)->toEndWith('(email.sending.error.email.invalid)');
});

test('subdomain records are found under cf-bounce, with DMARC inherited from the zone apex', function () {
    Http::fake(function ($request) {
        $url = urldecode($request->url());

        if (str_contains($url, '/zones?')) {
            return Http::response(['success' => true, 'result' => [['id' => 'z1', 'name' => 'dply.io']]]);
        }

        $txt = static fn (string $name, string $content): array => ['type' => 'TXT', 'name' => $name, 'content' => $content];
        $all = [
            $txt('cf-bounce.edge.dply.io', 'v=spf1 include:_spf.mx.cloudflare.net ~all'),
            $txt('cf-bounce._domainkey.edge.dply.io', 'v=DKIM1; p=abc'),
            $txt('_dmarc.dply.io', 'v=DMARC1; p=reject;'),
            // The apex's own DKIM must not count for the subdomain.
            $txt('cf-bounce._domainkey.dply.io', 'v=DKIM1; p=xyz'),
        ];

        preg_match('/[?&]name=([^&]+)/', $url, $m);
        $result = isset($m[1]) ? array_values(array_filter($all, fn ($r) => $r['name'] === $m[1])) : $all;

        return Http::response(['success' => true, 'result' => $result]);
    });

    $status = (new CloudflareGuidedMailProvider)->recordStatus(new CloudflareDnsService('dns-token'), 'dply.io', 'edge.dply.io');

    expect($status->spf)->toBeTrue()
        ->and($status->dkim)->toBeTrue()
        ->and($status->dmarc)->toBeTrue();
});

test('the apex DKIM record does not pass a subdomain that has none', function () {
    Http::fake(function ($request) {
        if (str_contains(urldecode($request->url()), '/zones?')) {
            return Http::response(['success' => true, 'result' => [['id' => 'z1', 'name' => 'dply.io']]]);
        }

        return Http::response(['success' => true, 'result' => [
            ['type' => 'TXT', 'name' => 'cf-bounce._domainkey.dply.io', 'content' => 'v=DKIM1; p=xyz'],
        ]]);
    });

    $status = (new CloudflareGuidedMailProvider)->recordStatus(new CloudflareDnsService('dns-token'), 'dply.io', 'edge.dply.io');

    expect($status->dkim)->toBeFalse();
});
