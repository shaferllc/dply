<?php

declare(strict_types=1);

namespace Tests\Feature\Servers\OverviewContainerLaunchBannerTest;

use App\Livewire\Servers\WorkspaceOverview;
use App\Livewire\Servers\WorkspaceSites;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('docker host with no sites shows add first container cta', function () {
    $user = userWithOrganization();
    $server = dockerServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server])
        ->assertSee('Add your first container app')
        ->assertSeeHtml('data-testid="add-first-container-cta"');
});
test('kubernetes host with no sites shows add first container cta', function () {
    $user = userWithOrganization();
    $server = kubernetesServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server])
        ->assertSeeHtml('data-testid="add-first-container-cta"');
});
test('vm host does not show container cta', function () {
    $user = userWithOrganization();
    $server = vmServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server])
        ->assertDontSeeHtml('data-testid="add-first-container-cta"')
        ->assertDontSee('Add your first container app');
});
test('container cta is hidden when launch is in flight', function () {
    $user = userWithOrganization();
    $server = dockerServer($user);
    $server->forceFill(['meta' => array_merge((array) $server->meta, [
        'container_launch' => [
            'status' => 'waiting_for_server',
            'target_family' => 'cloud_docker',
            'current_step_label' => 'Provisioning server',
            'summary' => 'Provisioning…',
            'events' => [],
        ],
    ])])->save();

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server])
        ->assertDontSeeHtml('data-testid="add-first-container-cta"')
        ->assertSeeHtml('data-testid="container-launch-progress"');
});
test('container cta is hidden when a site already exists', function () {
    $user = userWithOrganization();
    $server = dockerServer($user);
    Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $user->currentOrganization()->id,
        'user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceOverview::class, ['server' => $server])
        ->assertDontSeeHtml('data-testid="add-first-container-cta"');
});
test('workspace sites uses container copy for container hosts', function () {
    $user = userWithOrganization();
    $server = dockerServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceSites::class, ['server' => $server])
        ->assertSee('New container app')
        ->assertSee('Add container')
        ->assertDontSee('New site')
        ->assertDontSee('Add site');
});
test('workspace sites keeps site copy for vm hosts', function () {
    $user = userWithOrganization();
    $server = vmServer($user);

    Livewire::actingAs($user)
        ->test(WorkspaceSites::class, ['server' => $server])
        ->assertSee('New site')
        ->assertSee('Add site')
        ->assertDontSee('New container app')
        ->assertDontSee('Add container');
});
function dockerServer(User $user): Server
{
    return Server::factory()->ready()->create([
        'organization_id' => $user->currentOrganization()->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DOCKER],
    ]);
}
function kubernetesServer(User $user): Server
{
    return Server::factory()->ready()->create([
        'organization_id' => $user->currentOrganization()->id,
        'user_id' => $user->id,
        'meta' => [
            'host_kind' => Server::HOST_KIND_KUBERNETES,
            'kubernetes' => ['provider' => 'digitalocean', 'cluster_name' => 'c', 'namespace' => 'default'],
        ],
    ]);
}
function vmServer(User $user): Server
{
    return Server::factory()->ready()->create([
        'organization_id' => $user->currentOrganization()->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_VM, 'webserver' => 'nginx'],
    ]);
}
function userWithOrganization(): User
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $user->setRelation('currentOrganization', $organization);
    session(['current_organization_id' => $organization->id]);

    return $user;
}
