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
 * Detect PHP OPcache memory pressure. Companion to {@see OpcacheDisabledInsightRunner}.
 * A full opcache silently OOMs and discards entries, which manifests as random
 * CPU spikes during recompilation.
 *
 * Probes opcache_get_status() under the FPM ini stack and flags pools whose
 * memory_consumption is past `usage_warn_pct` (default 90%) OR whose
 * num_cached_keys is past `keys_warn_pct` (default 90%) of max_cached_keys.
 */
class OpcacheFullInsightRunner implements InsightRunnerInterface
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
shopt -s nullglob
emitted=0
for d in /etc/php/*/fpm; do
  ver=$(basename "$(dirname "$d")")
  bin="/usr/bin/php${ver}"
  if [ ! -x "$bin" ]; then
    bin=$(command -v php || true)
  fi
  if [ -z "$bin" ] || [ ! -x "$bin" ]; then
    continue
  fi
  # opcache_get_status() needs to run under a SAPI where opcache is loaded.
  # We invoke the CLI with the FPM php.ini so the same opcache ini directives
  # apply. memory_consumption.used_memory and memory_consumption.free_memory
  # come from the runtime structure.
  out=$("$bin" -c "$d/php.ini" -r '
    if (!function_exists("opcache_get_status")) { echo "no-opcache"; exit; }
    $s = @opcache_get_status(false);
    if ($s === false) { echo "no-opcache"; exit; }
    $m = $s["memory_usage"] ?? [];
    $k = $s["opcache_statistics"] ?? [];
    $used = (int) ($m["used_memory"] ?? 0);
    $free = (int) ($m["free_memory"] ?? 0);
    $wasted = (int) ($m["wasted_memory"] ?? 0);
    $total = $used + $free + $wasted;
    $num = (int) ($k["num_cached_keys"] ?? 0);
    $max = (int) ($k["max_cached_keys"] ?? 0);
    $oom = (int) ($k["oom_restarts"] ?? 0);
    echo "used=$used free=$free wasted=$wasted total=$total num_keys=$num max_keys=$max oom=$oom";
  ' 2>/dev/null)
  if [ -n "$out" ] && [ "$out" != "no-opcache" ]; then
    echo "version=${ver} ${out}"
    emitted=$((emitted+1))
  fi
done
if [ "$emitted" = "0" ]; then
  echo "no-data"
fi
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-opcache-full', $script, 25, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.opcache_full_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-data')) {
            return [];
        }

        $usageWarn = (float) ($parameters['usage_warn_pct'] ?? 90);
        $keysWarn = (float) ($parameters['keys_warn_pct'] ?? 90);

        $rows = [];
        $offenders = [];
        foreach (preg_split("/\r?\n/", $buffer) ?: [] as $line) {
            if (! preg_match('/^version=(\S+)\s+used=(\d+)\s+free=(\d+)\s+wasted=(\d+)\s+total=(\d+)\s+num_keys=(\d+)\s+max_keys=(\d+)\s+oom=(\d+)/', trim($line), $m)) {
                continue;
            }
            $row = [
                'version' => $m[1],
                'used' => (int) $m[2],
                'free' => (int) $m[3],
                'wasted' => (int) $m[4],
                'total' => (int) $m[5],
                'num_keys' => (int) $m[6],
                'max_keys' => (int) $m[7],
                'oom_restarts' => (int) $m[8],
            ];
            $row['used_pct'] = $row['total'] > 0 ? ($row['used'] / $row['total']) * 100.0 : 0.0;
            $row['keys_pct'] = $row['max_keys'] > 0 ? ($row['num_keys'] / $row['max_keys']) * 100.0 : 0.0;
            $rows[] = $row;
            if ($row['used_pct'] >= $usageWarn || $row['keys_pct'] >= $keysWarn || $row['oom_restarts'] > 0) {
                $offenders[] = $row;
            }
        }

        if ($offenders === []) {
            return [];
        }

        $reasons = [];
        foreach ($offenders as $row) {
            $parts = [];
            if ($row['used_pct'] >= $usageWarn) {
                $parts[] = sprintf('memory %.1f%% used', $row['used_pct']);
            }
            if ($row['keys_pct'] >= $keysWarn) {
                $parts[] = sprintf('keys %.1f%% used (%d/%d)', $row['keys_pct'], $row['num_keys'], $row['max_keys']);
            }
            if ($row['oom_restarts'] > 0) {
                $parts[] = sprintf('%d OOM restarts since boot', $row['oom_restarts']);
            }
            $reasons[] = __('PHP :v — :detail', ['v' => $row['version'], 'detail' => implode(', ', $parts)]);
        }

        // Critical when OOM has happened; warning otherwise.
        $hasOom = (bool) array_filter($offenders, static fn (array $r): bool => $r['oom_restarts'] > 0);
        $severity = $hasOom ? InsightFinding::SEVERITY_CRITICAL : InsightFinding::SEVERITY_WARNING;

        return [
            new InsightCandidate(
                insightKey: 'opcache_full',
                dedupeHash: 'opcache-full-'.md5(implode(',', array_map(static fn (array $r): string => $r['version'], $offenders))),
                severity: $severity,
                title: $hasOom
                    ? __('OPcache is restarting from memory pressure')
                    : __('OPcache memory is filling up'),
                body: implode("\n", $reasons)."\n".__('Raise `opcache.memory_consumption` (default 128M is small for modern apps) and/or `opcache.max_accelerated_files`, then reload FPM.'),
                meta: [
                    'signal' => [
                        'pools' => $rows,
                        'offenders' => $offenders,
                    ],
                ],
            ),
        ];
    }
}
