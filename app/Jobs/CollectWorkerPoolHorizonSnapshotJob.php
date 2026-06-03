<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Server;
use App\Models\Site;
use App\Models\WorkerPool;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Builds a Horizon-style metrics snapshot for a worker pool by running a small
 * PHP script through the APP's own Horizon repositories over SSH (the app ships
 * laravel/horizon, so its classes + Redis connection are right there). Stores
 * the result on the pool meta (`meta.horizon`) for the pool's Horizon tab.
 *
 * Every field is wrapped in try/catch on the box, so a Horizon version that
 * lacks a given repository method degrades that field to null instead of
 * failing the whole snapshot. The JSON is fenced with markers so we can pluck
 * it cleanly out of tinker's output.
 */
class CollectWorkerPoolHorizonSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public string $poolId)
    {
        $this->onQueue('dply-control');
    }

    public function handle(ExecuteRemoteTaskOnServer $exec): void
    {
        $pool = WorkerPool::query()->with('servers')->find($this->poolId);
        if (! $pool instanceof WorkerPool) {
            return;
        }
        $primary = $pool->primaryServer ?? $pool->sourceServer;
        if (! $primary instanceof Server) {
            return;
        }
        $site = $this->appSite($primary);
        if (! $site instanceof Site) {
            return;
        }
        $dir = rtrim($site->effectiveEnvDirectory(), '/');

        try {
            $out = $exec->runInlineBash($primary, 'worker-pool:horizon-snapshot', $this->script($dir), timeoutSeconds: 60, asRoot: false);
            $snapshot = $this->extract((string) $out->buffer);
        } catch (\Throwable $e) {
            Log::info('worker-pool: horizon snapshot failed', ['pool_id' => $pool->id, 'error' => $e->getMessage()]);

            return;
        }

        if ($snapshot === null) {
            return;
        }

        $snapshot['collected_at'] = now()->toIso8601String();
        $meta = is_array($pool->meta) ? $pool->meta : [];
        $meta['horizon'] = $snapshot;
        $pool->forceFill(['meta' => $meta])->save();
    }

    private function appSite(Server $member): ?Site
    {
        $sites = $member->sites()->get();

        return $sites->first(fn (Site $s): bool => $s->isLaravelFrameworkDetected()) ?? $sites->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extract(string $buffer): ?array
    {
        if (! preg_match('/DPLY_HZ_START(.*)DPLY_HZ_END/s', $buffer, $m)) {
            return null;
        }
        $data = json_decode(trim($m[1]), true);

        return is_array($data) ? $data : null;
    }

    private function script(string $dir): string
    {
        // The PHP snippet runs inside `php artisan tinker` (app booted), reads
        // from Horizon's repositories + the failed_jobs table, and prints fenced
        // JSON. base64 avoids every layer of shell/tinker quoting.
        $php = <<<'PHP'
$T = function ($cb, $d = null) { try { return $cb(); } catch (\Throwable $e) { return $d; } };
$jr = $T(fn () => app(\Laravel\Horizon\Contracts\JobRepository::class));
$mr = $T(fn () => app(\Laravel\Horizon\Contracts\MetricsRepository::class));
$wr = $T(fn () => app(\Laravel\Horizon\Contracts\WorkloadRepository::class));
$msr = $T(fn () => app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class));
$sr = $T(fn () => app(\Laravel\Horizon\Contracts\SupervisorRepository::class));
$out = [];
$out['status'] = $T(fn () => collect($msr->all())->first()->status ?? 'inactive', 'unknown');
// Process counts live on the SUPERVISOR records (each ->processes is a
// queue => count map). The master record's ->supervisors is only a list of
// supervisor name strings, so summing through it always yields 0.
$out['processes'] = $T(fn () => (int) collect($sr->all())->sum(fn ($s) => collect((array) ($s->processes ?? []))->sum()), null);
$out['recent'] = $T(fn () => (int) $jr->countRecent(), null);
$out['completed'] = $T(fn () => (int) $jr->countCompleted(), null);
$out['pending'] = $T(fn () => (int) $jr->countPending(), null);
$out['failed_recent'] = $T(fn () => (int) $jr->countRecentlyFailed(), null);
$out['jobs_per_minute'] = $T(fn () => $mr->jobsProcessedPerMinute(), null);
// Horizon returns workload rows as ARRAYS and job records as OBJECTS, so read
// both shapes through one accessor — object access on an array silently yields
// null (every queue name → '?', every metric → '—').
$g = fn ($o, $k, $d = null) => is_array($o) ? ($o[$k] ?? $d) : ($o->$k ?? $d);
$out['workload'] = $T(fn () => collect($wr->get())->map(fn ($w) => ['name' => $g($w, 'name', '?'), 'length' => $g($w, 'length'), 'wait' => $g($w, 'wait'), 'processes' => $g($w, 'processes')])->values()->all(), []);
$jobRow = fn ($j) => ['name' => $g($j, 'name') ?: 'job', 'queue' => $g($j, 'queue', '?'), 'status' => $g($j, 'status', '?'), 'at' => $g($j, 'reserved_at', $g($j, 'completed_at'))];
$out['pending_jobs'] = $T(fn () => collect($jr->getPending())->take(25)->map($jobRow)->values()->all(), []);
$out['recent_jobs'] = $T(fn () => collect($jr->getRecent())->take(25)->map($jobRow)->values()->all(), []);
$out['failed_total'] = $T(fn () => (int) \Illuminate\Support\Facades\DB::table('failed_jobs')->count(), null);
$out['failed_jobs'] = $T(fn () => \Illuminate\Support\Facades\DB::table('failed_jobs')->orderByDesc('failed_at')->limit(25)->get()->map(function ($j) {
    $p = json_decode($j->payload, true) ?: [];
    $ex = (string) $j->exception;
    return ['uuid' => $j->uuid ?? null, 'name' => $p['displayName'] ?? ($p['job'] ?? 'job'), 'queue' => $j->queue ?? '?', 'tags' => array_slice($p['tags'] ?? [], 0, 6), 'failed_at' => (string) $j->failed_at, 'exception' => mb_substr(strtok($ex, "\n"), 0, 240), 'exception_full' => mb_substr($ex, 0, 6000)];
})->values()->all(), []);
echo 'DPLY_HZ_START'.json_encode($out).'DPLY_HZ_END';
PHP;

        $b64 = base64_encode($php);

        return 'cd '.escapeshellarg($dir).' 2>/dev/null || { echo "no app dir"; exit 0; }; '
            ."printf '%s' ".escapeshellarg($b64).' | base64 -d | php artisan tinker 2>/dev/null || echo "tinker failed"';
    }
}
