<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Cloudflare\CloudflareCdnServiceTest;

use App\Services\Cloudflare\CloudflareCdnService;
use Illuminate\Support\Facades\Http;

test('rejects empty token', function () {
    expect(fn () => new CloudflareCdnService(''))
        ->toThrow(\InvalidArgumentException::class);
});

test('finds zone id', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-123', 'name' => 'example.com']],
        ]),
    ]);

    $id = (new CloudflareCdnService('tok'))->findZoneId('example.com');
    expect($id)->toBe('zone-123');
});

test('enable creates proxied A record when none exists', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true,
            'result' => [],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response([
            'success' => true,
            'result' => ['id' => 'rec-new'],
        ]),
    ]);

    $id = (new CloudflareCdnService('tok'))->enableProxyForRecord('zone-1', 'app.example.com', '203.0.113.10');

    expect($id)->toBe('rec-new');
    Http::assertSent(fn ($req) => $req->method() === 'POST'
        && str_ends_with($req->url(), '/zones/zone-1/dns_records')
        && $req['proxied'] === true
        && $req['name'] === 'app.example.com'
        && $req['content'] === '203.0.113.10');
});

test('enable updates existing A record when one exists', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true,
            'result' => [['id' => 'rec-existing', 'type' => 'A', 'name' => 'app.example.com']],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/rec-existing' => Http::response([
            'success' => true,
            'result' => ['id' => 'rec-existing'],
        ]),
    ]);

    $id = (new CloudflareCdnService('tok'))->enableProxyForRecord('zone-1', 'app.example.com', '203.0.113.10');

    expect($id)->toBe('rec-existing');
    Http::assertSent(fn ($req) => $req->method() === 'PUT'
        && str_ends_with($req->url(), '/zones/zone-1/dns_records/rec-existing')
        && $req['proxied'] === true);
});

test('disable flips proxied flag without deleting record', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/rec-1' => Http::response([
            'success' => true,
            'result' => ['id' => 'rec-1'],
        ]),
    ]);

    (new CloudflareCdnService('tok'))->disableProxyForRecord('zone-1', 'rec-1', 'app.example.com', '203.0.113.10');

    Http::assertSent(fn ($req) => $req->method() === 'PUT'
        && str_ends_with($req->url(), '/zones/zone-1/dns_records/rec-1')
        && $req['proxied'] === false);
});

test('apply cache preset patches two zone settings', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/settings/*' => Http::response(['success' => true]),
    ]);

    (new CloudflareCdnService('tok'))->applyCachePreset('zone-1', CloudflareCdnService::PRESET_AGGRESSIVE);

    Http::assertSent(fn ($req) => $req->method() === 'PATCH'
        && str_ends_with($req->url(), '/zones/zone-1/settings/cache_level')
        && $req['value'] === 'aggressive');
    Http::assertSent(fn ($req) => $req->method() === 'PATCH'
        && str_ends_with($req->url(), '/zones/zone-1/settings/browser_cache_ttl')
        && $req['value'] === 14400);
});

test('purge hostname scopes to the host', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/purge_cache' => Http::response(['success' => true]),
    ]);

    (new CloudflareCdnService('tok'))->purgeHostname('zone-1', 'app.example.com');

    Http::assertSent(fn ($req) => $req->method() === 'POST'
        && str_ends_with($req->url(), '/zones/zone-1/purge_cache')
        && $req['hosts'] === ['app.example.com']);
});

test('throws when API reports success false', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/purge_cache' => Http::response([
            'success' => false,
            'errors' => [['message' => 'nope']],
        ], 200),
    ]);

    expect(fn () => (new CloudflareCdnService('tok'))->purgeHostname('zone-1', 'app.example.com'))
        ->toThrow(\RuntimeException::class);
});
