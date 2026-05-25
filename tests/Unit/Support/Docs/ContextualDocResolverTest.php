<?php

namespace Tests\Unit\Support\Docs;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Docs\ContextualDocResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

usesFeatures('surface.edge');

test('contextual doc resolver maps edge index to edge fleet', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $this->actingAs($user)
        ->get(route('edge.index'))
        ->assertOk();

    $resolver = app(ContextualDocResolver::class);

    expect($resolver->resolve())->toBe('edge-fleet')
        ->and($resolver->guideGroup()['key'] ?? null)->toBe('edge');
});

test('contextual doc resolver maps edge create route', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $this->actingAs($user)
        ->get(route('edge.create'))
        ->assertOk();

    expect(app(ContextualDocResolver::class)->resolve())->toBe('edge-create');
});

test('contextual doc resolver maps edge site build section', function () {
    [$user, $server, $site] = makeEdgeSiteForDocs();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-build']))
        ->assertOk();

    expect(app(ContextualDocResolver::class)->resolve())->toBe('edge-build');
});

test('contextual doc resolver honors doc route props', function () {
    $resolver = app(ContextualDocResolver::class);

    expect($resolver->resolve(null, 'docs.markdown', 'billing-and-plans'))->toBe('billing-and-plans')
        ->and($resolver->resolve(null, 'docs.api'))->toBe('api')
        ->and($resolver->resolve(null, 'docs.create-first-server'))->toBe('create-first-server');
});

test('contextual doc resolver full page url for markdown slug', function () {
    $resolver = app(ContextualDocResolver::class);

    expect($resolver->fullPageUrlForSlug('edge-overview'))
        ->toBe(route('docs.markdown', ['slug' => 'edge-overview']));
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeEdgeSiteForDocs(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'edge-app',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'live_url' => 'https://edge-app.dply.host',
            ],
        ],
    ]);

    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/test/prefix',
        'published_at' => now(),
    ]);

    return [$user, $server, $site];
}
