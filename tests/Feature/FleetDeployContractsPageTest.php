<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Fleet\DeployContracts;
use App\Models\DeployContractRun;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('fleet deploy contracts page lists blocked preview', function () {
    Feature::purge(['surface.fleet', 'global.deploy_contract']);
    config([
        'features.surface.fleet' => true,
        'features.global.deploy_contract' => true,
    ]);
    Feature::flushCache();

    [$user, $server, $parent, $preview] = fleetContractFixtures();

    DeployContractRun::query()->create([
        'organization_id' => $parent->organization_id,
        'parent_site_id' => $parent->id,
        'preview_site_id' => $preview->id,
        'status' => DeployContractRun::STATUS_FAILED,
        'checks' => [['key' => 'edge.preview.replay', 'status' => 'fail', 'message' => 'nope', 'label' => 'Replay', 'engine' => 'edge']],
        'summary' => ['passed_count' => 0, 'failed_count' => 1, 'skipped_count' => 0],
        'finished_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(DeployContracts::class)
        ->assertOk()
        ->assertSee('Deploy contracts')
        ->assertSee($parent->name)
        ->assertSee('Failed');
});

/**
 * @return array{0: User, 1: Server, 2: Site, 3: Site}
 */
function fleetContractFixtures(): array
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

    $parent = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['runtime_profile' => 'edge_web', 'edge' => []],
    ]);

    $preview = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'preview_parent_site_id' => $parent->id,
                'preview_branch' => 'feature/fleet',
            ],
        ],
    ]);

    return [$user, $server, $parent, $preview];
}
