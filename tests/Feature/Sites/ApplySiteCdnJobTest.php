<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\ApplySiteCdnJobTest;

use App\Jobs\ApplySiteCdnJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * @return array{0: Site, 1: ProviderCredential}
 */
function seedCdnSite(array $cdnOverrides = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ip_address' => '203.0.113.10',
    ]);
    $credential = ProviderCredential::query()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'cloudflare',
        'name' => 'CF',
        'credentials' => ['api_token' => 'tok'],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'meta' => [
            'cdn' => array_merge([
                'enabled' => true,
                'provider' => 'cloudflare',
                'credential_id' => $credential->id,
                'zone_name' => 'example.com',
                'hostname' => 'app.example.com',
                'origin_ip' => '203.0.113.10',
                'cache_preset' => 'standard',
            ], $cdnOverrides),
        ],
    ]);

    return [$site, $credential];
}

test('enable flow resolves zone, creates proxied record, persists record id', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => Http::sequence()
            ->push(['success' => true, 'result' => [['id' => 'zone-1', 'name' => 'example.com']]]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true, 'result' => [],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response([
            'success' => true, 'result' => ['id' => 'rec-new'],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/settings/*' => Http::response(['success' => true]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/rulesets/phases/http_request_cache_settings/entrypoint' => Http::response([
            'success' => true, 'result' => ['rules' => []],
        ]),
    ]);

    [$site] = seedCdnSite();
    (new ApplySiteCdnJob($site->id))->handle();

    $fresh = $site->fresh();
    expect($fresh->meta['cdn']['zone_id'] ?? null)->toBe('zone-1');
    expect($fresh->meta['cdn']['record_id'] ?? null)->toBe('rec-new');
    expect($fresh->meta['cdn']['last_error'])->toBeNull();
    expect($fresh->meta['cdn']['last_applied_at'] ?? null)->not->toBeNull();
});

test('disable flow flips proxied flag using cached zone and record id', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/rec-1' => Http::response([
            'success' => true, 'result' => ['id' => 'rec-1'],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/rulesets/phases/http_request_cache_settings/entrypoint' => Http::response([
            'success' => true, 'result' => ['rules' => []],
        ]),
    ]);

    [$site] = seedCdnSite([
        'enabled' => false,
        'zone_id' => 'zone-1',
        'record_id' => 'rec-1',
    ]);

    (new ApplySiteCdnJob($site->id))->handle();

    Http::assertSent(fn ($req) => $req->method() === 'PUT'
        && str_ends_with($req->url(), '/zones/zone-1/dns_records/rec-1')
        && $req['proxied'] === false);

    $fresh = $site->fresh();
    expect($fresh->meta['cdn']['last_error'])->toBeNull();
});

test('no-op when cdn config missing', function () {
    Http::fake();
    [$site] = seedCdnSite();
    $site->meta = [];
    $site->save();

    (new ApplySiteCdnJob($site->id))->handle();

    Http::assertNothingSent();
});

test('enable flow pushes path rules to the cache ruleset', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true, 'result' => [['id' => 'zone-1']],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true, 'result' => [],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records' => Http::response([
            'success' => true, 'result' => ['id' => 'rec-1'],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/settings/*' => Http::response(['success' => true]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/rulesets/phases/http_request_cache_settings/entrypoint' => Http::sequence()
            ->push(['success' => true, 'result' => ['rules' => []]])
            ->push(['success' => true, 'result' => ['id' => 'rs-1']]),
    ]);

    [$site] = seedCdnSite([
        'rules' => [
            ['path' => '/api/', 'action' => 'bypass'],
            ['path' => '/static/', 'action' => 'cache', 'ttl' => 7200],
        ],
    ]);
    (new ApplySiteCdnJob($site->id))->handle();

    Http::assertSent(fn ($req) => $req->method() === 'PUT'
        && str_ends_with($req->url(), '/rulesets/phases/http_request_cache_settings/entrypoint')
        && count($req['rules']) === 2
        && str_starts_with($req['rules'][0]['description'], 'dply-site-')
        && $req['rules'][0]['action_parameters']['cache'] === false
        && $req['rules'][1]['action_parameters']['edge_ttl']['default'] === 7200);
});

test('disable flow clears managed rules', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/rec-1' => Http::response([
            'success' => true, 'result' => ['id' => 'rec-1'],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/rulesets/phases/http_request_cache_settings/entrypoint' => Http::sequence()
            ->push(['success' => true, 'result' => ['rules' => [
                ['description' => 'user', 'expression' => '(x)', 'action' => 'set_cache_settings', 'action_parameters' => ['cache' => false]],
            ]]])
            ->push(['success' => true]),
    ]);

    [$site] = seedCdnSite([
        'enabled' => false,
        'zone_id' => 'zone-1',
        'record_id' => 'rec-1',
    ]);

    (new ApplySiteCdnJob($site->id))->handle();

    // Only the GET should have hit the ruleset endpoint (no dply rules present → no PUT).
    $putCount = 0;
    foreach (Http::recorded() as [$request, $_]) {
        if ($request->method() === 'PUT' && str_contains($request->url(), '/rulesets/phases/http_request_cache_settings/entrypoint')) {
            $putCount++;
        }
    }
    expect($putCount)->toBe(0);
});

test('normaliseRules drops malformed entries and forces leading slash', function () {
    $out = ApplySiteCdnJob::normaliseRules([
        ['path' => 'api/', 'action' => 'bypass'],
        ['path' => '/static/', 'action' => 'cache', 'ttl' => 100],
        ['path' => '', 'action' => 'bypass'],         // dropped: empty path
        ['path' => '/x', 'action' => 'invalid'],      // dropped: bad action
        'not-an-array',                                // dropped
        ['path' => '/y', 'action' => 'cache'],        // ttl defaults to 3600
    ]);

    expect($out)->toBe([
        ['path' => '/api/', 'action' => 'bypass'],
        ['path' => '/static/', 'action' => 'cache', 'ttl' => 100],
        ['path' => '/y', 'action' => 'cache', 'ttl' => 3600],
    ]);
});

test('records error on api failure', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true, 'result' => [['id' => 'zone-1']],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => false, 'errors' => [['message' => 'broken']],
        ], 200),
    ]);

    [$site] = seedCdnSite();

    expect(fn () => (new ApplySiteCdnJob($site->id))->handle())->toThrow(\RuntimeException::class);

    $fresh = $site->fresh();
    expect($fresh->meta['cdn']['last_error'] ?? null)->toContain('broken');
});
