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
 * Detect MySQL connection saturation. Compares Max_used_connections (the
 * lifetime high-water mark) against max_connections. Severity escalates as
 * the ratio creeps toward 1:
 *   - ≥ critical_pct (default 95): connections will start refusing
 *   - ≥ warn_pct (default 80): close to refusing, plan a bump
 *
 * Also surfaces aborted_connects > 0 which usually means clients hit the
 * cap and got "Too many connections" — provides a hint in the body.
 *
 * Postgres support is intentionally omitted here; the existing
 * `requires => ['mysql', 'postgres']` config tag covers either engine being
 * present, but we read MySQL-specific status variables. A Postgres-flavored
 * variant can land later under the same insight key.
 */
class DatabaseConnectionsInsightRunner implements InsightRunnerInterface
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * @return array<int, App\Services\Insights\InsightCandidate>
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

opts=""
if [ -r /etc/mysql/debian.cnf ]; then
  opts="--defaults-file=/etc/mysql/debian.cnf"
elif [ -r /root/.my.cnf ]; then
  opts="--defaults-file=/root/.my.cnf"
fi

mysql $opts -NB -e "
SELECT CONCAT('max_connections=', @@max_connections);
SELECT CONCAT('threads_connected=', VARIABLE_VALUE) FROM performance_schema.global_status WHERE VARIABLE_NAME='Threads_connected';
SELECT CONCAT('max_used_connections=', VARIABLE_VALUE) FROM performance_schema.global_status WHERE VARIABLE_NAME='Max_used_connections';
SELECT CONCAT('aborted_connects=', VARIABLE_VALUE) FROM performance_schema.global_status WHERE VARIABLE_NAME='Aborted_connects';
SELECT CONCAT('connection_errors_max_connections=', VARIABLE_VALUE) FROM performance_schema.global_status WHERE VARIABLE_NAME='Connection_errors_max_connections';
" 2>&1 || true
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-db-connections', $script, 20, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.db_connections_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-mysql')) {
            return [];
        }

        $values = $this->parseKeyValues($buffer);
        $maxConn = (int) ($values['max_connections'] ?? 0);
        $maxUsed = (int) ($values['max_used_connections'] ?? 0);
        $threadsConn = (int) ($values['threads_connected'] ?? 0);
        $aborted = (int) ($values['aborted_connects'] ?? 0);
        $errorsMax = (int) ($values['connection_errors_max_connections'] ?? 0);

        if ($maxConn <= 0) {
            return [];
        }

        $usedRatioPct = ($maxUsed / $maxConn) * 100.0;
        $warnPct = (float) ($parameters['warn_pct'] ?? 80);
        $criticalPct = max($warnPct, (float) ($parameters['critical_pct'] ?? 95));

        $severity = null;
        if ($errorsMax > 0 || $usedRatioPct >= $criticalPct) {
            $severity = InsightFinding::SEVERITY_CRITICAL;
        } elseif ($usedRatioPct >= $warnPct) {
            $severity = InsightFinding::SEVERITY_WARNING;
        }

        if ($severity === null) {
            return [];
        }

        $body = [];
        $body[] = __('Max-ever connections :used / :max (:pct%). Threads currently connected: :now.', [
            'used' => $maxUsed,
            'max' => $maxConn,
            'pct' => number_format($usedRatioPct, 1),
            'now' => $threadsConn,
        ]);
        if ($errorsMax > 0) {
            $body[] = __('MySQL has refused :n connections at the max_connections limit so far. Bump max_connections or audit client pooling.', ['n' => $errorsMax]);
        } elseif ($aborted > 0) {
            $body[] = __(':n aborted connects so far — often client timeouts or auth failures rather than saturation, but worth noting.', ['n' => $aborted]);
        }

        return [
            new InsightCandidate(
                insightKey: 'database_connections',
                dedupeHash: 'db-conn-'.($severity === InsightFinding::SEVERITY_CRITICAL ? 'crit' : 'warn'),
                severity: $severity,
                title: $severity === InsightFinding::SEVERITY_CRITICAL
                    ? __('MySQL is at or near its connection cap')
                    : __('MySQL connection usage is high'),
                body: implode("\n", $body),
                meta: [
                    'signal' => [
                        'max_connections' => $maxConn,
                        'max_used_connections' => $maxUsed,
                        'threads_connected' => $threadsConn,
                        'aborted_connects' => $aborted,
                        'connection_errors_max_connections' => $errorsMax,
                        'used_ratio_pct' => round($usedRatioPct, 2),
                    ],
                ],
            ),
        ];
    }

    /**
     * @return array<int, App\Services\Insights\InsightCandidate>
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
