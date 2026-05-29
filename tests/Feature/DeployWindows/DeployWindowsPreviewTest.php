<?php

declare(strict_types=1);

namespace Tests\Feature\DeployWindows;

use App\Livewire\Servers\WorkspaceDeployPolicyPreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.deploy_windows', fn (): bool => false);
    Feature::define('workspace.deploy_windows_preview', fn (): bool => true);
    Feature::flushCache();
});

test('deploy windows preview sidebar shows soon badge when full feature is off', function (): void {
    [$user, $server] = deployWindowsPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('/deploy-policy', false);
});

test('deploy policy route renders coming soon panel when preview active', function (): void {
    [$user, $server] = deployWindowsPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.deploy-policy', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Deploy windows'))
        ->assertSee(__('Weekend freeze preset'));
});

test('admin vm servers page lists deploy windows preview flag', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.flags.vm.servers'))
        ->assertOk()
        ->assertSee('workspace.deploy_windows_preview')
        ->assertSee(__('Coming soon preview'));
});

test('deploy policy preview alias redirects to canonical route', function (): void {
    [$user, $server] = deployWindowsPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.deploy-policy-preview', $server))
        ->assertRedirect(route('servers.deploy-policy', $server));
});

test('deploy policy preview component redirects when preview active', function (): void {
    [$user, $server] = deployWindowsPreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceDeployPolicyPreview::class, ['server' => $server])
        ->assertRedirect(route('servers.deploy-policy', $server));
});

test('deploy policy route is hidden when preview and full feature are off', function (): void {
    Feature::define('workspace.deploy_windows_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = deployWindowsPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.deploy-policy', $server))
        ->assertNotFound();
});

test('deploy windows preview respects per-org override', function (): void {
    [$user, $server] = deployWindowsPreviewUserWithServer();
    $org = $user->currentOrganization();
    Feature::for($org)->deactivate('workspace.deploy_windows_preview');

    expect(workspace_deploy_windows_preview_active($org))->toBeFalse();

    $this->actingAs($user)
        ->get(route('servers.deploy-policy', $server))
        ->assertNotFound();
});

function deployWindowsPreviewUserWithServer(): array
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
