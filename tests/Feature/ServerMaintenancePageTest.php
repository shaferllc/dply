<?php

declare(strict_types=1);

namespace Tests\Feature\ServerMaintenancePageTest;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Servers\WorkspaceMaintenance;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

uses(RefreshDatabase::class);

usesFeatures('workspace.server_maintenance');

function maintenanceUserWithServer(string $role = 'owner'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        // serverOpsReady() requires a private key alongside the ready status +
        // ip the factory already sets.
        'ssh_private_key' => 'dummy-private-key',
        'meta' => ['host_kind' => 'vm'],
    ]);

    return [$user, $server];
}

test('server maintenance page is hidden when feature and preview are off', function (): void {
    Feature::define('workspace.server_maintenance', fn (): bool => false);
    Feature::define('workspace.server_maintenance_preview', fn (): bool => false);
    Feature::flushCache();

    [$user, $server] = maintenanceUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.maintenance', $server))
        ->assertNotFound();
});

test('server maintenance page renders coming soon when feature off but preview on', function (): void {
    Feature::define('workspace.server_maintenance', fn (): bool => false);
    Feature::define('workspace.server_maintenance_preview', fn (): bool => true);
    Feature::flushCache();

    [$user, $server] = maintenanceUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.maintenance', $server))
        ->assertOk()
        ->assertSee(__('Coming soon'))
        ->assertSee(__('Maintenance'));
});

test('server maintenance page renders controls', function (): void {
    [$user, $server] = maintenanceUserWithServer();

    $this->actingAs($user)
        ->get(route('servers.maintenance', $server))
        ->assertOk()
        ->assertSee(__('Visitor maintenance'))
        ->assertSee(__('Site impact'))
        ->assertSee(__('Preferred maintenance schedule'))
        ->assertSee(__('Public visitor message'));
});

test('org owner can enable maintenance from livewire', function (): void {
    [$user, $server] = maintenanceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceMaintenance::class, ['server' => $server])
        ->set('maintenance_message', 'Patch window')
        ->call('enableMaintenance')
        ->assertHasNoErrors();

    expect($server->fresh()->meta['maintenance']['active'] ?? false)->toBeTrue();
});

test('a past end time is rejected before the confirm modal opens', function (): void {
    [$user, $server] = maintenanceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceMaintenance::class, ['server' => $server])
        ->set('maintenance_timezone', 'UTC')
        ->set('maintenance_until_local', '2020-01-01T00:00')
        ->call('openEnableModal')
        ->assertHasErrors(['maintenance_until_local'])
        ->assertNotDispatched('open-modal');

    expect($server->fresh()->meta['maintenance']['active'] ?? false)->toBeFalse();
});

test('the end time is interpreted in the operator browser timezone and stored as UTC', function (): void {
    [$user, $server] = maintenanceUserWithServer();

    // 12:00 on 2999-12-31 in New York is EST (UTC-5) → 17:00 UTC.
    Livewire::actingAs($user)
        ->test(WorkspaceMaintenance::class, ['server' => $server])
        ->set('maintenance_timezone', 'America/New_York')
        ->set('maintenance_until_local', '2999-12-31T12:00')
        ->call('enableMaintenance')
        ->assertHasNoErrors();

    $until = Carbon::parse($server->fresh()->meta['maintenance']['until'])->utc();
    expect($until->format('Y-m-d H:i'))->toBe('2999-12-31 17:00');
});

test('an invalid browser timezone falls back to the app timezone', function (): void {
    config(['app.timezone' => 'UTC']);

    [$user, $server] = maintenanceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceMaintenance::class, ['server' => $server])
        ->set('maintenance_timezone', 'Not/AReal_Zone')
        ->set('maintenance_until_local', '2999-12-31T12:00')
        ->call('enableMaintenance')
        ->assertHasNoErrors();

    $until = Carbon::parse($server->fresh()->meta['maintenance']['until'])->utc();
    expect($until->format('Y-m-d H:i'))->toBe('2999-12-31 12:00');
});

test('running an allowlisted operation queues the manage job and logs activity', function (): void {
    Bus::fake();

    [$user, $server] = maintenanceUserWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceMaintenance::class, ['server' => $server])
        ->call('runMaintenanceAction', 'apt_clean')
        ->assertSet('remote_error', null)
        ->assertSet('maintenanceActionLabel', config('server_manage.service_actions.apt_clean.label'));

    Bus::assertDispatched(ServerManageRemoteSshJob::class, function (ServerManageRemoteSshJob $job): bool {
        return $job->taskName === 'manage-action:apt_clean';
    });

    expect(ServerManageAction::where('server_id', $server->id)
        ->where('task_name', 'manage-action:apt_clean')
        ->exists())->toBeTrue();
});

test('an action outside the maintenance allowlist is rejected', function (): void {
    Bus::fake();

    [$user, $server] = maintenanceUserWithServer();

    // restart_nginx is a real server_manage action but is NOT in
    // config/server_maintenance.php operations, so the page must refuse it.
    Livewire::actingAs($user)
        ->test(WorkspaceMaintenance::class, ['server' => $server])
        ->call('runMaintenanceAction', 'restart_nginx')
        ->assertSet('remote_error', __('Unknown action.'));

    Bus::assertNotDispatched(ServerManageRemoteSshJob::class);
});

test('deployers cannot run maintenance operations', function (): void {
    Bus::fake();

    [$user, $server] = maintenanceUserWithServer('deployer');

    Livewire::actingAs($user)
        ->test(WorkspaceMaintenance::class, ['server' => $server])
        ->call('runMaintenanceAction', 'apt_clean');

    Bus::assertNotDispatched(ServerManageRemoteSshJob::class);
});
