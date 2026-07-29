<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeCustomDomainTest;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use App\Modules\Edge\Services\EdgeRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('attach custom domain starts in pending dns state with fake ssl active', function () {
    config([
        'edge.fake.enabled' => true,
        'edge.custom_hostnames.enabled' => true,
    ]);
    $site = makeLiveEdgeSite();

    $backend = EdgeRouter::backendFor($site);
    expect($backend)->not->toBeNull();

    $backend->attachDomain($site->fresh(), 'www.example.com');

    $site->refresh();
    $domains = $site->edgeMeta()['routing']['custom_domains'] ?? [];
    expect($domains)->toHaveKey('www.example.com');
    expect($domains['www.example.com']['dns_status'] ?? null)->toBe('pending');
    expect($domains['www.example.com']['cname_target'] ?? '')->not->toBe('');
    expect($domains['www.example.com']['ssl_status'] ?? null)->toBe('active');
    expect($domains['www.example.com']['cf_custom_hostname_id'] ?? '')->not->toBe('');
});

test('verify fails when dns records are missing', function () {
    config([
        'edge.fake.enabled' => true,
        'edge.custom_hostnames.enabled' => true,
    ]);
    $site = makeLiveEdgeSite();
    $provisioner = app(EdgeCustomDomainProvisioner::class);

    $provisioner->provision($site->fresh(), 'docs.example.com');
    $entry = $provisioner->verify($site->fresh(), 'docs.example.com');

    expect($entry['dns_status'] ?? null)->toBe('failed');
});

test('fake backend detaches custom domain', function () {
    config([
        'edge.fake.enabled' => true,
        'edge.custom_hostnames.enabled' => true,
    ]);
    $site = makeLiveEdgeSite();

    $backend = EdgeRouter::backendFor($site);
    $backend->attachDomain($site->fresh(), 'docs.example.com');
    $backend->detachDomain($site->fresh(), 'docs.example.com');

    $site->refresh();
    $domains = $site->edgeMeta()['routing']['custom_domains'] ?? [];
    expect($domains)->not->toHaveKey('docs.example.com');
});

test('managed custom hostname create is called when fake edge is off', function () {
    config([
        'edge.fake.enabled' => false,
        'edge.custom_hostnames.enabled' => true,
        'edge.cloudflare.account_id' => 'acct_test',
        'edge.cloudflare.api_token' => 'token_test',
        'edge.cloudflare.worker_zone_name' => 'on-dply.site',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone_123/custom_hostnames*' => function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => []]);
            }

            if ($request->method() === 'POST') {
                return Http::response([
                    'success' => true,
                    'result' => [
                        'id' => 'ch_abc',
                        'hostname' => 'api.example.com',
                        'status' => 'pending',
                        'ssl' => ['status' => 'pending_validation', 'method' => 'http', 'type' => 'dv'],
                        'ownership_verification' => [
                            'type' => 'txt',
                            'name' => '_cf-custom-hostname.api.example.com',
                            'value' => 'ownership-token',
                        ],
                    ],
                ]);
            }

            return Http::response(['success' => true, 'result' => []]);
        },
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone_123', 'name' => 'on-dply.site']],
        ]),
    ]);

    $site = makeLiveEdgeSite();
    $provisioner = app(EdgeCustomDomainProvisioner::class);
    $entry = $provisioner->provision($site->fresh(), 'api.example.com');

    expect($entry['cf_custom_hostname_id'] ?? null)->toBe('ch_abc');
    expect($entry['ssl_status'] ?? null)->toBe('pending');
    expect($entry['ownership_verification']['value'] ?? null)->toBe('ownership-token');

    Http::assertSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/custom_hostnames')
            && ($request['hostname'] ?? null) === 'api.example.com';
    });
});

test('remove deletes custom hostname on cloudflare', function () {
    config([
        'edge.fake.enabled' => false,
        'edge.custom_hostnames.enabled' => true,
        'edge.cloudflare.account_id' => 'acct_test',
        'edge.cloudflare.api_token' => 'token_test',
        'edge.cloudflare.worker_zone_name' => 'on-dply.site',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone_123/custom_hostnames/ch_del' => Http::response([
            'success' => true,
            'result' => ['id' => 'ch_del'],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone_123/custom_hostnames*' => Http::response([
            'success' => true,
            'result' => [
                'id' => 'ch_del',
                'hostname' => 'gone.example.com',
                'ssl' => ['status' => 'active'],
            ],
        ]),
        'https://api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone_123', 'name' => 'on-dply.site']],
        ]),
    ]);

    $site = makeLiveEdgeSite();
    $meta = $site->edgeMeta();
    $meta['routing']['custom_domains']['gone.example.com'] = [
        'hostname' => 'gone.example.com',
        'mode' => 'manual',
        'dns_status' => 'ready',
        'cname_target' => 'edge-app.dply.host',
        'cf_custom_hostname_id' => 'ch_del',
        'ssl_status' => 'active',
    ];
    $site->update(['meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta])]);

    app(EdgeCustomDomainProvisioner::class)->remove($site->fresh(), 'gone.example.com');

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && str_contains($request->url(), '/custom_hostnames/ch_del');
    });

    $site->refresh();
    expect($site->edgeMeta()['routing']['custom_domains'] ?? [])->not->toHaveKey('gone.example.com');
});

test('org cloudflare backend skips custom hostnames', function () {
    config([
        'edge.fake.enabled' => true,
        'edge.custom_hostnames.enabled' => true,
    ]);

    $site = makeLiveEdgeSite(['edge_backend' => 'org_cloudflare']);
    $provisioner = app(EdgeCustomDomainProvisioner::class);
    $entry = $provisioner->provision($site->fresh(), 'byo.example.com');

    expect($entry['ssl_status'] ?? null)->toBeNull();
    expect($entry['cf_custom_hostname_id'] ?? null)->toBeNull();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function makeLiveEdgeSite(array $overrides = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create(array_merge([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Edge App',
        'slug' => 'edge-app',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'routing' => ['hostname' => 'edge-app.dply.host', 'spa_fallback' => true],
                'live_url' => 'https://edge-app.dply.host',
            ],
        ],
    ], $overrides));

    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/test/'.$site->id,
        'published_at' => now(),
    ]);

    $meta = $site->edgeMeta();
    $meta['active_deployment_id'] = $deployment->id;
    $site->update(['meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta])]);

    return $site->fresh();
}
