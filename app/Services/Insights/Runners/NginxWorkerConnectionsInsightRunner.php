<?php

namespace App\Services\Insights\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Log;

/**
 * Detect nginx connection saturation. nginx's `worker_processes` × `worker_connections`
 * is the hard per-server connection ceiling — each worker can hold open
 * `worker_connections` (mostly half are upstream-facing on a proxy host).
 *
 * Probes:
 *   - `nginx -T` dumps the merged config: parses worker_processes, worker_connections,
 *     worker_rlimit_nofile.
 *   - `ss -tan state established | wc -l` is a rough proxy for current load.
 *
 * Flags when current_connections / (workers × worker_connections) ≥ warn_pct
 * (default 60%) — a worker_connections wall is one of the more surprising
 * outages because nginx silently drops requests once hit.
 */
class NginxWorkerConnectionsInsightRunner implements InsightRunnerInterface
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }
        if (! $server->isReady() || blank($server->ip_address)) {
            return [];
        }

        $script = <<<'BASH'
if ! command -v nginx >/dev/null 2>&1; then
  echo "no-nginx"
  exit 0
fi

# Dump merged config so any `events { worker_connections N; }` block in a
# vhost-specific include shows up. `nginx -T` writes everything to stdout.
conf=$(nginx -T 2>/dev/null || true)
if [ -z "$conf" ]; then
  echo "probe-failed"
  exit 0
fi

wp=$(echo "$conf" | awk '/^[[:space:]]*worker_processes[[:space:]]/ { gsub(";", "", $2); print $2; exit }')
wc=$(echo "$conf" | awk '/^[[:space:]]*worker_connections[[:space:]]/ { gsub(";", "", $2); print $2; exit }')
rl=$(echo "$conf" | awk '/^[[:space:]]*worker_rlimit_nofile[[:space:]]/ { gsub(";", "", $2); print $2; exit }')

# auto resolves at master start, but the worker count appears in `ps`.
if [ -z "$wp" ] || [ "$wp" = "auto" ]; then
  wp=$(pgrep -af "nginx: worker" 2>/dev/null | wc -l | tr -d '[:space:]')
fi
if [ -z "$wp" ] || [ "$wp" = "0" ]; then
  wp=1
fi

# Current TCP connections nginx is plausibly serving — established sockets
# on the host. Not perfect (counts all processes' sockets), but cheap.
conns=$(ss -tan state established 2>/dev/null | tail -n +2 | wc -l | tr -d '[:space:]' || echo 0)

echo "worker_processes=${wp}"
echo "worker_connections=${wc:-0}"
echo "worker_rlimit_nofile=${rl:-0}"
echo "established_connections=${conns}"
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-nginx-workers', $script, 25, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.nginx_workers_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-nginx') || str_contains($buffer, 'probe-failed')) {
            return [];
        }

        $values = $this->parseKeyValues($buffer);
        $wp = (int) ($values['worker_processes'] ?? 0);
        $wc = (int) ($values['worker_connections'] ?? 0);
        $rl = (int) ($values['worker_rlimit_nofile'] ?? 0);
        $conns = (int) ($values['established_connections'] ?? 0);

        if ($wp <= 0 || $wc <= 0) {
            return [];
        }

        $capacity = $wp * $wc;
        $usagePct = ($conns / max($capacity, 1)) * 100.0;
        $warnPct = (float) ($parameters['warn_pct'] ?? 60);
        $criticalPct = max($warnPct, (float) ($parameters['critical_pct'] ?? 85));

        $reasons = [];
        $severity = null;

        if ($usagePct >= $criticalPct) {
            $severity = InsightFinding::SEVERITY_CRITICAL;
            $reasons[] = __('Established connections (:n) are :pct% of nginx capacity (:wp × :wc = :cap).', [
                'n' => $conns, 'pct' => number_format($usagePct, 1),
                'wp' => $wp, 'wc' => $wc, 'cap' => $capacity,
            ]);
        } elseif ($usagePct >= $warnPct) {
            $severity = InsightFinding::SEVERITY_WARNING;
            $reasons[] = __('Established connections (:n) are :pct% of nginx capacity (:wp × :wc = :cap).', [
                'n' => $conns, 'pct' => number_format($usagePct, 1),
                'wp' => $wp, 'wc' => $wc, 'cap' => $capacity,
            ]);
        }

        // worker_rlimit_nofile should be ≥ 2× worker_connections (each conn can
        // need a client socket + an upstream socket). Surface this too when
        // unset / too low.
        if ($rl > 0 && $rl < 2 * $wc) {
            $reasons[] = __('worker_rlimit_nofile (:rl) is below 2× worker_connections (:want) — workers may hit fd exhaustion before the connection cap.', [
                'rl' => $rl, 'want' => 2 * $wc,
            ]);
            $severity = $severity ?? InsightFinding::SEVERITY_INFO;
        }

        if ($severity === null) {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'nginx_worker_connections',
                dedupeHash: 'nginx-workers-'.$severity,
                severity: $severity,
                title: $severity === InsightFinding::SEVERITY_CRITICAL
                    ? __('nginx is near its connection ceiling')
                    : __('nginx worker capacity needs review'),
                body: implode("\n", $reasons)."\n".__('Raise `worker_connections` in /etc/nginx/nginx.conf (each worker holds N file descriptors; ensure `worker_rlimit_nofile` keeps up).'),
                meta: [
                    'signal' => [
                        'worker_processes' => $wp,
                        'worker_connections' => $wc,
                        'worker_rlimit_nofile' => $rl,
                        'established_connections' => $conns,
                        'capacity' => $capacity,
                        'usage_pct' => round($usagePct, 2),
                    ],
                ],
            ),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseKeyValues(string $buffer): array
    {
        $out = [];
        foreach (explode("\n", $buffer) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $out[trim($k)] = trim($v);
        }

        return $out;
    }
}
