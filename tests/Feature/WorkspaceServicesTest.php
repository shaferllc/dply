<?php

namespace Tests\Feature\WorkspaceServicesTest;

use App\Jobs\SyncServerSystemdServicesJob;
use App\Livewire\Servers\WorkspaceServices;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerSystemdServiceAuditEvent;
use App\Models\ServerSystemdServiceState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('workspace.services');

function actingOwnerWithReadyServer(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'setup_status' => Server::SETUP_STATUS_DONE,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);

    return [$user, $server];
}

test('services workspace tabs lazy render their sections', function () {
    Queue::getFacadeRoot()->except([SyncServerSystemdServicesJob::class]);

    [$user, $server] = actingOwnerWithReadyServer();

    ServerSystemdServiceState::query()->create([
        'server_id' => $server->id,
        'unit' => 'nginx.service',
        'label' => 'nginx.service',
        'active_state' => 'active',
        'sub_state' => 'running',
        'can_manage' => true,
        'captured_at' => now(),
    ]);

    ServerSystemdServiceAuditEvent::query()->create([
        'server_id' => $server->id,
        'occurred_at' => now(),
        'kind' => 'restarted',
        'unit' => 'nginx.service',
        'label' => 'nginx.service',
        'detail' => 'activity-tab-test',
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceServices::class, ['server' => $server])
        ->assertSee('System services')
        ->assertSee('nginx.service')
        ->assertDontSee('activity-tab-test')
        ->call('setServicesWorkspaceTab', 'activity')
        ->assertSee('Service activity')
        ->assertSee('activity-tab-test')
        ->assertDontSee('Sync now')
        ->call('setServicesWorkspaceTab', 'inventory')
        ->assertSee('Sync now')
        ->assertDontSee('activity-tab-test');
});

test('opening systemd confirm highlights only the target inventory row', function () {
    Queue::getFacadeRoot()->except([SyncServerSystemdServicesJob::class]);

    [$user, $server] = actingOwnerWithReadyServer();

    ServerSystemdServiceState::query()->create([
        'server_id' => $server->id,
        'unit' => 'fail2ban.service',
        'label' => 'fail2ban',
        'active_state' => 'active',
        'sub_state' => 'running',
        'can_manage' => true,
        'captured_at' => now(),
    ]);

    ServerSystemdServiceState::query()->create([
        'server_id' => $server->id,
        'unit' => 'nginx.service',
        'label' => 'nginx',
        'active_state' => 'inactive',
        'sub_state' => 'dead',
        'can_manage' => true,
        'captured_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(WorkspaceServices::class, ['server' => $server])
        ->call('openSystemdActionConfirm', 'stop', 'fail2ban.service')
        ->assertSet('systemdActiveRowUnit', 'fail2ban.service')
        ->assertSet('systemdActiveRowAction', 'stop')
        ->assertSeeHtml('aria-busy="true"')
        ->assertSee('Stopping');
});

test('services workspace shows ops not ready without ssh', function () {
    [$user, $server] = actingOwnerWithReadyServer();
    $server->update(['ssh_private_key' => null]);

    $this->actingAs($user)
        ->get(route('servers.services', $server))
        ->assertOk()
        ->assertSee('Provisioning and SSH must be ready before managing services.');
});
