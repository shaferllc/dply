<?php

namespace App\Jobs;

use App\Models\Server;
use App\Models\WorkerPool;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

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

        foreach ($pool->servers as $member) {
            if (! $member->isReady()) {
                continue;
            }

            try {
                $stats = $this->probe($exec, $member, $this->appSiteDir($member));
            } catch (\Throwable $e) {
                Log::info('worker-pool: stats probe failed', ['server_id' => $member->id, 'error' => $e->getMessage()]);

                continue;
            }

            $meta = is_array($member->meta) ? $member->meta : [];
            $meta['pool'] = array_merge($meta['pool'] ?? [], ['stats' => $stats]);
            $member->forceFill(['meta' => $meta])->save();
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
     * @return array<string, mixed>
     */
    private function probe(ExecuteRemoteTaskOnServer $exec, Server $member, string $siteDir): array
    {
        // KEY=VALUE lines, every value failure-tolerant. Redis/queue are read
        // from the APP's own .env (the backend is usually a REMOTE redis), not
        // localhost. Uses if/then/fi rather than `[ ] && cmd` so a missing file
        // never trips `set -e` and aborts the whole probe.
        $probe = <<<'BASH'
echo "LOAD=$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null || true)"
echo "CPUS=$(nproc 2>/dev/null || true)"
echo "MEM=$(free -m 2>/dev/null | awk '/^Mem:/{print $3"/"$2}' || true)"
echo "DISK=$(df -h / 2>/dev/null | awk 'NR==2{print $3"/"$2" "$5}' || true)"
echo "UPTIME=$(uptime -p 2>/dev/null | sed 's/^up //' || true)"
echo "HORIZON_PROCS=$(pgrep -fc 'artisan horizon' 2>/dev/null || echo 0)"
echo "QUEUE_PROCS=$(pgrep -fc 'artisan queue:work' 2>/dev/null || echo 0)"
echo "SYSTEMD_WORKERS=$(systemctl list-units 'dply-site-*.service' --no-legend --state=running 2>/dev/null | wc -l | tr -d ' ' || echo 0)"
echo "SV_RUNNING=$( (sudo -n supervisorctl status 2>/dev/null || supervisorctl status 2>/dev/null || true) | grep -c RUNNING || true)"
RH=127.0.0.1; RP=6379; RA=""
if [ -n "$DIR" ] && [ -d "$DIR" ]; then
  cd "$DIR" 2>/dev/null || true
  echo "QUEUE_SIZE=$(php artisan queue:size 2>/dev/null | grep -oE '[0-9]+' | tail -n1 || true)"
  if [ -f .env ]; then
    RH=$(sed -n 's/^REDIS_HOST=//p' .env | head -n1 | tr -d "\"' \r")
    RP=$(sed -n 's/^REDIS_PORT=//p' .env | head -n1 | tr -d "\"' \r")
    RA=$(sed -n 's/^REDIS_PASSWORD=//p' .env | head -n1 | tr -d "\"' \r")
  fi
  if [ -z "$RH" ]; then RH=127.0.0.1; fi
  if [ -z "$RP" ]; then RP=6379; fi
fi
RC="redis-cli -h $RH -p $RP"
if [ -n "$RA" ]; then RC="$RC -a $RA"; fi
echo "REDIS_HOST=$RH:$RP"
echo "REDIS_PING=$($RC ping 2>/dev/null || echo down)"
echo "REDIS_MEM=$($RC info memory 2>/dev/null | awk -F: '/used_memory_human/{print $2}' | tr -d '\r' || true)"
echo "REDIS_PEAK=$($RC info memory 2>/dev/null | awk -F: '/used_memory_peak_human/{print $2}' | tr -d '\r' || true)"
echo "REDIS_CLIENTS=$($RC info clients 2>/dev/null | awk -F: '/connected_clients/{print $2}' | tr -d '\r' || true)"
echo "REDIS_OPS=$($RC info stats 2>/dev/null | awk -F: '/instantaneous_ops_per_sec/{print $2}' | tr -d '\r' || true)"
echo "REDIS_TOTAL_CMDS=$($RC info stats 2>/dev/null | awk -F: '/total_commands_processed/{print $2}' | tr -d '\r' || true)"
echo "REDIS_KEYS=$($RC info keyspace 2>/dev/null | awk -F'[:,=]' '/^db/{s+=$3} END{print s+0}' || true)"
echo "REDIS_UPTIME=$($RC info server 2>/dev/null | awk -F: '/uptime_in_seconds/{print $2}' | tr -d '\r' || true)"
BASH;

        $bash = 'DIR='.escapeshellarg($siteDir)."\n".$probe;
        $out = $exec->runInlineBash($member, 'worker-pool:stats', $bash, timeoutSeconds: 45, asRoot: false);

        $parsed = $this->parse((string) $out->buffer);
        $parsed['ok'] = $out->exitCode === 0;
        // Stamp is set by the dispatcher path after return is not possible (jobs
        // can't use now() deterministically in resume), but a plain wall-clock
        // read here is fine — this isn't a resumable workflow.
        $parsed['collected_at'] = now()->toIso8601String();

        return $parsed;
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
