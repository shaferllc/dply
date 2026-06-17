<?php

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Server;
use App\Models\WorkerPool;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Gathers per-member host + worker + Redis stats for a worker pool over SSH and
 * stashes them on each member's meta (`meta.pool.stats`) so the pool workspace's
 * Traffic tab can render live throughput/health without blocking the request.
 *
 * Best-effort: a member that can't be reached just keeps its previous snapshot.
 * Each probe is one inline bash call that prints KEY=VALUE lines; every value is
 * guarded with `|| true`/`|| echo` so a missing tool (no redis-cli, no
 * supervisorctl) degrades to a blank field instead of aborting the probe.
 */
class CollectWorkerPoolStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    private ?Server $resolvedSubject = null;

    public function __construct(public string $poolId)
    {
        $this->onQueue('dply-control');
    }

    protected function consoleSubject(): Model
    {
        if ($this->resolvedSubject instanceof Server) {
            return $this->resolvedSubject;
        }
        $pool = WorkerPool::query()->with('servers')->find($this->poolId);
        if ($pool === null) {
            return $this->resolvedSubject = new Server;
        }
        $server = $pool->primaryServer ?? $pool->sourceServer;

        return $this->resolvedSubject = ($server instanceof Server ? $server : new Server);
    }

    protected function consoleKind(): string
    {
        return 'worker_pool_stats';
    }

    public function handle(ExecuteRemoteTaskOnServer $exec): void
    {
        $pool = WorkerPool::query()->with('servers')->find($this->poolId);
        if (! $pool instanceof WorkerPool) {
            return;
        }
        $this->resolvedSubject = $pool->primaryServer ?? $pool->sourceServer;

        $emit = $this->beginConsoleAction();

        try {
            foreach ($pool->servers as $member) {
                if (! $member->isReady()) {
                    $emit->warn(sprintf('%s — not ready, skipped', $member->name), 'stats');

                    continue;
                }

                $dir = $this->appSiteDir($member);
                $emit->step('stats', sprintf('probing %s (app dir: %s)', $member->name, $dir ?: '—'));

                try {
                    [$stats, $raw] = $this->probe($exec, $member, $dir);
                } catch (\Throwable $e) {
                    Log::info('worker-pool: stats probe failed', ['server_id' => $member->id, 'error' => $e->getMessage()]);
                    $emit->error(sprintf('%s probe failed: %s', $member->name, $e->getMessage()), 'stats');

                    continue;
                }

                // Surface the raw probe output so an operator can SEE why Redis
                // is down (connection refused / auth / TLS) instead of guessing.
                foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
                    if (trim($line) !== '') {
                        $emit($member->name.': '.$line, 'info', 'stats');
                    }
                }
                $redis = $stats['redis_ping'] ?? '';
                $emit($redis === 'PONG' ? sprintf('%s — Redis OK', $member->name) : sprintf('%s — Redis %s', $member->name, $redis ?: 'unknown'),
                    $redis === 'PONG' ? 'success' : 'warn', 'stats');

                $meta = is_array($member->meta) ? $member->meta : [];
                $meta['pool'] = array_merge($meta['pool'] ?? [], ['stats' => $stats]);
                $member->forceFill(['meta' => $meta])->save();
            }

            $this->completeConsoleAction();
        } catch (\Throwable $e) {
            $emit->error('Stats collection failed: '.$e->getMessage(), 'stats');
            $this->failConsoleAction($e->getMessage());

            throw $e;
        }
    }

    /**
     * Resolve the on-box app directory (where .env lives + artisan runs) for a
     * member's Laravel app site, so the probe can query the REAL queue backend.
     */
    private function appSiteDir(Server $member): string
    {
        $sites = $member->sites()->get();
        $site = $sites->first(fn ($s): bool => $s->isLaravelFrameworkDetected()) ?? $sites->first();

        return $site !== null ? rtrim($site->effectiveEnvDirectory(), '/') : '';
    }

    /**
     * @return array{0: array<string, mixed>, 1: string} [parsed stats, raw probe output]
     */
    private function probe(ExecuteRemoteTaskOnServer $exec, Server $member, string $siteDir): array
    {
        // Host metrics + worker-process detection come from bash. Redis + queue
        // come from the APP ITSELF (Redis::connection()->info() / Queue::size())
        // — not redis-cli — so the exact host/port/password/TLS the app uses is
        // honoured. redis-cli is often absent (the app uses phpredis) or can't
        // reach/auth a private/TLS redis, which is why it showed "down".
        $redisPhp = <<<'PHP'
$T = function ($cb, $d = '') { try { return $cb(); } catch (\Throwable $e) { return $d; } };
$r = $T(fn () => \Illuminate\Support\Facades\Redis::connection(), null);
echo "\nREDIS_PING=".$T(function () use ($r) { if (! $r) { return 'down'; } $r->ping(); return 'PONG'; }, 'down');
$info = $T(fn () => $r ? $r->info() : [], []);
$flat = [];
if (is_array($info)) { array_walk_recursive($info, function ($v, $k) use (&$flat) { $flat[$k] = $v; }); }
echo "\nREDIS_MEM=".($flat['used_memory_human'] ?? '');
echo "\nREDIS_PEAK=".($flat['used_memory_peak_human'] ?? '');
echo "\nREDIS_CLIENTS=".($flat['connected_clients'] ?? '');
echo "\nREDIS_OPS=".($flat['instantaneous_ops_per_sec'] ?? '');
echo "\nREDIS_TOTAL_CMDS=".($flat['total_commands_processed'] ?? '');
echo "\nREDIS_UPTIME=".($flat['uptime_in_seconds'] ?? '');
echo "\nREDIS_KEYS=".$T(fn () => (string) ($r ? $r->dbSize() : ''), '');
echo "\nQUEUE_SIZE=".$T(fn () => (string) \Illuminate\Support\Facades\Queue::size(), '');
echo "\n";
PHP;
        $redisB64 = base64_encode($redisPhp);

        $probe = <<<BASH
echo "LOAD=\$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null || true)"
echo "CPUS=\$(nproc 2>/dev/null || true)"
echo "MEM=\$(free -m 2>/dev/null | awk '/^Mem:/{print \$3"/"\$2}' || true)"
echo "DISK=\$(df -h / 2>/dev/null | awk 'NR==2{print \$3"/"\$2" "\$5}' || true)"
echo "UPTIME=\$(uptime -p 2>/dev/null | sed 's/^up //' || true)"
echo "HORIZON_PROCS=\$(pgrep -fc 'artisan horizon' 2>/dev/null || echo 0)"
echo "QUEUE_PROCS=\$(pgrep -fc 'artisan queue:work' 2>/dev/null || echo 0)"
echo "SYSTEMD_WORKERS=\$(systemctl list-units 'dply-site-*.service' --no-legend --state=running 2>/dev/null | wc -l | tr -d ' ' || echo 0)"
echo "SV_RUNNING=\$( (sudo -n supervisorctl status 2>/dev/null || supervisorctl status 2>/dev/null || true) | grep -c RUNNING || true)"
RH=127.0.0.1; RP=6379
if [ -n "\$DIR" ] && [ -d "\$DIR" ]; then
  cd "\$DIR" 2>/dev/null || true
  if [ -f .env ]; then
    RH=\$(sed -n 's/^REDIS_HOST=//p' .env | head -n1 | tr -d "\\"' \\r")
    RP=\$(sed -n 's/^REDIS_PORT=//p' .env | head -n1 | tr -d "\\"' \\r")
  fi
  echo "REDIS_HOST=\${RH:-127.0.0.1}:\${RP:-6379}"
  echo "--- redis probe (via app Redis::connection) ---"
  # Keep stderr (2>&1) so connection/auth/TLS errors are VISIBLE in the console
  # for debugging — the KEY=VALUE parser ignores any non KEY=VALUE noise.
  printf '%s' {$redisB64} | base64 -d | php artisan tinker 2>&1 || echo "REDIS_PING=down"
else
  echo "REDIS_HOST=:"
  echo "REDIS_PING=down (no app dir resolved)"
fi
BASH;

        $bash = 'DIR='.escapeshellarg($siteDir)."\n".$probe;
        $out = $exec->runInlineBash($member, 'worker-pool:stats', $bash, timeoutSeconds: 45, asRoot: false);

        $raw = (string) $out->buffer;
        $parsed = $this->parse($raw);
        $parsed['ok'] = $out->exitCode === 0;
        // Stamp is set by the dispatcher path after return is not possible (jobs
        // can't use now() deterministically in resume), but a plain wall-clock
        // read here is fine — this isn't a resumable workflow.
        $parsed['collected_at'] = now()->toIso8601String();

        return [$parsed, $raw];
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $buffer): array
    {
        $stats = [];
        foreach (preg_split('/\r?\n/', $buffer) ?: [] as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v);
            if ($k !== '' && preg_match('/^[A-Z_]+$/', $k)) {
                $stats[strtolower($k)] = $v;
            }
        }

        return $stats;
    }
}
