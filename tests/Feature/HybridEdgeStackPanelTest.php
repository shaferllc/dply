<?php

declare(strict_types=1);

namespace Tests\Feature\HybridEdgeStackPanelTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('cloud site workspace renders hybrid stack progress panel', function () {
    [$user, $server, $cloudSite] = makeCloudSiteWithStack('awaiting_origin');

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $cloudSite]))
        ->assertOk()
        ->assertSee('Hybrid Edge stack')
        ->assertSee('Provisioning Cloud SSR origin');
});

test('complete stack panel links to edge workspace', function () {
    [$user, $server, $cloudSite, $edgeSite] = makeCompletedHybridStack();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $cloudSite]))
        ->assertOk()
        ->assertSee('Edge hybrid app ready')
        ->assertSee('Open Edge app')
        ->assertSee($edgeSite->name);
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeCloudSiteWithStack(string $status): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    $cloudSite = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'SSR App origin',
        'slug' => 'ssr-app-origin',
        'type' => SiteType::Container,
        'container_backend' => 'digitalocean_app_platform',
        'status' => Site::STATUS_CONTAINER_PROVISIONING,
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/next-app', 'branch' => 'main'],
                'hybrid_edge_stack' => [
                    'status' => $status,
                    'edge_name' => 'SSR App',
                    'edge_site_id' => null,
                    'poll_attempts' => 0,
                ],
            ],
        ],
    ]);

    return [$user, $server, $cloudSite];
}

/**
 * @return array{0: User, 1: Server, 2: Site, 3: Site}
 */
function makeCompletedHybridStack(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $cloudServer = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_CLOUD],
    ]);

    $edgeServer = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $edgeSite = Site::factory()->create([
        'server_id' => $edgeServer->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'SSR App',
        'slug' => 'ssr-app',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_PROVISIONING,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'runtime_mode' => 'hybrid',
                'origin' => [
                    'url' => 'https://origin.ondigitalocean.app',
                    'managed' => true,
                ],
                'live_url' => 'https://ssr-app-abc123.on-dply.site',
            ],
        ],
    ]);

    $cloudSite = Site::factory()->create([
        'server_id' => $cloudServer->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'SSR App origin',
        'slug' => 'ssr-app-origin',
        'type' => SiteType::Container,
        'container_backend' => 'digitalocean_app_platform',
        'status' => Site::STATUS_CONTAINER_ACTIVE,
        'meta' => [
            'container' => [
                'source' => ['repo' => 'acme/next-app', 'branch' => 'main'],
                'live_url' => 'https://origin.ondigitalocean.app',
                'hybrid_edge_stack' => [
                    'status' => 'complete',
                    'edge_name' => 'SSR App',
                    'edge_site_id' => (string) $edgeSite->id,
                ],
            ],
        ],
    ]);

    return [$user, $cloudServer, $cloudSite, $edgeSite];
}
