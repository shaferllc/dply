<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgePerformanceHourly;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Upserts hourly Edge performance rollups shared by worker ingest, Logpush, and AE SQL.
 */
final class EdgePerformanceHourlyRollup
{
    public function record(
        Site $site,
        Carbon $occurredAt,
        int $status,
        int $durationMs,
        int $bytes,
        string $cacheStatus,
        string $source = 'worker',
    ): void {
        $hourStart = $occurredAt->copy()->startOfHour();

        $row = EdgePerformanceHourly::query()->firstOrNew([
            'site_id' => $site->id,
            'hour_start' => $hourStart,
            'source' => $source,
        ], [
            'organization_id' => $site->organization_id,
        ]);

        $row->requests = (int) $row->requests + 1;
        $row->bytes_egress = (int) $row->bytes_egress + $bytes;
        $row->duration_ms_total = (int) $row->duration_ms_total + $durationMs;

        if ($status >= 200 && $status < 300) {
            $row->status_2xx = (int) $row->status_2xx + 1;
        } elseif ($status >= 400 && $status < 500) {
            $row->status_4xx = (int) $row->status_4xx + 1;
        } elseif ($status >= 500) {
            $row->status_5xx = (int) $row->status_5xx + 1;
        }

        // A "hit" for ratio purposes covers anything served from the
        // edge cache without round-tripping the origin: fresh hits
        // (`cache-hit`, Cloudflare `HIT`), stale-while-revalidate
        // serves (`cache-stale`), and any future "served from edge"
        // status. Misses, origin pass-throughs, and revalidations do
        // not count.
        $lc = strtolower($cacheStatus);
        if (str_contains($lc, 'hit') || str_contains($lc, 'stale')) {
            $row->cache_hits = (int) $row->cache_hits + 1;
        }

        $row->save();
    }

    /**
     * @param  array{
     *   requests: int,
     *   bytes_egress: int,
     *   duration_ms_total: int,
     *   status_2xx: int,
     *   status_4xx: int,
     *   status_5xx: int,
     *   cache_hits: int,
     * }  $totals
     */
    public function upsertHour(Site $site, Carbon $hourStart, array $totals, string $source = 'analytics_engine'): void
    {
        EdgePerformanceHourly::query()->updateOrCreate(
            [
                'site_id' => $site->id,
                'hour_start' => $hourStart,
                'source' => $source,
            ],
            [
                'organization_id' => $site->organization_id,
                'requests' => max(0, (int) $totals['requests']),
                'bytes_egress' => max(0, (int) $totals['bytes_egress']),
                'duration_ms_total' => max(0, (int) $totals['duration_ms_total']),
                'status_2xx' => max(0, (int) $totals['status_2xx']),
                'status_4xx' => max(0, (int) $totals['status_4xx']),
                'status_5xx' => max(0, (int) $totals['status_5xx']),
                'cache_hits' => max(0, (int) $totals['cache_hits']),
            ],
        );
    }
}
