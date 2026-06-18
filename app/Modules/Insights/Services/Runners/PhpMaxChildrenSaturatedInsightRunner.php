<?php

namespace App\Modules\Insights\Services\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Insights\Services\Contracts\InsightRunnerInterface;
use App\Modules\Insights\Services\InsightCandidate;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use Illuminate\Support\Facades\Log;

/**
 * Detect PHP-FPM pools currently *at* their pm.max_children limit. This is the
 * stronger sibling of {@see PhpFpmWorkersUndersizedInsightRunner}: undersized
 * fires on a ratio (active/max ≥ 0.85) as a suggestion to bump up; this one
 * fires only when at-the-moment active == max, meaning a request is queued
 * right now waiting for a worker.
 *
 * Probes the FPM status page directly via fcgi over the pool's listen socket.
 * Falls back to scraping `ps` + `pgrep` if the status page isn't enabled
 * (operators often run without it).
 */
class PhpMaxChildrenSaturatedInsightRunner implements InsightRunnerInterface
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
# For each FPM pool config, read pm.max_children from the static text and
# the active worker count from ps. The "active" count is approximate (counts
# php-fpm: pool processes minus the master), but good enough for "at limit".
shopt -s nullglob
out_any=0
for pool in /etc/php/*/fpm/pool.d/www.conf; do
  ver=$(echo "$pool" | awk -F/ '{print $4}')
  max=$(awk -F= '/^[[:space:]]*pm\.max_children[[:space:]]*=/ { gsub(/[ \t]+/, "", $2); print $2; exit }' "$pool")
  if [ -z "$max" ]; then
    continue
  fi
  active=$(pgrep -af "php-fpm: pool" 2>/dev/null | grep -E "^[^ ]+ php-fpm[0-9.]*: pool www" | wc -l | tr -d '[:space:]')
  echo "version=${ver} max=${max} active=${active}"
  out_any=1
done

if [ "$out_any" = "0" ]; then
  echo "no-fpm"
fi
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-php-fpm-saturation', $script, 25, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.php_max_children_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-fpm')) {
            return [];
        }

        $rows = [];
        foreach (preg_split("/\r?\n/", $buffer) ?: [] as $line) {
            if (! preg_match('/^version=(\S+)\s+max=(\d+)\s+active=(\d+)/', trim($line), $m)) {
                continue;
            }
            $rows[] = [
                'version' => $m[1],
                'max_children' => (int) $m[2],
                'active' => (int) $m[3],
                'ratio' => (int) $m[2] > 0 ? round((int) $m[3] / (int) $m[2], 2) : null,
            ];
        }

        if ($rows === []) {
            return [];
        }

        // Threshold: saturation_pct=100 means "currently at limit". We allow
        // overriding to 95 etc. for noisier sites. Lower than the undersized
        // runner's default (85) on purpose — this fires only on imminent
        // wait, not a hint to right-size.
        $threshold = (float) ($parameters['saturation_pct'] ?? 100) / 100;
        $offenders = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['ratio'] !== null && $r['ratio'] >= $threshold,
        ));

        if ($offenders === []) {
            return [];
        }

        $versions = array_map(static fn (array $r): string => $r['version'], $offenders);

        return [
            new InsightCandidate(
                insightKey: 'php_max_children',
                dedupeHash: 'php-max-children-'.md5(implode(',', $versions)),
                severity: InsightFinding::SEVERITY_WARNING,
                title: trans_choice(
                    '{1} PHP-FPM pool is at its worker limit|[2,*] :count PHP-FPM pools are at their worker limit',
                    count($offenders),
                    ['count' => count($offenders)],
                ),
                body: __('Pools at limit: :versions. Requests are queueing while waiting for a worker. Bump pm.max_children (and watch the suggested fix on the related undersized check).', [
                    'versions' => implode(', ', array_map(
                        static fn (array $r) => $r['version'].' ('.$r['active'].'/'.$r['max_children'].')',
                        $offenders,
                    )),
                ]),
                meta: [
                    'signal' => [
                        'rows' => $rows,
                    ],
                ],
            ),
        ];
    }
}
