<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteQueueSnapshot;
use App\Models\SupervisorProgram;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Sites\QueueWorkerClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Snapshot every queue-bearing site on ONE server, in ONE SSH session.
 *
 * Per-site fan-out was the obvious shape and the wrong one: two sites on a box
 * means two connections every tick, and that multiplies by every site hosted.
 * The unit of work here is the server, so monitoring cost scales with servers
 * rather than with sites.
 *
 * Which queues to sample comes from dply, not from the app: the site's own
 * Supervisor programs declare them via `--queue=`, so the box is asked a
 * specific question ("how deep is `emails`") instead of being asked to
 * enumerate, which no driver can do portably.
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
            // A server that is down or mid-reboot is not a snapshot failure
            // worth surfacing — the next tick is five minutes away.
            Log::info('queue snapshot: exec failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return;
        }

        $this->store($this->extract((string) $out->buffer));
    }

    /**
     * Sites on this server with at least one queue worker, and the queues those
     * workers declare.
     *
     * A worker with no `--queue=` drains the app's default queue, which only
     * the app can resolve — 'default' is the right guess for every framework
     * dply supports, and a wrong guess costs one row of zeroes, not a failure.
     *
     * @return array<string, array{dir: string, queues: list<string>}>
     */
    private function targets(Server $server): array
    {
        $programs = SupervisorProgram::query()
            ->where('server_id', $server->id)
            ->whereNotNull('site_id')
            ->where('is_active', true)
            ->get();

        $targets = [];

        foreach ($programs as $program) {
            if (! QueueWorkerClassifier::isQueueWorker($program->command)) {
                continue;
            }

            $site = $program->site;

            if (! $site instanceof Site) {
                continue;
            }

            $dir = rtrim((string) $site->effectiveEnvDirectory(), '/');

            if ($dir === '') {
                continue;
            }

            $queues = $targets[$site->id]['queues'] ?? [];

            // `--queue=high,default` is one process draining both in priority
            // order; each is its own row because each has its own depth.
            foreach (explode(',', QueueWorkerClassifier::queueNameFrom($program->command) ?? 'default') as $queue) {
                $queue = trim($queue);

                if ($queue !== '') {
                    $queues[] = $queue;
                }
            }

            $targets[$site->id] = [
                'dir' => $dir,
                'queues' => array_values(array_unique($queues)),
            ];
        }

        return $targets;
    }

    /**
     * @param  array<string, array{dir: string, queues: list<string>}>  $targets
     */
    private function script(array $targets): string
    {
        $lines = [];

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
        }

        return implode("\n", $lines);
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
        return <<<'PHP'
$in = json_decode(base64_decode((string) getenv('DPLY_Q_IN')), true);
if (! is_array($in)) { return; }
$T = function ($cb, $d = null) { try { return $cb(); } catch (\Throwable $e) { return $d; } };
$app = $T(function () {
    require getcwd().'/vendor/autoload.php';
    $a = require getcwd().'/bootstrap/app.php';
    $a->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    return $a;
});
if ($app === null) { return; }
$horizon = $T(fn () => class_exists(\Laravel\Horizon\Horizon::class), false);
$workload = $horizon ? $T(fn () => collect(app(\Laravel\Horizon\Contracts\WorkloadRepository::class)->get())->keyBy('name'), null) : null;
$failed = $T(fn () => (int) app('queue.failer')->count(), null);
$rows = [];
foreach ((array) $in['queues'] as $queue) {
    $w = $workload?->get($queue);
    $rows[] = [
        'queue' => $queue,
        'source' => $w !== null ? 'horizon' : 'artisan',
        'pending' => $w !== null ? (int) ($w->length ?? 0) : $T(fn () => (int) \Illuminate\Support\Facades\Queue::size($queue), null),
        'oldest_pending_age_s' => $w !== null ? $T(fn () => (int) round((float) ($w->wait ?? 0)), null) : null,
        'worker_processes' => $w !== null ? $T(fn () => (int) ($w->processes ?? 0), null) : null,
    ];
}
echo 'DPLY_Q_START'.json_encode(['site_id' => $in['site_id'], 'failed_total' => $failed, 'queues' => $rows])."DPLY_Q_END\n";
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
                $matches[1] ?? [],
            ), 'is_array'));
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    private function store(array $payloads): void
    {
        $capturedAt = now();

        foreach ($payloads as $payload) {
            $siteId = (string) ($payload['site_id'] ?? '');

            if ($siteId === '') {
                continue;
            }

            foreach ((array) ($payload['queues'] ?? []) as $row) {
                if (! is_array($row) || ! is_string($row['queue'] ?? null)) {
                    continue;
                }

                SiteQueueSnapshot::query()->create([
                    'site_id' => $siteId,
                    'queue' => $row['queue'],
                    'source' => in_array($row['source'] ?? '', ['horizon', 'artisan', 'pool'], true)
                        ? $row['source']
                        : SiteQueueSnapshot::SOURCE_ARTISAN,
                    'pending' => $this->int($row['pending'] ?? null),
                    'oldest_pending_age_s' => $this->int($row['oldest_pending_age_s'] ?? null),
                    'worker_processes' => $this->int($row['worker_processes'] ?? null),
                    'failed_total' => $this->int($payload['failed_total'] ?? null),
                    'captured_at' => $capturedAt,
                ]);
            }
        }
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
