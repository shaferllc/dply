<?php

declare(strict_types=1);

namespace App\Services\Logs;

use App\Models\Server;

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

        $sql = 'SELECT timestamp, level, source, unit, host, message FROM '
            .$this->clickhouse->qualifiedTable()
            .' WHERE '.implode(' AND ', $where)
            .' ORDER BY timestamp DESC LIMIT {lim:UInt32}';

        return $this->clickhouse->select($sql, $params);
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
