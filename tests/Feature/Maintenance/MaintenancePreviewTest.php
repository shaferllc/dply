<?php

declare(strict_types=1);

namespace Tests\Feature\Maintenance;

use App\Livewire\Servers\WorkspaceMaintenancePreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.server_maintenance', fn (): bool => false);
    Feature::define('workspace.server_maintenance_preview', fn (): bool => true);
    Feature::flushCache();
});

test('maintenance preview sidebar shows soon badge when full feature is off', function (): void {
    [$user, $server] = maintenancePreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('/maintenance', false);
});

test('maintenance route renders coming soon panel when preview active', function (): void {
    [$user, $server] = maintenancePreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.maintenance', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Maintenance'))
        ->assertSee(__('Timed windows'));
});

test('admin vm servers page lists maintenance preview flag', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.flags.vm.servers'))
        ->assertOk()
        ->assertSee('workspace.server_maintenance_preview')
        ->assertSee(__('Coming soon preview'));
});

test('maintenance preview alias redirects to canonical route', function (): void {
    [$user, $server] = maintenancePreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.maintenance-preview', $server))
        ->assertRedirect(route('servers.maintenance', $server));
});

test('maintenance preview component redirects when preview active', function (): void {
    [$user, $server] = maintenancePreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceMaintenancePreview::class, ['server' => $server])
        ->assertRedirect(route('servers.maintenance', $server));
});

test('maintenance route is hidden when preview and full feature are off', function (): void {
    Feature::define('workspace.server_maintenance_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = maintenancePreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.maintenance', $server))
        ->assertNotFound();
});

test('maintenance preview respects per-org override', function (): void {
    [$user, $server] = maintenancePreviewUserWithServer();
    $org = $user->currentOrganization();
    Feature::for($org)->deactivate('workspace.server_maintenance_preview');

    expect(workspace_server_maintenance_preview_active($org))->toBeFalse();

    $this->actingAs($user)
        ->get(route('servers.maintenance', $server))
        ->assertNotFound();
});

function maintenancePreviewUserWithServer(): array
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
