<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeCustomDomainTest;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Edge\EdgeCustomDomainProvisioner;
use App\Services\Edge\EdgeRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('attach custom domain starts in pending dns state', function () {
    config(['edge.fake.enabled' => true]);
    $site = makeLiveEdgeSite();

    $backend = EdgeRouter::backendFor($site);
    expect($backend)->not->toBeNull();

    $backend->attachDomain($site->fresh(), 'www.example.com');

    $site->refresh();
    $domains = $site->edgeMeta()['routing']['custom_domains'] ?? [];
    expect($domains)->toHaveKey('www.example.com');
    expect($domains['www.example.com']['dns_status'] ?? null)->toBe('pending');
    expect($domains['www.example.com']['cname_target'] ?? '')->not->toBe('');
});

test('verify fails when dns records are missing', function () {
    config(['edge.fake.enabled' => true]);
    $site = makeLiveEdgeSite();
    $provisioner = app(EdgeCustomDomainProvisioner::class);

    $provisioner->provision($site->fresh(), 'docs.example.com');
    $entry = $provisioner->verify($site->fresh(), 'docs.example.com');

    expect($entry['dns_status'] ?? null)->toBe('failed');
});

test('fake backend detaches custom domain', function () {
    config(['edge.fake.enabled' => true]);
    $site = makeLiveEdgeSite();

    $backend = EdgeRouter::backendFor($site);
    $backend->attachDomain($site->fresh(), 'docs.example.com');
    $backend->detachDomain($site->fresh(), 'docs.example.com');

    $site->refresh();
    $domains = $site->edgeMeta()['routing']['custom_domains'] ?? [];
    expect($domains)->not->toHaveKey('docs.example.com');
});

function makeLiveEdgeSite(): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create([
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
    ]);

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
