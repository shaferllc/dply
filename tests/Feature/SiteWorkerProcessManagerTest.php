<?php

declare(strict_types=1);

namespace Tests\Feature\SiteWorkerProcessManagerTest;

use App\Jobs\CollectSiteHorizonSnapshotJob;
use App\Jobs\ControlWorkerDaemonJob;
use App\Livewire\Sites\WorkspaceQueue;
use App\Livewire\Sites\WorkspaceSystemd;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\User;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\WorkerPools\WorkerDaemonBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Server, 2: Site} */
function phpSiteWithOwner(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->ready()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----",
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'runtime' => 'php',
    ]);

    return [$user, $server, $site];
}

test('a php site services page lists its running worker units instead of saying systemd is unused', function () {
    [$user, $server, $site] = phpSiteWithOwner();
    SiteProcess::factory()->create(['site_id' => $site->id, 'name' => 'horizon', 'command' => 'php artisan horizon']);

    Livewire::actingAs($user)
        ->test(WorkspaceSystemd::class, ['server' => $server, 'site' => $site])
        ->assertSee('php artisan horizon')
        ->assertSee('Move them to Supervisor')
        ->assertDontSee('Systemd services not used for this site');

    // Moved to Supervisor: the rows stay (they are what gets mirrored), the units do not.
    $site->forceFill(['meta' => ['worker_process_manager' => 'supervisor']])->save();

    Livewire::actingAs($user)
        ->test(WorkspaceSystemd::class, ['server' => $server, 'site' => $site->fresh()])
        ->assertDontSee('php artisan horizon')
        ->assertSee('Systemd services not used for this site');
});

test('a site without a pool runs workers under its own process manager, systemd by default', function () {
    [, , $site] = phpSiteWithOwner();
    $backend = app(WorkerDaemonBackend::class);

    expect($backend->backendFor($site))->toBe('systemd');

    $site->forceFill(['meta' => ['worker_process_manager' => 'supervisor']])->save();

    expect($backend->backendFor($site->fresh()))->toBe('supervisor');
});

test('moving workers to supervisor records the choice and provisions it', function () {
    Bus::fake([ControlWorkerDaemonJob::class]);
    [$user, $server, $site] = phpSiteWithOwner();
    SiteProcess::factory()->create(['site_id' => $site->id, 'name' => 'horizon', 'command' => 'php artisan horizon']);

    $component = Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->assertViewHas('systemdWorkers', fn ($workers): bool => $workers->count() === 1)
        ->call('moveWorkersToSupervisor');

    expect($site->fresh()->meta['worker_process_manager'])->toBe('supervisor');
    Bus::assertDispatched(
        ControlWorkerDaemonJob::class,
        fn (ControlWorkerDaemonJob $job): bool => $job->siteId === $site->id && $job->action === 'ensure',
    );
    // The units are torn down by the job; the same workers come back through
    // workers() as Supervisor programs, so they must not be listed twice.
    $component->assertViewHas('systemdWorkers', fn ($workers): bool => $workers->isEmpty());
});

test('the queue page shows horizon controls only when a worker runs horizon', function () {
    Bus::fake([ControlWorkerDaemonJob::class, CollectSiteHorizonSnapshotJob::class]);
    [$user, $server, $site] = phpSiteWithOwner();

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->assertDontSee('Reading Horizon from the box');

    SiteProcess::factory()->create(['site_id' => $site->id, 'name' => 'horizon', 'command' => 'php artisan horizon']);

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->assertSee('Reading Horizon from the box')
        ->call('controlHorizon', 'horizon:pause')
        // Anything off the allowlist never reaches a shell.
        ->call('controlHorizon', 'horizon:clear');

    Bus::assertDispatchedTimes(ControlWorkerDaemonJob::class, 1);
    Bus::assertDispatched(ControlWorkerDaemonJob::class, fn (ControlWorkerDaemonJob $job): bool => $job->action === 'horizon:pause');
    Bus::assertDispatched(CollectSiteHorizonSnapshotJob::class);
});

test('a failed horizon pull keeps the last snapshot and records why', function () {
    [, , $site] = phpSiteWithOwner();
    $site->forceFill(['meta' => ['horizon' => ['status' => 'running', 'collected_at' => now()->subMinute()->toIso8601String()]]])->save();

    $exec = \Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $exec->shouldReceive('runInlineBash')->andThrow(new \RuntimeException('ssh down'));

    (new CollectSiteHorizonSnapshotJob((string) $site->id))->handle($exec);

    $horizon = $site->fresh()->meta['horizon'];
    expect($horizon['status'])->toBe('running')
        ->and($horizon['error'])->toContain('ssh down');
});
