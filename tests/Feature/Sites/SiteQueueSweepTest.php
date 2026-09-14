<?php

declare(strict_types=1);

namespace Tests\Feature\Sites\SiteQueueSweepTest;

use App\Jobs\CollectServerQueueSnapshotsJob;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\SiteQueueSnapshot;
use App\Models\SupervisorProgram;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Mockery;
use ReflectionMethod;

uses(RefreshDatabase::class);

function queueSite(): Site
{
    $org = Organization::factory()->create();
    $server = Server::factory()->ready()->create(['organization_id' => $org->id]);

    return Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);
}

function worker(Site $site, string $command, int $numprocs = 1): SupervisorProgram
{
    return SupervisorProgram::query()->create([
        'server_id' => $site->server_id,
        'site_id' => $site->id,
        'slug' => 'worker-'.fake()->unique()->numberBetween(1, 99999),
        'program_type' => 'queue',
        'command' => $command,
        'directory' => '/home/dply/app',
        'user' => 'dply',
        'numprocs' => $numprocs,
        'is_active' => true,
    ]);
}

/** @param  list<array<string, mixed>>  $queues */
function sweep(Site $site, string $liveness, array $queues): void
{
    $payload = json_encode(['site_id' => $site->id, 'failed_total' => 0, 'queues' => $queues]);

    $exec = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $exec->shouldReceive('runInlineBash')->andReturn(new ProcessOutput($liveness."\nDPLY_Q_START{$payload}DPLY_Q_END\n"));

    (new CollectServerQueueSnapshotsJob((string) $site->server_id))->handle($exec);
}

function processesFor(Site $site, string $queue): ?int
{
    return SiteQueueSnapshot::query()->where('site_id', $site->id)->where('queue', $queue)->latest('captured_at')->value('worker_processes');
}

test('the on-box read reports Horizon array workloads as real numbers, not zeros', function () {
    // Horizon hands back workload rows as arrays. Reading them as objects stored
    // pending 0 / processes 0 for every Horizon queue while Horizon itself said 15.
    // `high,low` is a grouped workload: only Horizon knows about it (no worker
    // declares it), and each member queue is drained by the group's processes.
    app()->instance(WorkloadRepository::class, new class implements WorkloadRepository
    {
        public function get()
        {
            return [
                ['name' => 'default', 'length' => 5, 'wait' => 12.4, 'processes' => 15, 'split_queues' => null],
                ['name' => 'high,low', 'length' => 7, 'wait' => 9.0, 'processes' => 4, 'split_queues' => [
                    ['name' => 'high', 'length' => 3, 'wait' => 2.0],
                    ['name' => 'low', 'length' => 4, 'wait' => 9.0],
                ]],
            ];
        }
    });

    $job = new CollectServerQueueSnapshotsJob('01hzzzzzzzzzzzzzzzzzzzzzzz');
    $php = fn (string $method): string => (new ReflectionMethod($job, $method))->invoke($job);

    putenv('DPLY_Q_IN='.base64_encode((string) json_encode(['site_id' => 's1', 'queues' => ['default', 'emails']])));
    ob_start();
    eval($php('preludePhp').$php('readPhp'));
    $out = (string) ob_get_clean();
    putenv('DPLY_Q_IN');

    $rows = collect((new ReflectionMethod($job, 'extract'))->invoke($job, $out)[0]['queues'])->keyBy('queue');

    // Depth is the framework's on every site; Horizon adds processes and its
    // time-to-clear estimate, which is no longer passed off as the oldest age.
    expect($rows['default'])->toMatchArray(['source' => 'horizon', 'time_to_clear_s' => 12.4, 'worker_processes' => 15])
        ->and($rows['default']['pending'])->toBeInt()
        ->and($rows['high'])->toMatchArray(['source' => 'horizon', 'time_to_clear_s' => 2.0, 'worker_processes' => 4])
        ->and($rows['low'])->toMatchArray(['source' => 'horizon', 'time_to_clear_s' => 9.0, 'worker_processes' => 4])
        ->and($rows)->not->toHaveKey('high,low')
        // Not in Horizon's workload: the process count is left for the
        // process manager to answer, and there is no Horizon estimate.
        ->and($rows['emails'])->toMatchArray(['source' => 'artisan', 'worker_processes' => null, 'time_to_clear_s' => null]);
});

test('running supervisor processes are credited to every queue the program drains', function () {
    $site = queueSite();
    $program = worker($site, 'php artisan queue:work --queue=high,default', numprocs: 2);
    $name = 'dply-sv-'.$program->id;

    sweep($site, implode("\n", [
        'DPLY_SV_START',
        "{$name}:{$name}_00   RUNNING   pid 101, uptime 1:00:00",
        "{$name}:{$name}_01   RUNNING   pid 102, uptime 1:00:00",
        'dply-sv-999999   FATAL   Exited too quickly',
        'DPLY_SV_END',
    ]), [
        ['queue' => 'high', 'source' => 'artisan', 'pending' => 3, 'worker_processes' => null],
        ['queue' => 'default', 'source' => 'artisan', 'pending' => 0, 'worker_processes' => null],
    ]);

    expect(processesFor($site, 'high'))->toBe(2)
        ->and(processesFor($site, 'default'))->toBe(2);
});

test('each queue row keeps its own running, delayed and failed counts', function () {
    $site = queueSite();
    worker($site, 'php artisan queue:work --queue=emails,default');

    sweep($site, "DPLY_SV_START\nDPLY_SV_END", [
        ['queue' => 'emails', 'source' => 'horizon', 'pending' => 2, 'delayed' => 5, 'reserved' => 1, 'oldest_pending_age_s' => 40, 'time_to_clear_s' => 7.6, 'worker_processes' => 3, 'failed' => 9],
        ['queue' => 'default', 'source' => 'horizon', 'pending' => 0, 'delayed' => null, 'reserved' => 0, 'oldest_pending_age_s' => null, 'time_to_clear_s' => 0, 'worker_processes' => 3, 'failed' => 1],
    ]);

    $rows = SiteQueueSnapshot::query()->where('site_id', $site->id)->get()->keyBy('queue');

    expect($rows['emails']->only(['delayed', 'reserved', 'oldest_pending_age_s', 'time_to_clear_s', 'failed_total']))
        ->toBe(['delayed' => 5, 'reserved' => 1, 'oldest_pending_age_s' => 40, 'time_to_clear_s' => 8, 'failed_total' => 9])
        // The failer's count is per queue now, not the site's total on every row.
        ->and($rows['default']->failed_total)->toBe(1)
        ->and($rows['default']->delayed)->toBeNull();
});

test('jobs waiting behind a stopped worker page no_worker', function () {
    $site = queueSite();
    $program = worker($site, 'php artisan queue:work');

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->once()->withArgs(fn (string $eventKey): bool => $eventKey === 'site.queue.no_worker');
    app()->instance(NotificationPublisher::class, $publisher);

    sweep($site, "DPLY_SV_START\ndply-sv-{$program->id}   STOPPED   Not started\nDPLY_SV_END", [
        ['queue' => 'default', 'source' => 'artisan', 'pending' => 4, 'worker_processes' => null],
    ]);

    expect(processesFor($site, 'default'))->toBe(0)
        ->and(data_get($site->fresh()->meta, 'queue_alerts.state'))->toHaveKey('default|no_worker');
});

test('an unreadable process manager is unknown, and unknown never pages no_worker', function () {
    // The deploy user cannot reach supervisord's socket: nothing parseable comes
    // back. Storing 0 here is exactly what paged every healthy queue:work site.
    $site = queueSite();
    worker($site, 'php artisan queue:work');

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldNotReceive('publish');
    app()->instance(NotificationPublisher::class, $publisher);

    sweep($site, "DPLY_SV_START\nunix:///var/run/supervisor.sock refused connection\nDPLY_SV_END", [
        ['queue' => 'default', 'source' => 'artisan', 'pending' => 4, 'worker_processes' => null],
    ]);

    expect(processesFor($site, 'default'))->toBeNull();
});

test('systemd worker units are swept and counted', function () {
    $site = queueSite();
    SiteProcess::factory()->create(['site_id' => $site->id, 'type' => SiteProcess::TYPE_WORKER, 'name' => 'queue', 'command' => 'php artisan queue:work', 'is_active' => true]);

    sweep($site, "DPLY_SV_START\nDPLY_SV_END\nDPLY_SD_START\nactive\nDPLY_SD_END", [
        ['queue' => 'default', 'source' => 'artisan', 'pending' => 0, 'worker_processes' => null],
    ]);

    expect(processesFor($site, 'default'))->toBe(1);
});

test('putMeta writes one key without reverting what a stale copy never saw', function () {
    $site = queueSite();
    $site->forceFill(['meta' => null])->save();

    // Loaded before the other writes land — a Livewire page left open.
    $stale = Site::query()->findOrFail($site->id);

    $site->putMeta('horizon', ['status' => 'running']);
    $site->putMeta('queue_paused', ['emails' => ['1' => 'cmd']]);
    $stale->putMeta('queue_alerts', ['enabled' => false]);
    $site->putMeta('queue_paused', null);

    expect($site->fresh()->meta)->toBe([
        'horizon' => ['status' => 'running'],
        'queue_alerts' => ['enabled' => false],
    ])->and($stale->meta)->toHaveKey('queue_alerts');
});
