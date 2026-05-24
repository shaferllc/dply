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

test('syncCacheRules preserves user rules and replaces dply-managed rules', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/rulesets/phases/http_request_cache_settings/entrypoint' => Http::sequence()
            ->push([
                'success' => true,
                'result' => ['rules' => [
                    ['description' => 'user manual rule', 'expression' => '(http.host eq "x")', 'action' => 'set_cache_settings', 'action_parameters' => ['cache' => false]],
                    ['description' => 'dply-site-S1:0', 'expression' => '(stale)', 'action' => 'set_cache_settings', 'action_parameters' => ['cache' => false]],
                ]],
            ])
            ->push(['success' => true, 'result' => ['id' => 'rs-1']]),
    ]);

    (new CloudflareCdnService('tok'))->syncCacheRules('zone-1', 'app.example.com', [
        ['path' => '/api/', 'action' => 'bypass'],
        ['path' => '/static/', 'action' => 'cache', 'ttl' => 7200],
    ], 'dply-site-S1');

    Http::assertSent(fn ($req) => $req->method() === 'PUT'
        && str_ends_with($req->url(), '/zones/zone-1/rulesets/phases/http_request_cache_settings/entrypoint')
        && collect($req['rules'])->pluck('description')->all() === ['user manual rule', 'dply-site-S1:0', 'dply-site-S1:1']
        && $req['rules'][1]['action_parameters'] === ['cache' => false]
        && $req['rules'][2]['action_parameters']['cache'] === true
        && $req['rules'][2]['action_parameters']['edge_ttl']['default'] === 7200);
});

test('syncCacheRules creates entrypoint when none exists', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/rulesets/phases/http_request_cache_settings/entrypoint' => Http::sequence()
            ->push(['errors' => [['message' => 'not found']]], 404)
            ->push(['success' => true, 'result' => ['id' => 'rs-new']]),
    ]);

    (new CloudflareCdnService('tok'))->syncCacheRules('zone-1', 'app.example.com', [
        ['path' => '/api/', 'action' => 'bypass'],
    ], 'dply-site-S2');

    Http::assertSent(fn ($req) => $req->method() === 'PUT'
        && collect($req['rules'])->pluck('description')->all() === ['dply-site-S2:0']);
});

test('clearManagedCacheRules drops only our rules', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/rulesets/phases/http_request_cache_settings/entrypoint' => Http::sequence()
            ->push([
                'success' => true,
                'result' => ['rules' => [
                    ['description' => 'user rule', 'expression' => '(x)', 'action' => 'set_cache_settings', 'action_parameters' => ['cache' => false]],
                    ['description' => 'dply-site-S3:0', 'expression' => '(y)', 'action' => 'set_cache_settings', 'action_parameters' => ['cache' => false]],
                ]],
            ])
            ->push(['success' => true]),
    ]);

    (new CloudflareCdnService('tok'))->clearManagedCacheRules('zone-1', 'dply-site-S3');

    Http::assertSent(fn ($req) => $req->method() === 'PUT'
        && collect($req['rules'])->pluck('description')->all() === ['user rule']);
});

test('clearManagedCacheRules is a no-op when nothing of ours is present', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/rulesets/phases/http_request_cache_settings/entrypoint' => Http::response([
            'success' => true,
            'result' => ['rules' => [
                ['description' => 'user rule', 'expression' => '(x)', 'action' => 'set_cache_settings', 'action_parameters' => ['cache' => false]],
            ]],
        ]),
    ]);

    (new CloudflareCdnService('tok'))->clearManagedCacheRules('zone-1', 'dply-site-missing');

    Http::assertSentCount(1); // only the GET; no PUT.
});

test('fetches analytics totals and returns a flat snapshot', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/analytics/dashboard*' => Http::response([
            'success' => true,
            'result' => [
                'totals' => [
                    'requests' => ['all' => 1000, 'cached' => 750],
                    'bandwidth' => ['all' => 5_000_000, 'cached' => 3_500_000],
                ],
            ],
        ]),
    ]);

    $snap = (new CloudflareCdnService('tok'))->fetchDashboardAnalytics('zone-1', 60);

    expect($snap)->toMatchArray([
        'requests_all' => 1000,
        'requests_cached' => 750,
        'bandwidth_all' => 5_000_000,
        'bandwidth_cached' => 3_500_000,
        'since_minutes' => 60,
    ]);
    Http::assertSent(fn ($req) => $req->method() === 'GET'
        && str_contains($req->url(), '/zones/zone-1/analytics/dashboard')
        && $req['since'] === '-60');
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
