<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeProvisioningCancelTest;

use App\Enums\SiteType;
use App\Jobs\TeardownEdgeSiteJob;
use App\Livewire\Sites\Show as SitesShow;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('cancel build during edge provisioning tears down site and edge server', function () {
    Queue::getFacadeRoot()->except([TeardownEdgeSiteJob::class]);

    [$user, $server, $site] = makeProvisioningEdgeSite();
    $siteId = $site->id;
    $serverId = $server->id;
    $deploymentId = EdgeDeployment::query()->where('site_id', $siteId)->value('id');

    Livewire::actingAs($user)
        ->test(SitesShow::class, ['server' => $server, 'site' => $site])
        ->call('cancelProvisioning')
        ->assertRedirect(route('edge.index'));

    expect(Site::query()->find($siteId))->toBeNull();
    expect(Server::query()->find($serverId))->toBeNull();
    expect(EdgeDeployment::query()->find($deploymentId))->toBeNull();
});

test('open cancel modal uses edge copy for edge sites', function () {
    [$user, $server, $site] = makeProvisioningEdgeSite();

    Livewire::actingAs($user)
        ->test(SitesShow::class, ['server' => $server, 'site' => $site])
        ->call('openCancelProvisioningModal')
        ->assertSet('confirmActionModalTitle', __('Cancel Edge build?'))
        ->assertSet('confirmActionModalConfirmLabel', __('Cancel and remove site'));
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeProvisioningEdgeSite(): array
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
        'name' => 'Edge App',
        'slug' => 'edge-app',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_PROVISIONING,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
                'routing' => ['hostname' => 'edge-app.dply.host'],
            ],
        ],
    ]);

    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_BUILDING,
        'storage_prefix' => 'edge/test/prefix',
    ]);

    return [$user, $server, $site];
}
