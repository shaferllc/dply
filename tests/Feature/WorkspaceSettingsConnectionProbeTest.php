<?php

namespace Tests\Feature\WorkspaceSettingsConnectionProbeTest;

use App\Jobs\ProbeServerOperationalSshJob;
use App\Livewire\Servers\WorkspaceSettings;
use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ownerWithServer(array $serverOverrides = []): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create(array_merge([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'status' => Server::STATUS_READY,
        'ssh_user' => 'dply',
        'ip_address' => '203.0.113.10',
    ], $serverOverrides));

    return [$user, $server];
}

test('saving connection settings dispatches the operational ssh probe and arms polling', function (): void {
    Queue::fake();
    [$user, $server] = ownerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'connection'])
        ->call('saveServerSettingsInfo')
        ->assertSet('operationalSshProbing', true)
        ->assertDispatched('notify', type: 'success');

    Queue::assertPushed(
        ProbeServerOperationalSshJob::class,
        fn (ProbeServerOperationalSshJob $job): bool => $job->serverId === (string) $server->id,
    );
});

test('the test connection button dispatches the probe without saving', function (): void {
    Queue::fake();
    [$user, $server] = ownerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'connection'])
        ->call('testSshConnection')
        ->assertSet('operationalSshProbing', true)
        ->assertDispatched('notify', type: 'success');

    Queue::assertPushed(ProbeServerOperationalSshJob::class, 1);
});

test('reload stops polling once the probe records a fresh result', function (): void {
    [$user, $server] = ownerWithServer();

    $component = Livewire::actingAs($user)
        ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'connection'])
        ->set('operationalSshProbing', true)
        ->set('operationalSshProbeStartedAt', now()->subSeconds(5)->getTimestamp());

    // Simulate the queued probe finishing: a fresh tested_at + healthy verdict.
    $server->update(['meta' => array_merge($server->meta ?? [], [
        'ssh_operational_status' => 'healthy',
        'ssh_operational_tested_at' => now()->toIso8601String(),
    ])]);

    $component->call('reloadOperationalSshStatus')
        ->assertSet('operationalSshProbing', false);
});

test('reload keeps polling while the probe is still in flight', function (): void {
    [$user, $server] = ownerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'connection'])
        ->set('operationalSshProbing', true)
        ->set('operationalSshProbeStartedAt', now()->subSeconds(5)->getTimestamp())
        ->call('reloadOperationalSshStatus')
        ->assertSet('operationalSshProbing', true);
});

test('a stale prior probe result does not end the new probe', function (): void {
    [$user, $server] = ownerWithServer();

    // A result from before this probe started must be ignored.
    $server->update(['meta' => array_merge($server->meta ?? [], [
        'ssh_operational_status' => 'failing',
        'ssh_operational_tested_at' => now()->subMinutes(10)->toIso8601String(),
    ])]);

    Livewire::actingAs($user)
        ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'connection'])
        ->set('operationalSshProbing', true)
        ->set('operationalSshProbeStartedAt', now()->subSeconds(5)->getTimestamp())
        ->call('reloadOperationalSshStatus')
        ->assertSet('operationalSshProbing', true);
});

test('reload times out after 45s and surfaces an error', function (): void {
    [$user, $server] = ownerWithServer();

    Livewire::actingAs($user)
        ->test(WorkspaceSettings::class, ['server' => $server, 'section' => 'connection'])
        ->set('operationalSshProbing', true)
        ->set('operationalSshProbeStartedAt', now()->subSeconds(60)->getTimestamp())
        ->call('reloadOperationalSshStatus')
        ->assertSet('operationalSshProbing', false)
        ->assertDispatched('notify', type: 'error');
});

test('the probe marks healthy without ssh when the deploy user is root', function (): void {
    [, $server] = ownerWithServer(['ssh_user' => 'root']);

    (new ProbeServerOperationalSshJob((string) $server->id))->handle();

    $server->refresh();

    expect($server->meta['ssh_operational_status'] ?? null)->toBe('healthy')
        ->and($server->meta['ssh_operational_tested_at'] ?? null)->not->toBeNull()
        ->and($server->meta['ssh_operational_error'] ?? null)->toBeNull();
});
