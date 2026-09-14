<?php

declare(strict_types=1);

namespace Tests\Feature\SiteWorkerProcessManagerTest;

use App\Jobs\CollectSiteHorizonSnapshotJob;
use App\Jobs\ControlWorkerDaemonJob;
use App\Jobs\SetUpSiteQueueingJob;
use App\Livewire\Sites\WorkspaceQueue;
use App\Livewire\Sites\WorkspaceSystemd;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\SupervisorProgram;
use App\Models\User;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\SupervisorProvisioner;
use App\Services\Sites\SiteEnvPusher;
use App\Services\Sites\SiteSystemdProvisioner;
use App\Services\WorkerPools\WorkerDaemonBackend;
use App\Support\Sites\QueueWorkerPlan;
use App\Support\SiteSettingsSidebar;
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

test('the sidebar shows services for a php site only while its workers are systemd units', function () {
    [, $server, $site] = phpSiteWithOwner();
    $ids = fn (Site $s): array => collect(SiteSettingsSidebar::items($s, $server))->pluck('id')->all();

    expect($ids($site))->not->toContain('services');

    SiteProcess::factory()->create(['site_id' => $site->id, 'name' => 'horizon', 'command' => 'php artisan horizon']);
    expect($ids($site->fresh()))->toContain('services');

    $site->forceFill(['meta' => ['worker_process_manager' => 'supervisor']])->save();
    expect($ids($site->fresh()))->not->toContain('services');
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

test('the managed servers tab embeds the fleet panel once the site is on the dply queue', function () {
    [$user, $server, $site] = phpSiteWithOwner();

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site])
        ->set('queue_workspace_tab', 'fleet')
        ->assertDontSeeLivewire('queue-fleet-panel');

    $namespace = (new QueueNamespace)->forceFill([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'name' => 'edge',
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);
    $namespace->save();

    // A kept namespace alone is not "on the dply queue" — connect() records it.
    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site->fresh()])
        ->set('queue_workspace_tab', 'fleet')
        ->assertDontSeeLivewire('queue-fleet-panel');

    $site->forceFill(['meta' => ['managed_queue' => ['namespace_id' => (string) $namespace->id]]])->save();

    Livewire::actingAs($user)
        ->test(WorkspaceQueue::class, ['server' => $server, 'site' => $site->fresh()])
        ->set('queue_workspace_tab', 'fleet')
        ->assertSeeLivewire('queue-fleet-panel');
});

function queueProgram(Site $site, string $slug, string $command, bool $active = true): SupervisorProgram
{
    return SupervisorProgram::query()->create([
        'server_id' => $site->server_id,
        'site_id' => $site->id,
        'slug' => $slug,
        'program_type' => 'queue',
        'command' => $command,
        'directory' => '/home/dply/app',
        'user' => 'dply',
        'numprocs' => 1,
        'is_active' => $active,
    ]);
}

function queueSwitchRun(Site $site): ConsoleAction
{
    return ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => 'queue_setup',
        'status' => ConsoleAction::STATUS_QUEUED,
        'label' => 'Switching',
        'output' => ['v' => 1, 'lines' => []],
    ]);
}

test('a switch plan takes redis to dply and back to where it started', function () {
    [, , $site] = phpSiteWithOwner();
    $horizon = queueProgram($site, 'app-horizon', 'php artisan horizon');

    $toDply = QueueWorkerPlan::for($site, 'dply');
    expect($toDply->stop->pluck('slug')->all())->toBe(['app-horizon'])
        ->and($toDply->createDefault)->toBeTrue();

    // What the job leaves behind: horizon kept but stopped, a queue:work beside it.
    $horizon->forceFill(['is_active' => false])->save();
    queueProgram($site, 'app-queue-default', "php artisan queue:work --queue='default'");

    $toRedis = QueueWorkerPlan::for($site, 'redis');
    expect($toRedis->start->pluck('slug')->all())->toBe(['app-horizon'])
        ->and($toRedis->stop->pluck('slug')->all())->toBe(['app-queue-default'])
        ->and($toRedis->createDefault)->toBeFalse();

    // A worker pinned to another connection cannot drain this one.
    expect(QueueWorkerPlan::drains('php artisan queue:work redis --queue=a', 'database'))->toBeFalse()
        ->and(QueueWorkerPlan::drains("php artisan queue:work --queue='default'", 'database'))->toBeTrue();
});

test('the switch job starts the new worker before stopping the one that cannot read the connection', function () {
    [, , $site] = phpSiteWithOwner();
    $site->forceFill([
        'env_file_content' => "QUEUE_CONNECTION=dply\n",
        // Pending from an earlier deferral; this run finishes it.
        'meta' => ['worker_process_manager' => 'supervisor', 'queue_switch_pending' => 'dply'],
    ])->save();
    // Mirrored from a SiteProcess, as ensure() writes it: the row has to stop too.
    $process = SiteProcess::factory()->create(['site_id' => $site->id, 'name' => 'horizon', 'command' => 'php artisan horizon']);
    $horizon = queueProgram($site, 'dply-worker-'.$site->id.'-horizon', 'php artisan horizon');
    $run = queueSwitchRun($site);

    app()->instance(SiteEnvPusher::class, \Mockery::mock(SiteEnvPusher::class)->shouldIgnoreMissing());
    $exec = \Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $exec->shouldReceive('runInlineBash')->andReturn(new ProcessOutput('DPLY_PKG_YES', 0));
    $provisioner = \Mockery::mock(SupervisorProvisioner::class);
    $provisioner->shouldReceive('syncProgram')->once()->ordered()->andReturn('ok');
    $provisioner->shouldReceive('sync')->once()->ordered()->andReturn('ok');

    app()->call([new SetUpSiteQueueingJob((string) $run->id, (string) $site->id, 'dply'), 'handle'], [
        'exec' => $exec,
        'provisioner' => $provisioner,
    ]);

    expect($run->fresh()->status)->toBe(ConsoleAction::STATUS_COMPLETED)
        ->and($horizon->fresh()->is_active)->toBeFalse()
        ->and($process->fresh()->is_active)->toBeFalse()
        ->and($site->fresh()->meta['queue_stopped_processes'])->toBe(['horizon'])
        ->and($site->fresh()->meta)->not->toHaveKey('queue_switch_pending')
        ->and(SupervisorProgram::query()->where('site_id', $site->id)->where('is_active', true)->pluck('command')->all())
        ->toHaveCount(1)
        ->each->toContain('queue:work');
});

test('switching to dply before a deploy installs the package leaves the box alone', function () {
    [, , $site] = phpSiteWithOwner();
    $site->forceFill(['env_file_content' => "QUEUE_CONNECTION=dply\n"])->save();
    $horizon = queueProgram($site, 'app-horizon', 'php artisan horizon');
    $run = queueSwitchRun($site);

    // No expectations: any push would throw and fail the run.
    app()->instance(SiteEnvPusher::class, \Mockery::mock(SiteEnvPusher::class));
    $exec = \Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $exec->shouldReceive('runInlineBash')->once()->andReturn(new ProcessOutput('DPLY_PKG_NO', 0));

    app()->call([new SetUpSiteQueueingJob((string) $run->id, (string) $site->id, 'dply'), 'handle'], [
        'exec' => $exec,
        'provisioner' => \Mockery::mock(SupervisorProvisioner::class),
    ]);

    expect($run->fresh()->status)->toBe(ConsoleAction::STATUS_COMPLETED)
        ->and($horizon->fresh()->is_active)->toBeTrue()
        // The deploy that installs the package picks this up and finishes it.
        ->and($site->fresh()->meta['queue_switch_pending'])->toBe('dply');
});

test('a switch moves queue workers still on systemd to supervisor first', function () {
    [, , $site] = phpSiteWithOwner();
    SiteProcess::factory()->create(['site_id' => $site->id, 'name' => 'horizon', 'command' => 'php artisan horizon']);
    SiteProcess::factory()->create(['site_id' => $site->id, 'name' => 'scheduler', 'command' => 'php artisan schedule:work']);

    $plan = QueueWorkerPlan::for($site, 'dply');

    // ensure() moves every non-web unit, so the modal lists them all …
    expect($plan->move->pluck('name')->sort()->values()->all())->toBe(['horizon', 'scheduler'])
        // … and horizon is planned as the program it becomes, which dply cannot use.
        ->and($plan->stop->pluck('slug')->all())->toBe(['dply-worker-'.$site->id.'-horizon'])
        ->and($plan->createDefault)->toBeTrue();
});

test('a pending dply switch survives a failed step so the next deploy retries it', function () {
    [, , $site] = phpSiteWithOwner();
    $site->forceFill(['meta' => ['queue_switch_pending' => 'dply']])->save();
    $run = queueSwitchRun($site);

    app()->instance(SiteEnvPusher::class, \Mockery::mock(SiteEnvPusher::class)->shouldIgnoreMissing());
    $exec = \Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $exec->shouldReceive('runInlineBash')->andReturnUsing(
        fn ($server, string $name) => $name === 'site:queue-setup-config-clear'
            ? throw new \RuntimeException('ssh dropped')
            : new ProcessOutput('DPLY_PKG_YES', 0),
    );

    app()->call([new SetUpSiteQueueingJob((string) $run->id, (string) $site->id, 'dply'), 'handle'], [
        'exec' => $exec,
        'provisioner' => \Mockery::mock(SupervisorProvisioner::class),
    ]);

    expect($run->fresh()->status)->toBe(ConsoleAction::STATUS_FAILED)
        ->and($site->fresh()->meta['queue_switch_pending'])->toBe('dply');

    // Reverting elsewhere supersedes it: a later deploy must not re-switch to dply.
    $revert = queueSwitchRun($site);
    app()->call([new SetUpSiteQueueingJob((string) $revert->id, (string) $site->id, 'redis'), 'handle'], [
        'exec' => $exec,
        'provisioner' => \Mockery::mock(SupervisorProvisioner::class),
    ]);

    expect($site->fresh()->meta)->not->toHaveKey('queue_switch_pending');
});

test('a package check that cannot run fails the switch instead of reporting it saved', function () {
    [, , $site] = phpSiteWithOwner();
    $run = queueSwitchRun($site);

    $exec = \Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $exec->shouldReceive('runInlineBash')->andThrow(new \RuntimeException('ssh down'));

    app()->call([new SetUpSiteQueueingJob((string) $run->id, (string) $site->id, 'dply'), 'handle'], [
        'exec' => $exec,
        'provisioner' => \Mockery::mock(SupervisorProvisioner::class),
    ]);

    expect($run->fresh()->status)->toBe(ConsoleAction::STATUS_FAILED)
        ->and($run->fresh()->error)->toContain('ssh down');
});

test('moving to supervisor leaves the units running when supervisor fails, and only removes mirrored ones', function () {
    [, , $site] = phpSiteWithOwner();
    $site->forceFill(['meta' => ['worker_process_manager' => 'supervisor']])->save();
    SiteProcess::factory()->create(['site_id' => $site->id, 'name' => 'horizon', 'command' => 'php artisan horizon']);
    SiteProcess::factory()->create(['site_id' => $site->id, 'name' => 'blank', 'command' => '']);

    $supervisor = \Mockery::mock(SupervisorProvisioner::class);
    $supervisor->shouldReceive('isSupervisorPackageInstalled')->andReturn(true);
    $supervisor->shouldReceive('sync')->once()->andThrow(new \RuntimeException('supervisord down'));
    $systemd = \Mockery::mock(SiteSystemdProvisioner::class);
    $systemd->shouldNotReceive('teardownUnit');
    app()->instance(SupervisorProvisioner::class, $supervisor);
    app()->instance(SiteSystemdProvisioner::class, $systemd);

    expect(fn () => app(WorkerDaemonBackend::class)->ensure($site->fresh()))->toThrow(\RuntimeException::class, 'supervisord down');

    // Supervisor up: horizon's unit comes down; the command-less one has no program, so it stays.
    $supervisor = \Mockery::mock(SupervisorProvisioner::class);
    $supervisor->shouldReceive('isSupervisorPackageInstalled')->andReturn(true);
    $supervisor->shouldReceive('sync')->andReturn('ok');
    $systemd = \Mockery::mock(SiteSystemdProvisioner::class);
    $systemd->shouldReceive('teardownUnit')->once();
    app()->instance(SupervisorProvisioner::class, $supervisor);
    app()->instance(SiteSystemdProvisioner::class, $systemd);

    app(WorkerDaemonBackend::class)->ensure($site->fresh());
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
