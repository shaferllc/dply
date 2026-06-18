<?php

namespace Tests\Unit\Support\Docs;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Docs\Support\ContextualDocResolver;
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

test('contextual doc resolver maps edge site previews section', function () {
    [$user, $server, $site] = makeEdgeSiteForDocs();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-previews']))
        ->assertOk();

    expect(app(ContextualDocResolver::class)->resolve())->toBe('edge-previews');
});

test('contextual doc resolver maps site section without route params', function () {
    [$user, $server, $site] = makeEdgeSiteForDocs();

    $resolver = app(ContextualDocResolver::class);

    expect($resolver->resolveForSiteSection($site, 'edge-previews'))->toBe('edge-previews')
        ->and($resolver->resolveForSiteSection($site, 'edge-build'))->toBe('edge-build')
        ->and($resolver->resolveForSiteSection($site, 'edge-delivery'))->toBe('edge-delivery')
        ->and($resolver->resolveForSiteSection($site, 'edge-environment'))->toBe('edge-environment');
});

test('contextual doc resolver builds documentation breadcrumbs', function () {
    $resolver = app(ContextualDocResolver::class);

    expect($resolver->breadcrumbsForSlug('edge-previews'))->toBe([
        ['label' => 'Documentation', 'slug' => 'docs-index'],
        ['label' => 'Edge guides', 'slug' => null],
        ['label' => 'Edge previews', 'slug' => 'edge-previews'],
    ]);
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

test('contextual doc resolver maps vm site sections from config', function () {
    $site = Site::factory()->make([
        'meta' => ['runtime_profile' => 'php'],
    ]);

    $resolver = app(ContextualDocResolver::class);

    expect($resolver->resolveForSiteSection($site, 'deploy'))->toBe('vm-site-deploy')
        ->and($resolver->resolveForSiteSection($site, 'certificates'))->toBe('vm-site-certificates')
        ->and($resolver->resolveForSiteSection($site, 'general'))->toBe('vm-site-overview')
        ->and($resolver->resolveForSiteSection($site, 'laravel-stack'))->toBe('vm-site-laravel');
});

test('contextual doc resolver maps server workspace section keys', function () {
    $resolver = app(ContextualDocResolver::class);

    expect($resolver->resolveForServerWorkspace('webserver'))->toBe('server-webserver')
        ->and($resolver->resolveForServerWorkspace('edge-proxy'))->toBe('server-edge-proxy')
        ->and($resolver->resolveForServerWorkspace('shared-host'))->toBe('server-shared-host')
        ->and($resolver->resolveForServerWorkspace('overview'))->toBe('server-overview')
        ->and($resolver->resolveForServerWorkspace(null))->toBe('server-overview');
});

test('contextual doc resolver maps dedicated vm site routes from config', function () {
    $routes = config('contextual-docs.site_route_slugs');

    expect($routes['sites.webserver-config'] ?? null)->toBe('vm-site-webserver-config')
        ->and($routes['sites.monitor'] ?? null)->toBe('vm-site-monitor');
});

test('contextual doc resolver resolves vm site monitor route at runtime', function () {
    [$user, $server, $site] = makeVmSiteForDocs();

    $this->actingAs($user)
        ->get(route('sites.monitor', ['server' => $server, 'site' => $site]))
        ->assertOk();

    $resolver = app(ContextualDocResolver::class);

    expect($resolver->resolve())->toBe('vm-site-monitor')
        ->and($resolver->guideGroup()['key'] ?? null)->toBe('byo-sites');
});

test('contextual doc resolver resolves vm site repository route at runtime', function () {
    [$user, $server, $site] = makeVmSiteForDocs();

    $this->actingAs($user)
        ->get(route('sites.repository', ['server' => $server, 'site' => $site]))
        ->assertOk();

    expect(app(ContextualDocResolver::class)->resolve())->toBe('vm-site-repository');
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

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeVmSiteForDocs(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'vm-app',
        'status' => Site::STATUS_NGINX_ACTIVE,
        'meta' => [
            'runtime_profile' => 'php',
        ],
    ]);

    return [$user, $server, $site];
}
