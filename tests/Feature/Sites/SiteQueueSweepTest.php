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
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Supervisor;
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

test('the on-box read reports only this server’s Horizon queues, with real numbers', function () {
    // Horizon hands back workload rows as arrays. Reading them as objects stored
    // pending 0 / processes 0 for every Horizon queue while Horizon itself said 15.
    // Its workload also spans every server sharing Redis: `reports` and `mail`
    // are other boxes' queues, and `default` has 5 more processes elsewhere.
    // `high,low` is one pool draining both; each is credited its processes.
    app()->instance(WorkloadRepository::class, new class implements WorkloadRepository
    {
        public function get()
        {
            return [
                ['name' => 'default', 'length' => 5, 'wait' => 12.4, 'processes' => 20, 'split_queues' => null],
                ['name' => 'reports', 'length' => 1, 'wait' => 1.0, 'processes' => 3, 'split_queues' => null],
                ['name' => 'high,low', 'length' => 7, 'wait' => 9.0, 'processes' => 4, 'split_queues' => [
                    ['name' => 'high', 'length' => 3, 'wait' => 2.0],
                    ['name' => 'low', 'length' => 4, 'wait' => 9.0],
                ]],
            ];
        }
    });

    app()->instance(SupervisorRepository::class, new class implements SupervisorRepository
    {
        public function all()
        {
            return [
                (object) ['name' => 'box-1-ab12:supervisor-1', 'master' => 'box-1-ab12', 'processes' => ['redis:default' => 15, 'redis:high,low' => 4]],
                // A hostname that merely starts with ours is another server.
                (object) ['name' => 'box-10-qq99:supervisor-1', 'master' => 'box-10-qq99', 'processes' => ['redis:mail' => 2]],
                (object) ['name' => 'box-2-wx34:supervisor-1', 'master' => 'box-2-wx34', 'processes' => ['redis:reports' => 3, 'redis:default' => 5]],
            ];
        }

        public function names() {}

        public function find($name) {}

        public function get(array $names) {}

        public function longestActiveTimeout() {}

        public function update(Supervisor $supervisor) {}

        public function forget($names) {}

        public function flushExpired() {}
    });

    MasterSupervisor::determineNameUsing(fn (): string => 'box-1');

    $job = new CollectServerQueueSnapshotsJob('01hzzzzzzzzzzzzzzzzzzzzzzz');
    $php = fn (string $method): string => (new ReflectionMethod($job, $method))->invoke($job);

    putenv('DPLY_Q_IN='.base64_encode((string) json_encode(['site_id' => 's1', 'queues' => ['default', 'emails']])));
    ob_start();
    eval($php('preludePhp').$php('readPhp'));
    $out = (string) ob_get_clean();
    putenv('DPLY_Q_IN');
    MasterSupervisor::determineNameUsing(fn (): string => Str::slug((string) gethostname()));

    $rows = collect((new ReflectionMethod($job, 'extract'))->invoke($job, $out)[0]['queues'])->keyBy('queue');

    // Depth is the framework's on every site; Horizon adds processes and its
    // time-to-clear estimate, which is no longer passed off as the oldest age.
    expect($rows['default'])->toMatchArray(['source' => 'horizon', 'time_to_clear_s' => 12.4, 'worker_processes' => 15])
        ->and($rows['default']['pending'])->toBeInt()
        ->and($rows['high'])->toMatchArray(['source' => 'horizon', 'time_to_clear_s' => 2.0, 'worker_processes' => 4])
        ->and($rows['low'])->toMatchArray(['source' => 'horizon', 'time_to_clear_s' => 9.0, 'worker_processes' => 4])
        ->and($rows)->not->toHaveKey('high,low')
        ->and($rows)->not->toHaveKey('reports')
        ->and($rows)->not->toHaveKey('mail')
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

/** @return array<string, array<string, mixed>> */
function targetsFor(Site $site): array
{
    $job = new CollectServerQueueSnapshotsJob((string) $site->server_id);

    return (new ReflectionMethod($job, 'targets'))->invoke($job, $site->server);
}

function publisherExpecting(?string $eventKey): void
{
    $publisher = Mockery::mock(NotificationPublisher::class);

    if ($eventKey === null) {
        $publisher->shouldNotReceive('publish');
    } else {
        $publisher->shouldReceive('publish')->once()->withArgs(fn (string $key): bool => $key === $eventKey);
    }

    app()->instance(NotificationPublisher::class, $publisher);
}

test('stopped workers and paused queues stay in the sweep', function () {
    // Pausing drops the queue from its worker's --queue=, and pausing a worker's
    // last queue switches the worker off — both used to fall out of the sweep.
    $site = queueSite();
    worker($site, 'php artisan queue:work --queue=reports')->update(['is_active' => false]);
    $site->putMeta('queue_paused', ['emails' => ['1' => 'php artisan queue:work --queue=emails']]);

    expect(targetsFor($site)[$site->id]['queues'])->toContain('reports', 'emails');
});

test('a paused queue is sampled but never pages no_worker', function () {
    $site = queueSite();
    $site->putMeta('queue_paused', ['emails' => ['1' => 'php artisan queue:work --queue=emails']]);
    publisherExpecting(null);

    sweep($site, "DPLY_SV_START\nDPLY_SV_END", [
        ['queue' => 'emails', 'source' => 'artisan', 'pending' => 6],
    ]);

    expect(processesFor($site, 'emails'))->toBe(0);
});

test('a running Horizon master that is not draining a queue is not its worker', function () {
    // Horizon's workload did not list `default` (wedged, or the queue is missing
    // from config/horizon.php), yet supervisord says the master is RUNNING.
    // Crediting that process kept no_worker silent while jobs sat.
    $site = queueSite();
    $program = worker($site, 'php artisan horizon');
    publisherExpecting('site.queue.no_worker');

    sweep($site, "DPLY_SV_START\ndply-sv-{$program->id}   RUNNING   pid 7, uptime 2:00:00\nDPLY_SV_END", [
        ['queue' => 'default', 'source' => 'artisan', 'pending' => 3],
    ]);

    expect(processesFor($site, 'default'))->toBe(0);
});

test('an app that does not answer is recorded as unreadable', function () {
    $site = queueSite();
    worker($site, 'php artisan queue:work');

    $exec = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $exec->shouldReceive('runInlineBash')->andReturn(new ProcessOutput("DPLY_SV_START\nDPLY_SV_END\n"));
    (new CollectServerQueueSnapshotsJob((string) $site->server_id))->handle($exec);

    expect(data_get($site->fresh()->meta, 'queue_read_error'))
        ->toMatchArray(['failures' => 1])
        ->and(data_get($site->fresh()->meta, 'queue_read_error.error'))->toContain('did not answer');
});

test('a site that stays unreadable pages once, and a good read clears it', function () {
    $site = queueSite();
    worker($site, 'php artisan queue:work');
    publisherExpecting('site.queue.unreadable');

    $exec = Mockery::mock(ExecuteRemoteTaskOnServer::class);
    $exec->shouldReceive('runInlineBash')->andThrow(new \RuntimeException('Connection refused'));
    $job = new CollectServerQueueSnapshotsJob((string) $site->server_id);

    // One miss is noise: recorded, not paged.
    $job->handle($exec);
    expect(data_get($site->fresh()->meta, 'queue_read_error.failures'))->toBe(1)
        ->and(data_get($site->fresh()->meta, 'queue_alerts.state', []))->not->toHaveKey('*|unreadable');

    // The second pages; the third is inside the cooldown, so still one page.
    $job->handle($exec);
    $job->handle($exec);
    expect(data_get($site->fresh()->meta, 'queue_alerts.state'))->toHaveKey('*|unreadable');

    sweep($site, "DPLY_SV_START\nDPLY_SV_END", [
        ['queue' => 'default', 'source' => 'artisan', 'pending' => 0],
    ]);

    $meta = $site->fresh()->meta;
    expect($meta)->not->toHaveKey('queue_read_error')
        ->and(data_get($meta, 'queue_alerts.state', []))->not->toHaveKey('*|unreadable');
});

function priorFailedCount(Site $site, string $queue, int $failed): void
{
    SiteQueueSnapshot::query()->create([
        'site_id' => $site->id,
        'queue' => $queue,
        'source' => 'artisan',
        'pending' => 0,
        'failed_total' => $failed,
        'captured_at' => now()->subMinutes(5),
    ]);
}

test('a burst of failures pages once, quoting the newest exception and linking to them', function () {
    $site = queueSite();
    worker($site, 'php artisan queue:work');
    priorFailedCount($site, 'default', 2);

    $publisher = Mockery::mock(NotificationPublisher::class);
    $publisher->shouldReceive('publish')->once()->withArgs(fn (string $key, $subject, string $title, string $body, string $url): bool => $key === 'site.queue.failures'
        && str_contains($body, '12 job(s) failed')
        && str_contains($body, 'RuntimeException: Stripe is down')
        && str_ends_with($url, '?activity=failed'));
    app()->instance(NotificationPublisher::class, $publisher);

    $row = ['queue' => 'default', 'source' => 'artisan', 'pending' => 0, 'failed' => 14, 'last_failure' => 'RuntimeException: Stripe is down in /app/Jobs/Charge.php:40'];
    sweep($site, "DPLY_SV_START\nDPLY_SV_END", [$row]);
    // Still failing on the next sweep: one problem, not a second page.
    sweep($site, "DPLY_SV_START\nDPLY_SV_END", [['failed' => 30] + $row]);
});

test('clearing failed jobs is not news, and a blank threshold switches the rule off', function () {
    $site = queueSite();
    worker($site, 'php artisan queue:work');
    publisherExpecting(null);

    // Someone ran queue:flush: the total dropped 40 → 0.
    priorFailedCount($site, 'default', 40);
    sweep($site, "DPLY_SV_START\nDPLY_SV_END", [['queue' => 'default', 'source' => 'artisan', 'pending' => 0, 'failed' => 0]]);

    $quiet = queueSite();
    worker($quiet, 'php artisan queue:work');
    $quiet->putMeta('queue_alerts', ['defaults' => ['failures_at_least' => null]]);
    priorFailedCount($quiet, 'default', 0);
    sweep($quiet, "DPLY_SV_START\nDPLY_SV_END", [['queue' => 'default', 'source' => 'artisan', 'pending' => 0, 'failed' => 500]]);
});

test('the sweep refreshes Horizon detail in the same session, page open or not', function () {
    $site = queueSite();
    worker($site, 'php artisan horizon');

    $job = new CollectServerQueueSnapshotsJob((string) $site->server_id);
    $script = (new ReflectionMethod($job, 'script'))->invoke($job, targetsFor($site));

    // A subshell, because the Horizon script exits when the app dir is gone.
    expect($script)->toContain('DPLY_HZSITE_START:'.$site->id)->toContain('( cd ');

    $horizon = json_encode(['status' => 'running', 'recent_jobs' => [['name' => 'App\\Jobs\\ChargeCard', 'status' => 'reserved']]]);
    sweep($site, "DPLY_SV_START\nDPLY_SV_END\nDPLY_HZSITE_START:{$site->id}\nDPLY_HZ_START{$horizon}DPLY_HZ_END\nDPLY_HZSITE_END", [
        ['queue' => 'default', 'source' => 'horizon', 'pending' => 0, 'worker_processes' => 4],
    ]);

    $meta = $site->fresh()->meta;
    expect(data_get($meta, 'horizon.status'))->toBe('running')
        ->and(data_get($meta, 'horizon.recent_jobs.0.name'))->toBe('App\\Jobs\\ChargeCard')
        ->and(data_get($meta, 'horizon.collected_at'))->not->toBeNull();
});

test('a site without Horizon does not pay for a Horizon read', function () {
    $site = queueSite();
    worker($site, 'php artisan queue:work');

    $job = new CollectServerQueueSnapshotsJob((string) $site->server_id);

    expect((new ReflectionMethod($job, 'script'))->invoke($job, targetsFor($site)))->not->toContain('DPLY_HZSITE_START');
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
