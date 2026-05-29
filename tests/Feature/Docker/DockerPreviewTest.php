<?php

declare(strict_types=1);

namespace Tests\Feature\Docker;

use App\Livewire\Servers\WorkspaceDockerPreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.docker', fn (): bool => false);
    Feature::define('workspace.docker_preview', fn (): bool => true);
    Feature::flushCache();
});

test('docker preview sidebar shows soon badge when full feature is off', function (): void {
    [$user, $server] = dockerPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('/docker', false);
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
