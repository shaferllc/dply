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
 * Detect undersized MySQL InnoDB buffer pool. The rule of thumb is
 * `innodb_buffer_pool_size` ≈ 50–80% of RAM on a dedicated DB host, lower on
 * a mixed host. We flag two cases:
 *   - Buffer pool is below `min_ram_share_pct` of total RAM (default 25%).
 *   - Buffer pool is far smaller than the working set (estimated by
 *     pages_data / total_pages > `working_set_full_pct`, default 95%).
 *
 * Uses `mysql --defaults-file=/etc/mysql/debian.cnf` (Ubuntu/Debian
 * convention) so we don't have to manage a credential separately. Falls back
 * to plain `mysql` if that file is absent.
 */
class InnodbBufferPoolInsightRunner implements InsightRunnerInterface
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * @return array<int, App\Modules\Insights\Services\InsightCandidate>
     */
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }
        if (! $server->isReady() || blank($server->ip_address)) {
            return [];
        }

        $script = <<<'BASH'
if ! command -v mysql >/dev/null 2>&1; then
  echo "no-mysql"
  exit 0
fi

# Resolve a credential source — debian.cnf works on Ubuntu/Debian's stock
# install, /root/.my.cnf is the common manual setup. Fall back to anonymous
# auth which works on socket-default installs.
opts=""
if [ -r /etc/mysql/debian.cnf ]; then
  opts="--defaults-file=/etc/mysql/debian.cnf"
elif [ -r /root/.my.cnf ]; then
  opts="--defaults-file=/root/.my.cnf"
fi

# Print key=value pairs the parser will consume.
mysql $opts -NB -e "
SELECT CONCAT('buffer_pool_bytes=', @@innodb_buffer_pool_size);
SELECT CONCAT('pages_total=', (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Innodb_buffer_pool_pages_total'));
SELECT CONCAT('pages_data=',  (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Innodb_buffer_pool_pages_data'));
SELECT CONCAT('pages_dirty=', (SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Innodb_buffer_pool_pages_dirty'));
" 2>&1 || true

if [ -r /proc/meminfo ]; then
  awk '/^MemTotal:/ { print "mem_total_kb=" $2 }' /proc/meminfo
fi
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-innodb-buffer-pool', $script, 25, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.innodb_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-mysql')) {
            return [];
        }

        $values = $this->parseKeyValues($buffer);
        // A host without MySQL (e.g. a worker box) emits none of these keys —
        // the probe's `|| true` swallows the mysql error. Coalesce so a missing
        // key degrades to 0 (then the <= 0 guard below skips) instead of an
        // "Undefined array key" ErrorException under prod's strict handler.
        $poolBytes = (int) ($values['buffer_pool_bytes'] ?? 0);
        $pagesTotal = (int) ($values['pages_total'] ?? 0);
        $pagesData = (int) ($values['pages_data'] ?? 0);
        $memTotalKb = (int) ($values['mem_total_kb'] ?? 0);

        // If we couldn't read anything authentic, skip — better than firing on
        // an auth failure where mysql returned an error onto stdout.
        if ($poolBytes <= 0 || $memTotalKb <= 0) {
            return [];
        }

        $memTotalBytes = $memTotalKb * 1024;
        $ramSharePct = $memTotalBytes > 0 ? ($poolBytes / $memTotalBytes) * 100.0 : 0.0;
        $workingSetPct = $pagesTotal > 0 ? ($pagesData / $pagesTotal) * 100.0 : 0.0;

        $minRamShare = (float) ($parameters['min_ram_share_pct'] ?? 25);
        $workingSetFull = (float) ($parameters['working_set_full_pct'] ?? 95);

        $reasons = [];
        if ($ramSharePct < $minRamShare) {
            $reasons[] = __('innodb_buffer_pool_size is :pct% of RAM (target ≥ :min%).', [
                'pct' => $this->fmt($ramSharePct),
                'min' => $this->fmt($minRamShare),
            ]);
        }
        if ($workingSetPct >= $workingSetFull) {
            $reasons[] = __('Pool is :pct% full (pages_data / pages_total); working set may exceed the pool.', [
                'pct' => $this->fmt($workingSetPct),
            ]);
        }

        if ($reasons === []) {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'innodb_buffer_pool',
                dedupeHash: 'innodb-bp-'.md5(implode('|', $reasons)),
                severity: InsightFinding::SEVERITY_INFO,
                title: __('InnoDB buffer pool may be undersized'),
                body: implode("\n", $reasons)."\n".__('Bump innodb_buffer_pool_size in your MySQL config and restart. Aim for ~60% of RAM on a dedicated DB host, less on a mixed host.'),
                meta: [
                    'signal' => [
                        'buffer_pool_bytes' => $poolBytes,
                        'pages_total' => $pagesTotal,
                        'pages_data' => $pagesData,
                        'mem_total_bytes' => $memTotalBytes,
                        'ram_share_pct' => round($ramSharePct, 2),
                        'working_set_pct' => round($workingSetPct, 2),
                    ],
                ],
                kind: InsightFinding::KIND_SUGGESTION,
            ),
        ];
    }

    private function fmt(float $n): string
    {
        return number_format($n, 1, '.', '');
    }

    /**
     * @return array<int, App\Modules\Insights\Services\InsightCandidate>
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
