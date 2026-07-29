<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Models\Organization;
use App\Models\ServerLogUsageDaily;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Rolls a single UTC day of dply Logs ingest volume out of the ClickHouse store
 * into the per-org {@see ServerLogUsageDaily} table — the billable source of truth
 * (PR A of docs/SERVER_LOGS_BILLING.md).
 *
 * Metering runs ClickHouse-side, not Vector-side: it survives aggregator restarts,
 * never double-counts, and ClickHouse aggregates the GROUP BY in milliseconds. We
 * count `length(message)` bytes — exactly what we stored after edge redaction/drops.
 * Laravel stays out of the ingest hot path; this is a read + a small Postgres upsert.
 */
class ServerLogUsageMeter
{
    public function __construct(private ClickHouseClient $clickhouse) {}

    /**
     * Meter one UTC day. Idempotent: re-running overwrites the day's rows in place
     * (keyed on org + day + source), so the hourly current-day pass and the nightly
     * prior-day finalize are both safe.
     *
     * @return array{reachable: bool, day: string, orgs: int, events: int, bytes: int, skipped: int}
     */
    public function meterDay(CarbonInterface $day, bool $dryRun = false): array
    {
        $day = $day->copy()->startOfDay();
        $from = $day->copy();
        $to = $day->copy()->addDay();

        $result = [
            'reachable' => true,
            'day' => $day->toDateString(),
            'orgs' => 0,
            'events' => 0,
            'bytes' => 0,
            'skipped' => 0,
        ];

        // The store may be absent in envs without ClickHouse wired (dev, CI). Skip
        // quietly rather than throwing into the scheduler — there's nothing to meter.
        if (! $this->clickhouse->ping()) {
            $result['reachable'] = false;

            return $result;
        }

        // Meter by ingested_at (when we accepted + stored the row), not the log's
        // own timestamp (which a noisy box can backdate) — ingested_at is the
        // billable moment and matches "bytes accepted at the aggregator".
        $rows = $this->clickhouse->select(
            'SELECT org_id, count() AS events, sum(length(message)) AS bytes '
            ."FROM {$this->clickhouse->qualifiedTable()} "
            .'WHERE ingested_at >= {from:DateTime} AND ingested_at < {to:DateTime} '
            ."AND org_id != '' "
            .'GROUP BY org_id',
            [
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        );

        if ($rows === []) {
            return $result;
        }

        // Only meter org_ids that still exist in Postgres. A row for a deleted org
        // would violate the FK; cascade-delete already drops its history.
        $orgIds = array_values(array_unique(array_map(
            static fn (array $row): string => (string) ($row['org_id'] ?? ''),
            $rows,
        )));
        $knownOrgIds = Organization::query()->whereIn('id', $orgIds)->pluck('id')->all();
        $known = array_flip($knownOrgIds);

        foreach ($rows as $row) {
            $orgId = (string) ($row['org_id'] ?? '');
            $events = (int) ($row['events'] ?? 0);
            $bytes = (int) ($row['bytes'] ?? 0);

            if ($orgId === '' || ! isset($known[$orgId])) {
                $result['skipped']++;

                continue;
            }

            $result['orgs']++;
            $result['events'] += $events;
            $result['bytes'] += $bytes;

            if ($dryRun) {
                continue;
            }

            ServerLogUsageDaily::query()->updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'day' => $day->toDateString(),
                    'source' => ServerLogUsageDaily::SOURCE_CLICKHOUSE,
                ],
                [
                    'events' => $events,
                    'bytes' => $bytes,
                    'meta' => ['metered_via' => 'ServerLogUsageMeter'],
                ],
            );
        }

        if ($result['skipped'] > 0) {
            Log::info('server_logs.usage.metered_skipped_unknown_orgs', [
                'day' => $result['day'],
                'skipped' => $result['skipped'],
            ]);
        }

        return $result;
    }
}
