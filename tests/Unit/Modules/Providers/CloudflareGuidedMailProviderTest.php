<?php

declare(strict_types=1);

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Modules\Providers\Cloudflare\CloudflareDnsService;
use App\Modules\Providers\Cloudflare\CloudflareGuidedMailProvider;
use Illuminate\Http\Client\Request;
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

test('a From address on an unrelated domain fails before calling Cloudflare', function () {
    Http::fake();

    $error = verifyGuidedSend('hello@other.dev');

    expect($error)->toContain('hello@other.dev isn\'t on edge.dply.io')
        ->and($error)->toContain('hello@edge.dply.io');
    Http::assertNothingSent();
});

test('a From address on the parent zone is sent, since the zone can be the onboarded domain', function () {
    Http::fake(['*' => Http::response(['success' => true, 'result' => ['delivered' => ['tj@tjshafer.com']]])]);

    expect(verifyGuidedSend('hello@dply.io'))->toBeNull();
    Http::assertSentCount(1);
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
        ->and($error)->toContain('parent zone is onboarded')
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

/** A site on edge.dply.io whose pinned DNS credential is Cloudflare. */
function subdomainSite(): Site
{
    $site = new Site;
    $site->setRelation('dnsProviderCredential', new ProviderCredential([
        'provider' => 'cloudflare',
        'name' => 'cf',
        'credentials' => ['api_token' => 'dns-token'],
    ]));

    return $site;
}

/**
 * Fake the Cloudflare API for a dply.io zone.
 *
 * @param  list<array<string, mixed>>|null  $subdomains  null = the token may not read them
 * @param  list<array<string, mixed>>  $expected  what the subdomain /dns endpoint returns
 * @param  list<array<string, mixed>>  $records  the zone's DNS records
 */
function fakeSendingZone(?array $subdomains, array $expected = [], array $records = []): void
{
    Http::fake(function (Request $request) use ($subdomains, $expected, $records) {
        $url = urldecode($request->url());

        if (str_contains($url, '/zones?')) {
            $hit = str_contains($url, 'name=dply.io');

            return Http::response(['success' => true, 'result' => $hit ? [['id' => 'z1', 'name' => 'dply.io']] : []]);
        }
        if (str_ends_with(parse_url($url, PHP_URL_PATH), '/dns')) {
            return Http::response(['success' => true, 'result' => $expected]);
        }
        if (str_contains($url, '/email/sending/subdomains')) {
            if ($request->method() === 'POST') {
                return Http::response(['success' => true, 'result' => ['tag' => 't1', 'name' => $request['name'], 'enabled' => true]]);
            }

            return $subdomains === null
                ? Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403)
                : Http::response(['success' => true, 'result' => $subdomains]);
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $result = array_values(array_filter($records, fn (array $r): bool => $r['type'] === ($query['type'] ?? $r['type'])
            && (! isset($query['name']) || $r['name'] === $query['name'])));

        return Http::response(['success' => true, 'result' => $result]);
    });
}

test('a subdomain with no sending entry is reported as not enabled, with its zone', function () {
    fakeSendingZone(subdomains: []);

    $status = (new CloudflareGuidedMailProvider)->pollRecords(subdomainSite(), 'edge.dply.io');

    expect($status->zone)->toBe('dply.io')
        ->and($status->sendingEnabled)->toBeFalse()
        ->and($status->detail)->toBeNull();
});

test('a wildcard entry covers the subdomain and its expected records are checked', function () {
    $txt = static fn (string $name, string $content): array => ['type' => 'TXT', 'name' => $name, 'content' => $content];

    fakeSendingZone(
        subdomains: [['tag' => 't1', 'name' => '*.dply.io', 'enabled' => true]],
        expected: [
            $txt('bounces.edge.dply.io', 'v=spf1 include:_spf.mx.cloudflare.net ~all'),
            $txt('sel1._domainkey.edge.dply.io', 'v=DKIM1; p=abc'),
        ],
        // SPF published, DKIM under the assigned selector missing, DMARC on the apex.
        records: [
            $txt('bounces.edge.dply.io', 'v=spf1 include:_spf.mx.cloudflare.net ~all'),
            $txt('_dmarc.dply.io', 'v=DMARC1; p=reject;'),
        ],
    );

    $status = (new CloudflareGuidedMailProvider)->pollRecords(subdomainSite(), 'edge.dply.io');

    expect($status->sendingEnabled)->toBeTrue()
        ->and($status->spf)->toBeTrue()
        ->and($status->dkim)->toBeFalse()
        ->and($status->dmarc)->toBeTrue();
});

test('an unreadable subdomain list leaves sending unknown instead of failing the check', function () {
    fakeSendingZone(subdomains: null);

    $status = (new CloudflareGuidedMailProvider)->pollRecords(subdomainSite(), 'edge.dply.io');

    expect($status->sendingEnabled)->toBeNull()
        ->and($status->zone)->toBe('dply.io')
        ->and($status->detail)->toBeNull();
});

test('enabling a subdomain creates the entry under its zone', function () {
    fakeSendingZone(subdomains: []);

    expect((new CloudflareGuidedMailProvider)->enableSendingSubdomain(subdomainSite(), 'edge.dply.io'))->toBeNull();

    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST'
        && str_ends_with($r->url(), '/zones/z1/email/sending/subdomains')
        && $r['name'] === 'edge.dply.io');
});

test('a permission failure when enabling names the missing permission and the zone', function () {
    Http::fake(function (Request $request) {
        $url = urldecode($request->url());
        if (str_contains($url, '/zones?')) {
            return Http::response(['success' => true, 'result' => str_contains($url, 'name=dply.io') ? [['id' => 'z1', 'name' => 'dply.io']] : []]);
        }

        return Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403);
    });

    $error = (new CloudflareGuidedMailProvider)->enableSendingSubdomain(subdomainSite(), 'edge.dply.io');

    expect($error)->toContain('Email Sending edit permission')
        ->and($error)->toContain('enabled on dply.io');
});
