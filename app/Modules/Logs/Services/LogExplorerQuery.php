<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Models\Server;
use Carbon\CarbonInterface;

/**
 * Read-side query for the dply Logs explorer: org + server-scoped reads of
 * dply_logs.server_logs out of ClickHouse for the Logs workspace. ALWAYS filters
 * by org_id (tenant isolation) and the current server; all user input is passed
 * as bound ClickHouse params, never interpolated. See docs/SERVER_LOGS_ADDON.md.
 */
class LogExplorerQuery
{
    public function __construct(private readonly ClickHouseClient $clickhouse) {}

    /**
     * Log lines for a server within an explicit [$from, $to] window, ordered
     * CHRONOLOGICALLY (oldest first) — the natural way to read context around a
     * known event (a deploy, an error, a downtime window). This is the shared
     * primitive behind the Tier-1 correlation views: cross-link logs from the
     * deploy timeline / errors stream / uptime incidents into the exact slice
     * that surrounded the event. Same org+server scoping + bound params as recent().
     *
     * @param  array{search?:string,level?:string,source?:string,limit?:int}  $filters
     * @return list<array<string,mixed>>
     */
    public function window(Server $server, CarbonInterface $from, CarbonInterface $to, array $filters = []): array
    {
        $params = [
            'org' => (string) $server->organization_id,
            'server' => (string) $server->id,
            'from' => $from->copy()->utc()->format('Y-m-d H:i:s'),
            'to' => $to->copy()->utc()->format('Y-m-d H:i:s'),
            'lim' => max(1, min(2000, (int) ($filters['limit'] ?? 500))),
        ];

        $where = [
            'org_id = {org:String}',
            'server_id = {server:String}',
            'timestamp >= {from:DateTime}',
            'timestamp <= {to:DateTime}',
        ];

        $this->applyFacetFilters($filters, $where, $params);

        $sql = 'SELECT timestamp, level, source, unit, host, message FROM '
            .$this->clickhouse->qualifiedTable()
            .' WHERE '.implode(' AND ', $where)
            .' ORDER BY timestamp ASC LIMIT {lim:UInt32}';

        return $this->clickhouse->select($sql, $params);
    }

    /**
     * Convenience wrapper: the window of logs surrounding a single instant
     * ($before/$after seconds on each side). Powers "logs around this error /
     * this deploy" jump-offs.
     *
     * @param  array{search?:string,level?:string,source?:string,limit?:int}  $filters
     * @return list<array<string,mixed>>
     */
    public function around(
        Server $server,
        CarbonInterface $instant,
        int $beforeSeconds = 120,
        int $afterSeconds = 120,
        array $filters = [],
    ): array {
        return $this->window(
            $server,
            $instant->copy()->subSeconds(max(0, $beforeSeconds)),
            $instant->copy()->addSeconds(max(0, $afterSeconds)),
            $filters,
        );
    }

    /**
     * Recent log lines for a server, newest first.
     *
     * @param  array{search?:string,level?:string,source?:string,range_minutes?:int,limit?:int}  $filters
     * @return list<array<string,mixed>>
     */
    public function recent(Server $server, array $filters = []): array
    {
        $params = [
            'org' => (string) $server->organization_id,
            'server' => (string) $server->id,
            'mins' => max(1, (int) ($filters['range_minutes'] ?? 60)),
            'lim' => max(1, min(1000, (int) ($filters['limit'] ?? 100))),
        ];

        $where = [
            'org_id = {org:String}',
            'server_id = {server:String}',
            'timestamp >= now() - toIntervalMinute({mins:UInt32})',
        ];

        $this->applyFacetFilters($filters, $where, $params);

        $sql = 'SELECT timestamp, level, source, unit, host, message FROM '
            .$this->clickhouse->qualifiedTable()
            .' WHERE '.implode(' AND ', $where)
            .' ORDER BY timestamp DESC LIMIT {lim:UInt32}';

        return $this->clickhouse->select($sql, $params);
    }

    /**
     * Number of log lines for a server within [$from, $to] matching the optional
     * level/source/search facets — the aggregation primitive behind dply Logs
     * alerting ("> N error lines in 5 min"). Same org+server scoping + bound
     * params as window(); returns a single count, never rows.
     *
     * @param  array{search?:string,level?:string,source?:string}  $filters
     */
    public function countInWindow(Server $server, CarbonInterface $from, CarbonInterface $to, array $filters = []): int
    {
        $params = [
            'org' => (string) $server->organization_id,
            'server' => (string) $server->id,
            'from' => $from->copy()->utc()->format('Y-m-d H:i:s'),
            'to' => $to->copy()->utc()->format('Y-m-d H:i:s'),
        ];

        $where = [
            'org_id = {org:String}',
            'server_id = {server:String}',
            'timestamp >= {from:DateTime}',
            'timestamp <= {to:DateTime}',
        ];

        $this->applyFacetFilters($filters, $where, $params);

        $sql = 'SELECT count() AS c FROM '
            .$this->clickhouse->qualifiedTable()
            .' WHERE '.implode(' AND ', $where);

        return (int) ($this->clickhouse->scalar($sql, $params) ?? 0);
    }

    /**
     * Time-bucketed log counts for a server across [$from, $to], one row per
     * non-empty bucket, split into error / warn severity classes (the rest is
     * derived as "other"). Powers the correlation histogram — log volume over
     * time, against which deploys/errors/incidents are overlaid. `$bucketSeconds`
     * sets the granularity (60 = minute, 3600 = hour, 86400 = day). Same
     * org+server scoping + bound params as window(); optional facet filters apply.
     *
     * Levels arrive as either words (error/warn/notice) or numeric syslog
     * severities (journald: 0–7) — both are classed: error = severity 0–3 or a
     * name containing err/crit/fatal/alert/emerg; warn = severity 4–5 or warn/notice.
     *
     * @param  array{search?:string,level?:string,source?:string}  $filters
     * @return list<array{bucket:string,total:int,errors:int,warns:int}>
     */
    public function histogram(Server $server, CarbonInterface $from, CarbonInterface $to, int $bucketSeconds, array $filters = []): array
    {
        $params = [
            'org' => (string) $server->organization_id,
            'server' => (string) $server->id,
            'from' => $from->copy()->utc()->format('Y-m-d H:i:s'),
            'to' => $to->copy()->utc()->format('Y-m-d H:i:s'),
            'bucket' => max(1, $bucketSeconds),
        ];

        $where = [
            'org_id = {org:String}',
            'server_id = {server:String}',
            'timestamp >= {from:DateTime}',
            'timestamp <= {to:DateTime}',
        ];

        $this->applyFacetFilters($filters, $where, $params);

        $errorClass = "(level IN ('0','1','2','3')"
            ." OR positionCaseInsensitive(level,'err')>0 OR positionCaseInsensitive(level,'crit')>0"
            ." OR positionCaseInsensitive(level,'fatal')>0 OR positionCaseInsensitive(level,'alert')>0"
            ." OR positionCaseInsensitive(level,'emerg')>0)";
        $warnClass = "(level IN ('4','5') OR positionCaseInsensitive(level,'warn')>0 OR positionCaseInsensitive(level,'notice')>0)";

        $sql = 'SELECT toStartOfInterval(timestamp, INTERVAL {bucket:UInt32} SECOND) AS bucket, '
            .'count() AS total, '
            ."countIf({$errorClass}) AS errors, "
            ."countIf({$warnClass}) AS warns "
            .'FROM '.$this->clickhouse->qualifiedTable()
            .' WHERE '.implode(' AND ', $where)
            .' GROUP BY bucket ORDER BY bucket ASC';

        $rows = $this->clickhouse->select($sql, $params);

        return array_values(array_map(static fn (array $r): array => [
            'bucket' => (string) ($r['bucket'] ?? ''),
            'total' => (int) ($r['total'] ?? 0),
            'errors' => (int) ($r['errors'] ?? 0),
            'warns' => (int) ($r['warns'] ?? 0),
        ], $rows));
    }

    /**
     * Apply the optional level/source/search facets shared by recent() and
     * window(), as bound params (never interpolated).
     *
     * @param  array<string,mixed>  $filters
     * @param  list<string>  $where
     * @param  array<string,mixed>  $params
     */
    private function applyFacetFilters(array $filters, array &$where, array &$params): void
    {
        $level = trim((string) ($filters['level'] ?? ''));
        if ($level !== '') {
            $where[] = 'level = {level:String}';
            $params['level'] = $level;
        }

        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '') {
            $where[] = 'source = {source:String}';
            $params['source'] = $source;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = 'positionCaseInsensitive(message, {search:String}) > 0';
            $params['search'] = $search;
        }
    }

    /**
     * Distinct levels present for this server in the window (for the filter UI).
     *
     * @return list<string>
     */
    public function levels(Server $server, int $rangeMinutes = 1440): array
    {
        $rows = $this->clickhouse->select(
            'SELECT DISTINCT level FROM '.$this->clickhouse->qualifiedTable()
            .' WHERE org_id = {org:String} AND server_id = {server:String}'
            .' AND timestamp >= now() - toIntervalMinute({mins:UInt32}) AND level != \'\' ORDER BY level',
            [
                'org' => (string) $server->organization_id,
                'server' => (string) $server->id,
                'mins' => max(1, $rangeMinutes),
            ],
        );

        return array_values(array_filter(array_map(
            static fn (array $r): string => (string) ($r['level'] ?? ''),
            $rows,
        )));
    }
}
