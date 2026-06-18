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
 * Detect runaway MySQL binary logs. Two failure modes:
 *   - log_bin is on with no expire policy: logs accrete until disk fills.
 *   - binlog dir's size exceeds `bin_log_warn_pct` of the partition's used
 *     space (default 30%): even with an expire policy, the retention window
 *     may need shortening.
 *
 * Probes a single mysql command for the three relevant variables, then
 * measures the binlog directory with `du`. Falls back gracefully when mysql
 * is unauthenticated against debian.cnf.
 */
class MysqlBinLogsInsightRunner implements InsightRunnerInterface
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

opts=""
if [ -r /etc/mysql/debian.cnf ]; then
  opts="--defaults-file=/etc/mysql/debian.cnf"
elif [ -r /root/.my.cnf ]; then
  opts="--defaults-file=/root/.my.cnf"
fi

mysql $opts -NB -e "
SELECT CONCAT('log_bin=', @@log_bin);
SELECT CONCAT('binlog_basename=', @@log_bin_basename);
SELECT CONCAT('expire_days=', @@expire_logs_days);
SELECT CONCAT('binlog_expire_seconds=', @@binlog_expire_logs_seconds);
" 2>&1 || true

# If we got a basename, measure the directory holding it.
if [ -r /etc/mysql/debian.cnf ] || [ -r /root/.my.cnf ]; then
  basename=$(mysql $opts -NB -e "SELECT @@log_bin_basename;" 2>/dev/null)
  if [ -n "$basename" ] && [ "$basename" != "NULL" ]; then
    dir=$(dirname "$basename")
    if [ -d "$dir" ]; then
      # binlog filename prefix is the basename; sum up files starting with it.
      bytes=$(du -b -c "$basename"* 2>/dev/null | tail -n 1 | awk '{print $1}')
      echo "binlog_dir=${dir}"
      echo "binlog_bytes=${bytes:-0}"
      # Partition free / total for the binlog dir.
      df_line=$(df -B1 --output=size,used,avail "$dir" 2>/dev/null | tail -n 1)
      echo "df_size=$(echo $df_line | awk '{print $1}')"
      echo "df_used=$(echo $df_line | awk '{print $2}')"
      echo "df_avail=$(echo $df_line | awk '{print $3}')"
    fi
  fi
fi
BASH;

        try {
            $out = $this->remote->runInlineBash($server, 'insight-mysql-binlogs', $script, 25, false);
            $buffer = (string) $out->getBuffer();
        } catch (\Throwable $e) {
            Log::debug('insights.mysql_binlogs_probe_failed', ['server_id' => $server->id, 'error' => $e->getMessage()]);

            return [];
        }

        if (str_contains($buffer, 'no-mysql')) {
            return [];
        }

        $values = $this->parseKeyValues($buffer);
        $logBin = strtolower($values['log_bin'] ?? '');
        if (! in_array($logBin, ['on', '1'], true)) {
            return [];
        }

        $expireDays = (int) ($values['expire_days'] ?? 0);
        $expireSeconds = (int) ($values['binlog_expire_seconds'] ?? 0);
        $binlogBytes = (int) ($values['binlog_bytes'] ?? 0);
        $dfUsed = (int) ($values['df_used'] ?? 0);
        $dfSize = (int) ($values['df_size'] ?? 0);

        $usedPct = $dfUsed > 0 && $binlogBytes > 0 ? ($binlogBytes / $dfUsed) * 100.0 : 0.0;
        $sizePct = $dfSize > 0 && $binlogBytes > 0 ? ($binlogBytes / $dfSize) * 100.0 : 0.0;

        $warnPct = (float) ($parameters['bin_log_warn_pct'] ?? 30);

        $reasons = [];
        $severity = InsightFinding::SEVERITY_INFO;

        if ($expireDays === 0 && $expireSeconds === 0) {
            $reasons[] = __('binlog retention is unlimited (expire_logs_days=0, binlog_expire_logs_seconds=0). Logs will grow until disk fills.');
            $severity = InsightFinding::SEVERITY_WARNING;
        }

        if ($usedPct >= $warnPct) {
            $reasons[] = __('Binary logs occupy :u% of used space on the binlog partition (:b GB).', [
                'u' => number_format($usedPct, 1),
                'b' => number_format($binlogBytes / 1024 / 1024 / 1024, 2),
            ]);
            $severity = $severity === InsightFinding::SEVERITY_INFO
                ? InsightFinding::SEVERITY_WARNING
                : $severity;
        }

        if ($reasons === []) {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'mysql_bin_logs',
                dedupeHash: 'mysql-binlogs',
                severity: $severity,
                title: __('MySQL binary logs need attention'),
                body: implode("\n", $reasons)."\n".__('Set `binlog_expire_logs_seconds = 604800` (7d) or similar in your MySQL config, then `FLUSH BINARY LOGS`.'),
                meta: [
                    'signal' => [
                        'binlog_dir' => $values['binlog_dir'] ?? null,
                        'binlog_bytes' => $binlogBytes,
                        'expire_days' => $expireDays,
                        'binlog_expire_seconds' => $expireSeconds,
                        'used_pct' => round($usedPct, 2),
                        'size_pct' => round($sizePct, 2),
                    ],
                ],
            ),
        ];
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
