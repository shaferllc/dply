<?php

declare(strict_types=1);

namespace Tests\Feature\Docker;

use App\Livewire\Servers\WorkspaceDocker;
use App\Livewire\Servers\WorkspaceDockerPreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Models\User;
use App\Support\Servers\ServerInstalledServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.docker', fn (): bool => false);
    Feature::define('workspace.docker_preview', fn (): bool => true);
    Feature::flushCache();
});

test('docker is in the sidebar and opens wherever it is installed, whatever the flag', function (): void {
    [$user, $server] = dockerPreviewUserWithServer();
    seedDockerPreviewStack($server, ['nginx', 'php-fpm', 'docker-daemon']);

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(route('servers.docker', $server), false);

    // The route guard lets it through, and the component is the full
    // workspace rather than the teaser: Docker is on this box.
    $this->actingAs($user)->get(route('servers.docker', $server))->assertOk();
    Livewire::actingAs($user)
        ->test(WorkspaceDocker::class, ['server' => $server])
        ->assertSet('comingSoonPreview', false);
});

test('docker installed after provisioning still gets the sidebar item', function (): void {
    [$user, $server] = dockerPreviewUserWithServer();
    seedDockerPreviewStack($server, ['nginx', 'php-fpm']);
    // What the Tools page probe records after "Install Docker service".
    $server->forceFill(['meta' => ['host_kind' => 'vm', 'manage_tools' => ['docker' => ['present' => true, 'version' => '27.0.0']]]])->save();
    ServerInstalledServices::flushCaches();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(route('servers.docker', $server), false);
});

test('a server without docker has no docker sidebar item', function (): void {
    [$user, $server] = dockerPreviewUserWithServer();
    seedDockerPreviewStack($server, ['nginx', 'php-fpm']);

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertDontSee(route('servers.docker', $server), false);
});

test('docker route renders coming soon panel when preview active', function (): void {
    [$user, $server] = dockerPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.docker', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Docker'))
        ->assertSee(__('Containers & logs'));
});

test('admin vm servers page lists docker preview flag', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.flags.vm.servers'))
        ->assertOk()
        ->assertSee('workspace.docker_preview')
        ->assertSee(__('Coming soon preview'));
});

test('docker preview alias redirects to canonical route', function (): void {
    [$user, $server] = dockerPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.docker-preview', $server))
        ->assertRedirect(route('servers.docker', $server));
});

test('docker preview component redirects when preview active', function (): void {
    [$user, $server] = dockerPreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceDockerPreview::class, ['server' => $server])
        ->assertRedirect(route('servers.docker', $server));
});

test('docker route is hidden when preview and full feature are off', function (): void {
    Feature::define('workspace.docker_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = dockerPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.docker', $server))
        ->assertNotFound();
});

test('docker preview respects per-org override', function (): void {
    [$user, $server] = dockerPreviewUserWithServer();
    $org = $user->currentOrganization();
    Feature::for($org)->deactivate('workspace.docker_preview');

    expect(workspace_docker_preview_active($org))->toBeFalse();

    $this->actingAs($user)
        ->get(route('servers.docker', $server))
        ->assertNotFound();
});

/** @param  list<string>  $services  the provision stack's expected services */
function seedDockerPreviewStack(Server $server, array $services): void
{
    $run = ServerProvisionRun::create(['server_id' => $server->id, 'attempt' => 1, 'status' => 'completed']);
    ServerProvisionArtifact::create([
        'server_provision_run_id' => $run->id,
        'type' => 'stack_summary',
        'key' => 'stack_summary',
        'label' => 'stack summary',
        'metadata' => ['expected_services' => $services],
    ]);
    ServerInstalledServices::flushCaches();
}

function dockerPreviewUserWithServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => 'vm'],
        'status' => Server::STATUS_READY,
        'setup_status' => Server::SETUP_STATUS_DONE,
    ]);

    return [$user, $server];
}
