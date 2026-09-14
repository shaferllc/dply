<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\SiteQueueSnapshot;
use App\Models\SupervisorProgram;
use App\Models\WorkerPool;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Sites\SiteQueueAlertEvaluator;
use App\Services\Sites\SiteSystemdUnitBuilder;
use App\Services\WorkerPools\WorkerDaemonBackend;
use App\Support\Sites\QueueWorkerClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Snapshot every queue-bearing site on ONE server, in ONE SSH session.
 *
 * Per-site fan-out was the obvious shape and the wrong one: two sites on a box
 * means two connections every tick, and that multiplies by every site hosted.
 * The unit of work here is the server, so monitoring cost scales with servers
 * rather than with sites.
 *
 * Which queues to sample comes from dply, not from the app: the site's own
 * Supervisor programs and systemd units declare them via `--queue=`, so the box
 * is asked a specific question ("how deep is `emails`") instead of being asked
 * to enumerate, which no driver can do portably.
 */
class CollectServerQueueSnapshotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public string $serverId)
    {
        $this->onQueue('dply-control');
    }

    public function handle(ExecuteRemoteTaskOnServer $exec): void
    {
        $server = Server::query()->find($this->serverId);

        if (! $server instanceof Server || ! $server->isReady() || blank($server->ip_address)) {
            return;
        }

        $targets = $this->targets($server);

        if ($targets === []) {
            return;
        }

        try {
            $out = $exec->runInlineBash(
                $server,
                'site:queue-snapshot',
                $this->script($targets),
                timeoutSeconds: 120,
                asRoot: false,
            );
        } catch (\Throwable $e) {
            // One miss is noise — the next tick is five minutes away — but it is
            // recorded, because a server that stays down used to be silence
            // indistinguishable from health.
            Log::info('queue snapshot: exec failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);
            $this->recordReads($targets, [], __('Could not reach the server over SSH: :e', ['e' => Str::limit($e->getMessage(), 200)]));

            return;
        }

        $buffer = (string) $out->buffer;
        $payloads = $this->extract($buffer);

        $this->store($payloads, $targets, $this->liveness($buffer, $targets));
        $this->recordReads($targets, array_map(static fn (array $payload): string => (string) ($payload['site_id'] ?? ''), $payloads), __('The app did not answer — it failed to boot, or its directory is missing.'));
        $this->storeHorizon($buffer, $targets);
    }

    /**
     * @param  array<string, mixed>  $targets
     */
    private function storeHorizon(string $buffer, array $targets): void
    {
        if (preg_match_all('/DPLY_HZSITE_START:(\S+)\s(.*?)DPLY_HZSITE_END/s', $buffer, $blocks, PREG_SET_ORDER) === 0) {
            return;
        }

        foreach ($blocks as $block) {
            // Only sites this sweep asked about: the id comes back off the box.
            $site = isset($targets[$block[1]]) ? Site::query()->find($block[1]) : null;

            if ($site instanceof Site) {
                CollectSiteHorizonSnapshotJob::record($site, $block[2]);
            }
        }
    }

    /**
     * Sites on this server with at least one queue worker, the queues those
     * workers declare, and the workers themselves — so each queue's process
     * count can be credited to the daemons that actually drain it.
     *
     * A worker with no `--queue=` drains the app's default queue, which only
     * the app can resolve — 'default' is the right guess for every framework
     * dply supports, and a wrong guess costs one row of zeroes, not a failure.
     *
     * @return array<string, array{dir: string, queues: list<string>, workers: list<array{kind: string, name: string, queues: list<string>, horizon: bool}>}>
     */
    private function targets(Server $server): array
    {
        $targets = [];

        // Stopped workers too: a site whose last worker was switched off still
        // has jobs arriving, and dropping it from the sweep meant nobody was
        // told. A stopped program simply reports zero processes.
        $programs = SupervisorProgram::query()
            ->where('server_id', $server->id)
            ->whereNotNull('site_id')
            ->with('site')
            ->get();

        foreach ($programs as $program) {
            if (QueueWorkerClassifier::isQueueWorker($program->command)) {
                $this->addQueues($targets, $program->site, $this->queuesFrom((string) $program->command), [
                    'kind' => 'supervisor',
                    'name' => 'dply-sv-'.$program->id,
                    'horizon' => $this->isHorizon((string) $program->command),
                ]);
            }
        }

        // On Supervisor the units are gone and the same commands come back
        // above as mirrored programs — counting both would double them.
        $backend = app(WorkerDaemonBackend::class);
        $units = app(SiteSystemdUnitBuilder::class);

        $sites = Site::query()
            ->where('server_id', $server->id)
            ->whereHas('processes', fn ($query) => $query->where('type', '!=', SiteProcess::TYPE_WEB))
            ->with(['processes' => fn ($query) => $query->where('type', '!=', SiteProcess::TYPE_WEB)])
            ->get();

        foreach ($sites as $site) {
            if ($backend->backendFor($site) === WorkerPool::PM_SUPERVISOR) {
                continue;
            }

            foreach ($site->processes as $process) {
                if (QueueWorkerClassifier::isQueueWorker($process->command)) {
                    $this->addQueues($targets, $site, $this->queuesFrom((string) $process->command), [
                        'kind' => 'systemd',
                        'name' => $units->processUnitName($site, $process),
                        'horizon' => $this->isHorizon((string) $process->command),
                    ]);
                }
            }
        }

        // Pausing takes a queue out of its worker's `--queue=`, so no worker
        // declares it any more — yet a backlog building behind a pause is
        // exactly what someone needs to hear about.
        $pausedSites = Site::query()
            ->where('server_id', $server->id)
            ->whereNotNull('meta->queue_paused')
            ->get();

        foreach ($pausedSites as $site) {
            $this->addQueues($targets, $site, array_map('strval', array_keys((array) data_get($site->meta, 'queue_paused', []))), null);
        }

        return $targets;
    }

    /**
     * `--queue=high,default` is one process draining both in priority order;
     * each is its own row because each has its own depth.
     *
     * @return list<string>
     */
    private function queuesFrom(string $command): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', QueueWorkerClassifier::queueNameFrom($command) ?? 'default')),
            static fn (string $queue): bool => $queue !== '',
        ));
    }

    /**
     * A Horizon master is alive whether or not it drains a given queue, so its
     * process-manager status never counts as a worker for one — Horizon's own
     * workload answers that. Counting it let a wedged Horizon read as healthy.
     */
    private function isHorizon(string $command): bool
    {
        return str_contains(strtolower($command), 'horizon');
    }

    /**
     * @param  array<string, array{dir: string, queues: list<string>, workers: list<array{kind: string, name: string, queues: list<string>, horizon: bool}>}>  $targets
     * @param  list<string>  $queues
     * @param  array{kind: string, name: string, horizon: bool}|null  $worker
     */
    private function addQueues(array &$targets, ?Site $site, array $queues, ?array $worker): void
    {
        if (! $site instanceof Site || $queues === []) {
            return;
        }

        $dir = rtrim((string) $site->effectiveEnvDirectory(), '/');

        if ($dir === '') {
            return;
        }

        $target = $targets[$site->id] ?? ['dir' => $dir, 'queues' => [], 'workers' => []];
        $target['queues'] = array_values(array_unique([...$target['queues'], ...$queues]));

        if ($worker !== null) {
            $target['workers'][] = $worker + ['queues' => $queues];
        }

        $targets[$site->id] = $target;
    }

    /**
     * @param  array<string, array{dir: string, queues: list<string>, workers: list<array{kind: string, name: string, queues: list<string>, horizon: bool}>}>  $targets
     */
    private function script(array $targets): string
    {
        // Liveness is one server-level read, fenced apart from the per-site
        // payloads. sudo is probed up front rather than `sudo … || plain …`:
        // supervisorctl exits non-zero whenever any program is not RUNNING, so
        // the fallback would run too and print every line twice.
        $lines = [
            'echo DPLY_SV_START',
            'if sudo -n true 2>/dev/null; then sudo -n supervisorctl status 2>/dev/null || true; else supervisorctl status 2>/dev/null || true; fi',
            'echo DPLY_SV_END',
        ];

        $units = $this->systemdUnits($targets);

        if ($units !== []) {
            $lines[] = 'echo DPLY_SD_START';
            $lines[] = 'systemctl is-active '.implode(' ', array_map('escapeshellarg', $units)).' 2>/dev/null || true';
            $lines[] = 'echo DPLY_SD_END';
        }

        foreach ($targets as $siteId => $target) {
            $payload = base64_encode((string) json_encode([
                'site_id' => $siteId,
                'queues' => $target['queues'],
            ]));

            $php = base64_encode($this->remotePhp());

            // cd || continue: a site whose directory is gone must not abort the
            // snapshot for every other site sharing this connection.
            $lines[] = sprintf(
                'cd %s 2>/dev/null && DPLY_Q_IN=%s php -d error_reporting=0 -r "eval(base64_decode(\'%s\'));" 2>/dev/null || true',
                escapeshellarg($target['dir']),
                escapeshellarg($payload),
                $php,
            );

            // Horizon's detail — running jobs, recent history, throughput — rides
            // this same session, so it stays fresh with nobody on the page. In a
            // subshell: that script `exit`s when the app directory is missing,
            // which would end the sweep for every site after this one.
            // ponytail: one tinker boot per Horizon site inside the 120s exec
            // timeout; split the session if a box ever hosts dozens of them.
            if (collect($target['workers'])->contains(fn (array $worker): bool => $worker['horizon'])) {
                $lines[] = 'echo DPLY_HZSITE_START:'.$siteId;
                $lines[] = '( '.CollectWorkerPoolHorizonSnapshotJob::script($target['dir']).' ) || true';
                $lines[] = 'echo; echo DPLY_HZSITE_END';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array{dir: string, queues: list<string>, workers: list<array{kind: string, name: string, queues: list<string>, horizon: bool}>}>  $targets
     * @return list<string>
     */
    private function systemdUnits(array $targets): array
    {
        $units = [];

        foreach ($targets as $target) {
            foreach ($target['workers'] as $worker) {
                if ($worker['kind'] === 'systemd') {
                    $units[] = $worker['name'];
                }
            }
        }

        return array_values(array_unique($units));
    }

    /**
     * The snippet that runs inside each site's app directory.
     *
     * Boots the app through its own bootstrap so `Queue::size()` resolves the
     * site's real connection and driver — asking the framework beats
     * reimplementing per-driver depth queries that go stale with every Laravel
     * release. Every field is individually guarded: a site on an exotic driver
     * degrades that field to null instead of losing the whole snapshot.
     */
    private function remotePhp(): string
    {
        return $this->preludePhp().$this->bootPhp().$this->readPhp();
    }

    private function preludePhp(): string
    {
        return <<<'PHP'
$in = json_decode(base64_decode((string) getenv('DPLY_Q_IN')), true);
if (! is_array($in)) { return; }
$T = function ($cb, $d = null) { try { return $cb(); } catch (\Throwable $e) { return $d; } };

PHP;
    }

    private function bootPhp(): string
    {
        return <<<'PHP'
$app = $T(function () {
    require getcwd().'/vendor/autoload.php';
    $a = require getcwd().'/bootstrap/app.php';
    $a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    return $a;
});
if ($app === null) { return; }

PHP;
    }

    /**
     * Separate from the boot so a test can run it against a booted app.
     *
     * Depth comes from the framework for every site — `pendingSize`,
     * `delayedSize`, `reservedSize` and the oldest job's creation time exist on
     * every driver in current Laravel, so Horizon and plain queue:work sites
     * share one definition of "pending" and "oldest". An older app lacks them
     * and those fields stay null. Horizon adds only what it alone knows: its
     * process count and its time-to-clear estimate.
     *
     * Horizon's workload rows are ARRAYS; reading them as objects once stored a
     * confident zero for every Horizon queue. A field nobody answered stays
     * null, never zero.
     */
    private function readPhp(): string
    {
        return CollectWorkerPoolHorizonSnapshotJob::READ_EITHER_SHAPE_PHP."\n".<<<'PHP'
$horizon = $T(fn () => class_exists(\Laravel\Horizon\Horizon::class), false);
// The queues THIS box's Horizon supervises, and its processes on each. The
// workload repository reads the shared Redis: for an app whose Horizon runs on
// several servers it lists every server's queues and sums every server's
// processes, and this page is about one server. A master is named
// `<basename>-<4-char token>`; each of its supervisors records
// `connection:queue => processes`, where a pool's queue may be `high,default`.
$local = [];
if ($horizon) {
    $base = (string) $T(fn () => \Laravel\Horizon\MasterSupervisor::basename(), '');
    foreach ($T(fn () => app(\Laravel\Horizon\Contracts\SupervisorRepository::class)->all(), []) as $s) {
        if ($base === '' || ! preg_match('/^'.preg_quote($base, '/').'-[A-Za-z0-9]{4}$/', (string) $g($s, 'master'))) {
            continue;
        }
        foreach ((array) $g($s, 'processes') as $pool => $count) {
            foreach (explode(',', (string) (explode(':', (string) $pool, 2)[1] ?? '')) as $q) {
                if (($q = trim($q)) !== '') {
                    $local[$q] = ($local[$q] ?? 0) + (int) $count;
                }
            }
        }
    }
}
// Horizon's time-to-clear estimate per queue; a grouped workload names its
// members in split_queues.
$wait = [];
foreach (($local !== [] ? $T(fn () => app(\Laravel\Horizon\Contracts\WorkloadRepository::class)->get(), []) : []) as $w) {
    $split = $g($w, 'split_queues');
    foreach (($split ? collect($split)->all() : [$w]) as $part) {
        $wait[(string) $g($part, 'name')] = $g($part, 'wait');
    }
}
$workload = [];
foreach ($local as $q => $processes) {
    $workload[$q] = ['processes' => $processes, 'wait' => $wait[$q] ?? null];
}
$conn = $T(fn () => app('queue')->connection());
$ask = fn (string $method, string $queue) => $conn !== null && method_exists($conn, $method) ? $T(fn () => $conn->$method($queue)) : null;
$failer = $T(fn () => app('queue.failer'));
// An older failer's count() takes no queue; passed one anyway it would report
// the site's total as every queue's.
$perQueueFailed = $T(fn () => (new \ReflectionMethod($failer, 'count'))->getNumberOfParameters() >= 2, false);
$rows = [];
foreach (array_values(array_unique([...(array) $in['queues'], ...array_keys($workload)])) as $queue) {
    $w = $workload[$queue] ?? null;
    $oldest = $ask('creationTimeOfOldestPendingJob', $queue);
    $failed = $perQueueFailed ? $T(fn () => $failer->count(null, $queue)) : null;
    // The newest failure's first line, for the alert. Only table-backed
    // failers have one to query, and only when there is a failure at all.
    $lastFailure = $failed ? $T(fn () => strtok((string) \Illuminate\Support\Facades\DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))->where('queue', $queue)->orderByDesc('failed_at')->value('exception'), "\n")) : null;
    $rows[] = [
        'queue' => $queue,
        'source' => $w !== null ? 'horizon' : 'artisan',
        'pending' => $ask('pendingSize', $queue) ?? $T(fn () => \Illuminate\Support\Facades\Queue::size($queue)),
        'delayed' => $ask('delayedSize', $queue),
        'reserved' => $ask('reservedSize', $queue),
        // Both clocks are this box's: the job was stamped by this app.
        'oldest_pending_age_s' => is_numeric($oldest) ? max(0, time() - (int) $oldest) : null,
        'time_to_clear_s' => $w['wait'] ?? null,
        'worker_processes' => $w['processes'] ?? null,
        'failed' => $failed,
        'last_failure' => is_string($lastFailure) ? mb_substr($lastFailure, 0, 250) : null,
    ];
}
echo 'DPLY_Q_START'.json_encode(['site_id' => $in['site_id'], 'queues' => $rows])."DPLY_Q_END\n";
PHP;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extract(string $buffer): array
    {
        if (preg_match_all('/DPLY_Q_START(.*?)DPLY_Q_END/s', $buffer, $matches) !== false) {
            return array_values(array_filter(array_map(
                static fn (string $json): mixed => json_decode(trim($json), true),
                $matches[1],
            ), 'is_array'));
        }

        return [];
    }

    /**
     * What the process managers say is running: supervisor group => RUNNING
     * process count, systemd unit => active. Null for a manager that could not
     * be read — unknown, which must never be mistaken for "nothing running".
     *
     * @param  array<string, array{dir: string, queues: list<string>, workers: list<array{kind: string, name: string, queues: list<string>, horizon: bool}>}>  $targets
     * @return array{supervisor: ?array<string, int>, systemd: ?array<string, bool>}
     */
    private function liveness(string $buffer, array $targets): array
    {
        $supervisor = null;

        // Any recognisable status line proves supervisord answered. A group of
        // numprocs > 1 reports as `dply-sv-7:dply-sv-7_00`, so the group name
        // is the part before the colon — never compared against the full name.
        if (preg_match('/DPLY_SV_START(.*?)DPLY_SV_END/s', $buffer, $block) === 1
            && preg_match_all('/^([^\s:]+)(?::\S+)?\s+(RUNNING|STARTING|BACKOFF|STOPPING|STOPPED|EXITED|FATAL|UNKNOWN)\b/m', $block[1], $rows, PREG_SET_ORDER) > 0) {
            $supervisor = [];

            foreach ($rows as $row) {
                $supervisor[$row[1]] = ($supervisor[$row[1]] ?? 0) + ($row[2] === 'RUNNING' ? 1 : 0);
            }
        }

        $systemd = null;
        $units = $this->systemdUnits($targets);

        // `systemctl is-active a b` answers one line per unit, in order; any
        // other count means the output cannot be lined up with the units.
        if ($units !== [] && preg_match('/DPLY_SD_START(.*?)DPLY_SD_END/s', $buffer, $block) === 1) {
            $states = array_values(array_filter(array_map('trim', explode("\n", $block[1])), static fn (string $line): bool => $line !== ''));

            if (count($states) === count($units)) {
                $systemd = array_combine($units, array_map(static fn (string $state): bool => $state === 'active', $states));
            }
        }

        return ['supervisor' => $supervisor, 'systemd' => $systemd];
    }

    /**
     * Processes draining one queue, summed over every worker that declares it.
     * Null when any of those workers could not be read: a partial sum would
     * report fewer workers than exist, and zero is what pages someone.
     *
     * @param  array{dir: string, queues: list<string>, workers: list<array{kind: string, name: string, queues: list<string>, horizon: bool}>}  $target
     * @param  array{supervisor: ?array<string, int>, systemd: ?array<string, bool>}  $liveness
     */
    private function workerProcesses(array $target, string $queue, array $liveness): ?int
    {
        $total = 0;

        foreach ($target['workers'] as $worker) {
            if (! in_array($queue, $worker['queues'], true) || $worker['horizon']) {
                continue;
            }

            $read = $liveness[$worker['kind']] ?? null;

            if ($read === null) {
                return null;
            }

            $total += $worker['kind'] === 'supervisor'
                ? (int) ($read[$worker['name']] ?? 0)
                : (int) ($read[$worker['name']] ?? false);
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @param  array<string, array{dir: string, queues: list<string>, workers: list<array{kind: string, name: string, queues: list<string>, horizon: bool}>}>  $targets
     * @param  array{supervisor: ?array<string, int>, systemd: ?array<string, bool>}  $liveness
     */
    private function store(array $payloads, array $targets, array $liveness): void
    {
        $capturedAt = now();
        $touchedSiteIds = [];

        foreach ($payloads as $payload) {
            $siteId = (string) ($payload['site_id'] ?? '');

            if ($siteId === '') {
                continue;
            }

            $touchedSiteIds[] = $siteId;

            foreach ((array) ($payload['queues'] ?? []) as $row) {
                if (! is_array($row) || ! is_string($row['queue'] ?? null)) {
                    continue;
                }

                $source = in_array($row['source'] ?? '', ['horizon', 'artisan', 'pool'], true)
                    ? $row['source']
                    : SiteQueueSnapshot::SOURCE_ARTISAN;

                // Horizon counts its own processes per queue; anything else
                // is answered by the process manager running the workers.
                $processes = $source === SiteQueueSnapshot::SOURCE_HORIZON || ! isset($targets[$siteId])
                    ? $this->int($row['worker_processes'] ?? null)
                    : $this->workerProcesses($targets[$siteId], $row['queue'], $liveness);

                SiteQueueSnapshot::query()->create([
                    'site_id' => $siteId,
                    'queue' => $row['queue'],
                    'source' => $source,
                    'pending' => $this->int($row['pending'] ?? null),
                    'delayed' => $this->int($row['delayed'] ?? null),
                    'reserved' => $this->int($row['reserved'] ?? null),
                    'oldest_pending_age_s' => $this->int($row['oldest_pending_age_s'] ?? null),
                    'time_to_clear_s' => $this->int($row['time_to_clear_s'] ?? null),
                    'worker_processes' => $processes,
                    'failed_total' => $this->int($row['failed'] ?? null),
                    'last_failure' => is_string($row['last_failure'] ?? null) && trim($row['last_failure']) !== ''
                        ? mb_substr(trim($row['last_failure']), 0, 255)
                        : null,
                    'captured_at' => $capturedAt,
                ]);
            }
        }

        $this->evaluateAlerts($touchedSiteIds);
    }

    /**
     * Judge what was just measured.
     *
     * Here rather than in its own scheduled pass: a separate job would re-read
     * these same rows minutes later and alert on a state that had already
     * changed. Never lets an alert failure lose the snapshot — the reading is
     * the product, the notification is a courtesy on top of it.
     *
     * @param  list<string>  $siteIds
     */
    private function evaluateAlerts(array $siteIds): void
    {
        if ($siteIds === []) {
            return;
        }

        $evaluator = app(SiteQueueAlertEvaluator::class);

        foreach (Site::query()->whereIn('id', array_unique($siteIds))->get() as $site) {
            $this->judge($site, fn () => $evaluator->evaluate($site));
        }
    }

    /**
     * Remember which sites could not be read, so the page says so instead of
     * showing stale numbers as current, and page once a site stays unreadable.
     * A site that answered clears its record.
     *
     * @param  array<string, mixed>  $targets
     * @param  list<string>  $answered
     */
    private function recordReads(array $targets, array $answered, string $error): void
    {
        $evaluator = app(SiteQueueAlertEvaluator::class);

        foreach (Site::query()->whereIn('id', array_keys($targets))->get() as $site) {
            $previous = data_get($site->meta, 'queue_read_error');

            if (in_array((string) $site->id, $answered, true)) {
                if ($previous !== null) {
                    $site->putMeta('queue_read_error', null);
                }

                continue;
            }

            $site->putMeta('queue_read_error', [
                'error' => $error,
                'failures' => (int) data_get($previous, 'failures', 0) + 1,
                'since' => data_get($previous, 'since') ?? now()->toIso8601String(),
            ]);

            $this->judge($site, fn () => $evaluator->evaluateUnreadable($site));
        }
    }

    /**
     * A manual refresh can land on top of the scheduled sweep; the lock stops
     * both reading the same unfired state and paging twice. An alert failure
     * never takes the sweep down with it.
     */
    private function judge(Site $site, \Closure $check): void
    {
        try {
            Cache::lock('site-queue-alerts:'.$site->id, 60)->get($check);
        } catch (\Throwable $e) {
            Log::info('queue alerts: evaluation failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) round((float) $value)) : null;
    }
}
