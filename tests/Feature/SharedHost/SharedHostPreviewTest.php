<?php

declare(strict_types=1);

namespace Tests\Feature\SharedHost;

use App\Livewire\Servers\WorkspaceSharedHost;
use App\Livewire\Servers\WorkspaceSharedHostPreview;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('workspace.shared_host', fn (): bool => false);
    Feature::define('workspace.shared_host_preview', fn (): bool => true);
    Feature::flushCache();
});

test('shared host preview sidebar shows soon badge when full feature is off', function (): void {
    [$user, $server] = sharedHostPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.overview', $server))
        ->assertOk()
        ->assertSee(__('Soon'))
        ->assertSee('/shared-host', false);
});

test('shared host route renders coming soon panel when preview active', function (): void {
    [$user, $server] = sharedHostPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.shared-host', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Shared Host Radar'))
        ->assertSee(__('Site load attribution'));
});

test('admin vm servers page lists shared host preview flag', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.flags.vm.servers'))
        ->assertOk()
        ->assertSee('workspace.shared_host_preview')
        ->assertSee(__('Coming soon preview'));
});

test('shared host preview alias redirects to canonical route', function (): void {
    [$user, $server] = sharedHostPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.shared-host-preview', $server))
        ->assertRedirect(route('servers.shared-host', $server));
});

test('shared host preview component redirects when preview active', function (): void {
    [$user, $server] = sharedHostPreviewUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSharedHostPreview::class, ['server' => $server])
        ->assertRedirect(route('servers.shared-host', $server));
});

test('shared host route is hidden when preview and full feature are off', function (): void {
    Feature::define('workspace.shared_host_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = sharedHostPreviewUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.shared-host', $server))
        ->assertNotFound();
});

test('shared host preview respects per-org override', function (): void {
    [$user, $server] = sharedHostPreviewUserWithServer();
    $org = $user->currentOrganization();
    Feature::for($org)->deactivate('workspace.shared_host_preview');

    expect(workspace_shared_host_preview_active($org))->toBeFalse();

    $this->actingAs($user)
        ->get(route('servers.shared-host', $server))
        ->assertNotFound();
});

test('shared host workspace renders solo tenant empty state with one site', function (): void {
    Feature::define('workspace.shared_host', fn (): bool => true);
    Feature::flushCache();

    [$user, $server] = sharedHostPreviewUserWithServer(siteCount: 1);

    $this->actingAs($user)
        ->get(route('servers.shared-host', $server))
        ->assertOk()
        ->assertSee(__('Solo tenant on this host'));
});

test('shared host workspace renders attribution panel with feature on and two sites', function (): void {
    Feature::define('workspace.shared_host', fn (): bool => true);
    Feature::flushCache();

    [$user, $server] = sharedHostPreviewUserWithServer(siteCount: 2);

    Livewire::actingAs($user)
        ->test(WorkspaceSharedHost::class, ['server' => $server])
        ->assertOk()
        ->assertSee(__('Site load attribution'))
        ->assertSee(__('Shared stack map'))
        ->assertSee(__('Contention timeline'));
});

function sharedHostPreviewUserWithServer(int $siteCount = 0): array
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

    if ($siteCount > 0) {
        Site::factory()->count($siteCount)->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);
    }

    return [$user, $server];
}
