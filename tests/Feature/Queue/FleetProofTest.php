<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\FleetProofTest;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Modules\Queue\Jobs\BuildFleetImageJob;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\ManagedQueueWorker;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Services\FleetReachabilityProbe;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('fleet-host opts a server in, reads its soak, and opts it out', function () {
    $server = Server::factory()->create(['name' => 'fleet-1']);
    $remote = \Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $remote->shouldReceive('runInlineBash')->once()
        ->withArgs(fn ($s, string $name, string $script, ...$rest): bool => $name === 'fleet-host-install-docker' && str_contains($script, 'docker'))
        ->andReturn(new ProcessOutput('Docker already installed', 0));
    app()->instance(ExecuteRemoteTaskOnServer::class, $remote);

    $this->artisan('dply:queue:fleet-host', ['server' => 'fleet-1', '--capacity' => 3072])->assertSuccessful();
    expect($server->fresh()->meta['queue_fleet_host'])->toMatchArray(['enabled' => true, 'capacity_mib' => 3072]);

    // Three days into a soak, with one worker the host failed to keep.
    $meta = $server->fresh()->meta;
    data_set($meta, 'queue_fleet_host.proof.soak_started_at', now()->subDays(3)->toIso8601String());
    $server->forceFill(['meta' => $meta])->save();
    $worker = new ManagedQueueWorker;
    $worker->forceFill([
        'fleet_id' => (string) Str::ulid(),
        'runtime' => 'docker',
        'host_server_id' => $server->id,
        'state' => ManagedQueueWorker::STATE_ERRORED,
        'memory_mib' => 256,
        'stopped_at' => now()->subDay(),
    ])->saveQuietly();

    $this->artisan('dply:queue:fleet-host', ['server' => 'fleet-1'])
        ->expectsOutputToContain('failing — day 3 of 7, 1 worker failure(s)')
        ->assertSuccessful();

    $this->artisan('dply:queue:fleet-host', ['server' => 'fleet-1', '--off' => true])->assertSuccessful();
    expect($server->fresh()->meta['queue_fleet_host']['enabled'])->toBeFalse();
});

test('a deploy rebuilds the site’s active fleets, and only once a fleet host exists', function () {
    Bus::fake([BuildFleetImageJob::class]);
    config(['queue_service.fleets.runtime' => 'docker']);

    $site = Site::factory()->create();
    $namespace = QueueNamespace::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'name' => 'edge',
        'status' => QueueNamespace::STATUS_ACTIVE,
    ]);
    $fleet = fn (string $status) => ManagedQueueFleet::query()->create([
        'namespace_id' => $namespace->id,
        'organization_id' => $site->organization_id,
        'queue' => 'q-'.$status,
        'class' => ManagedQueueFleet::CLASS_FLEX,
        'status' => $status,
        'memory_mib' => 256,
        'min_workers' => 0,
        'max_workers' => 2,
    ]);
    $active = $fleet(ManagedQueueFleet::STATUS_ACTIVE);
    $fleet(ManagedQueueFleet::STATUS_PAUSED);
    $rebuild = fn () => (fn () => $this->rebuildQueueFleets())->call(new RunSiteDeploymentJob($site));

    // No fleet host yet: a build could not be placed, so none is asked for.
    $rebuild();
    Bus::assertNotDispatched(BuildFleetImageJob::class);

    Server::factory()->create(['meta' => ['queue_fleet_host' => ['enabled' => true, 'capacity_mib' => 8192]]]);
    $rebuild();
    Bus::assertDispatchedTimes(BuildFleetImageJob::class, 1);
    Bus::assertDispatched(BuildFleetImageJob::class, fn ($job): bool => $job->fleetId === (string) $active->id);
});

test('fleet-host refuses a capacity too small for one worker', function () {
    Server::factory()->create(['name' => 'fleet-1']);

    $this->artisan('dply:queue:fleet-host', ['server' => 'fleet-1', '--capacity' => 100])->assertFailed();
});

test('the probe dials the app database and redis the way laravel reads them', function () {
    expect(FleetReachabilityProbe::targets([
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => '10.0.0.5',
        'REDIS_HOST' => '10.0.0.6',
    ]))->toBe([
        ['name' => 'database', 'host' => '10.0.0.5', 'port' => 5432],
        ['name' => 'redis', 'host' => '10.0.0.6', 'port' => 6379],
    ])->and(FleetReachabilityProbe::targets(['DB_CONNECTION' => 'sqlite', 'DB_HOST' => 'x']))->toBe([]);
});

test('the probe never dials loopback or a host that is not plainly an address', function () {
    $remote = \Mockery::mock(ExecuteRemoteTaskOnServer::class);
    // Exactly one call: only the plain address may reach the shell.
    $remote->shouldReceive('runInlineBash')->once()->andReturn(new ProcessOutput('DPLY_REACH_OK', 0));
    app()->instance(ExecuteRemoteTaskOnServer::class, $remote);
    $probe = app(FleetReachabilityProbe::class);
    $host = Server::factory()->create();

    expect($probe->probe($host, ['name' => 'database', 'host' => '127.0.0.1', 'port' => 3306])['ok'])->toBeFalse()
        ->and($probe->probe($host, ['name' => 'database', 'host' => 'db;rm -rf /', 'port' => 3306])['ok'])->toBeFalse()
        ->and($probe->probe($host, ['name' => 'redis', 'host' => '10.0.0.6', 'port' => 6379])['ok'])->toBeTrue();
});
