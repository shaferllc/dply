<?php

declare(strict_types=1);

namespace Tests\Feature\FleetPreviewsPageTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SitePreviewDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

usesFeatures('surface.fleet');

test('fleet previews page lists byo and edge hostnames', function () {
    [$user, $org, $server] = makeOrgWithServer();

    $byo = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'API',
    ]);

    SitePreviewDomain::query()->create([
        'site_id' => $byo->id,
        'hostname' => 'api-a1b2c3d4.on-dply.site',
        'zone' => 'on-dply.site',
        'is_primary' => true,
        'dns_status' => 'ready',
    ]);

    Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'Edge Front',
        'edge_backend' => 'dply_edge',
        'type' => SiteType::Static,
        'meta' => [
            'edge' => [
                'routing' => ['hostname' => 'edge-front-abc123.on-dply.site'],
                'live_url' => 'https://edge-front-abc123.on-dply.site',
            ],
        ],
    ]);

    $this->actingAs($user)->get(route('fleet.previews'))
        ->assertOk()
        ->assertSee(__('Preview URLs'))
        ->assertSee('api-a1b2c3d4.on-dply.site')
        ->assertSee('edge-front-abc123.on-dply.site')
        ->assertSee('API')
        ->assertSee('Edge Front');
});

/**
 * @return array{0: User, 1: Organization, 2: Server}
 */
function makeOrgWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Prod VM',
    ]);

    return [$user, $org, $server];
}
