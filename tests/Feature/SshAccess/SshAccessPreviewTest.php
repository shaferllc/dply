<?php

declare(strict_types=1);

namespace Tests\Feature\SshAccess;

use App\Livewire\Servers\WorkspaceSshAccessGraphPreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.ssh_access_graph', fn (): bool => false);
    Feature::define('workspace.ssh_access_graph_preview', fn (): bool => true);
    Feature::flushCache();
});

test('ssh access preview sidebar shows soon badge when full feature is off', function (): void {
    [$user, $server] = sshAccessPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('/ssh-access', false);
});

test('ssh access route renders coming soon panel when preview active', function (): void {
    [$user, $server] = sshAccessPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.ssh-access', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('SSH access'))
        ->assertSee(__('Time-boxed sessions'));
});

test('admin vm servers page lists ssh access preview flag', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.flags.vm.servers'))
        ->assertOk()
        ->assertSee('workspace.ssh_access_graph_preview')
        ->assertSee(__('Coming soon preview'));
});

test('ssh access preview alias redirects to canonical route', function (): void {
    [$user, $server] = sshAccessPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.ssh-access-preview', $server))
        ->assertRedirect(route('servers.ssh-access', $server));
});

test('ssh access preview component redirects when preview active', function (): void {
    [$user, $server] = sshAccessPreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSshAccessGraphPreview::class, ['server' => $server])
        ->assertRedirect(route('servers.ssh-access', $server));
});

test('ssh access route is hidden when preview and full feature are off', function (): void {
    Feature::define('workspace.ssh_access_graph_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = sshAccessPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.ssh-access', $server))
        ->assertNotFound();
});

test('ssh access preview respects per-org override', function (): void {
    [$user, $server] = sshAccessPreviewUserWithServer();
    $org = $user->currentOrganization();
    Feature::for($org)->deactivate('workspace.ssh_access_graph_preview');

    expect(workspace_ssh_access_graph_preview_active($org))->toBeFalse();

    $this->actingAs($user)
        ->get(route('servers.ssh-access', $server))
        ->assertNotFound();
});

function sshAccessPreviewUserWithServer(): array
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
