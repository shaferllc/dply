<?php

declare(strict_types=1);

namespace Tests\Feature\FleetBlastRadiusPageTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\User;
use App\Services\Fleet\BlastRadiusGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

usesFeatures('surface.fleet');

test('blast radius page renders fleet tab and inventory', function () {
    [$user, $org, $server] = makeOrgWithServer();

    Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'API',
    ]);

    ServerDatabase::query()->create([
        'server_id' => $server->id,
        'name' => 'app_db',
        'engine' => 'postgres',
        'username' => 'app',
        'password' => 'secret',
        'host' => '127.0.0.1',
    ]);

    $this->actingAs($user)->get(route('fleet.blast-radius'))
        ->assertOk()
        ->assertSee(__('Blast radius'))
        ->assertSee('API')
        ->assertSee('app_db');
});

test('blast radius graph links hybrid edge to cloud origin', function () {
    [$user, $org, $server] = makeOrgWithServer();

    $cloud = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'SSR Origin',
        'container_backend' => 'dply_cloud',
        'type' => SiteType::Container,
    ]);

    Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'user_id' => $user->id,
        'name' => 'Edge Front',
        'edge_backend' => 'dply_edge',
        'meta' => [
            'edge' => [
                'origin' => [
                    'cloud_site_id' => (string) $cloud->id,
                    'url' => 'https://origin.example.com',
                ],
            ],
        ],
    ]);

    $graph = BlastRadiusGraph::forOrganization($org);
    $edgeNode = collect($graph->nodes())->first(fn (array $n): bool => $n['label'] === 'Edge Front');
    $cloudNode = collect($graph->nodes())->first(fn (array $n): bool => $n['label'] === 'SSR Origin');

    expect($edgeNode)->not->toBeNull();
    expect($cloudNode)->not->toBeNull();

    $affected = $graph->affectedBy($cloudNode['id']);
    expect(collect($affected)->pluck('label')->all())->toContain('Edge Front');
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
