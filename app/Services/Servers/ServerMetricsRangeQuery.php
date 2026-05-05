<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use Illuminate\Support\Carbon;

/**
 * Server-side bucketed query for the per-metric panels on the Metrics page.
 *
 * Given a server + named range (1h / 6h / 24h / 7d / 30d), returns a normalized
 * series per metric containing {at, min, avg, max} for each bucket. Min/max
 * preserve transient spikes that would otherwise vanish when the chart spans
 * many days; avg is what the line itself draws.
 *
 * Buckets are computed in PHP from raw snapshots — no rollup table required.
 * The bucket size grows with the range so the rendered point count stays
 * roughly constant (~50-200 points), regardless of how long the window is.
 */
final class ServerMetricsRangeQuery
{
    /**
     * Range key → bucket-size in seconds.
     *
     * @var array<string, int>
     */
    public const RANGES = [
        '1h' => 60,        // raw 1-minute resolution
        '6h' => 5 * 60,    // 5-min buckets
        '24h' => 15 * 60,  // 15-min buckets
        '7d' => 60 * 60,   // 1-hr buckets
        '30d' => 4 * 3600, // 4-hr buckets
    ];

    /**
     * Range key → window length in seconds.
     *
     * @var array<string, int>
     */
    public const WINDOW_SECONDS = [
        '1h' => 3600,
        '6h' => 6 * 3600,
        '24h' => 24 * 3600,
        '7d' => 7 * 86400,
        '30d' => 30 * 86400,
    ];

    /**
     * Metric keys we render — all live in {@see ServerMetricSnapshot::$payload}.
     * Phase B added io_read_bps / io_write_bps. Per-disk usage and top-process
     * lists are point-in-time only — not bucketed (the latest payload is shown
     * directly in the blade).
     *
     * @var list<string>
     */
    public const METRICS = [
        'cpu_pct',
        'mem_pct',
        'disk_pct',
        'load_1m',
        'rx_bytes_per_sec',
        'tx_bytes_per_sec',
        'io_read_bps',
        'io_write_bps',
    ];

    public static function defaultRange(): string
    {
        return '1h';
    }

    public static function isValidRange(string $range): bool
    {
        return array_key_exists($range, self::RANGES);
    }

    /**
     * @return array{
     *     range: string,
     *     bucket_seconds: int,
     *     from: \Illuminate\Support\Carbon,
     *     to: \Illuminate\Support\Carbon,
     *     sample_count: int,
     *     latest_payload: array<string, mixed>,
     *     latest_at: ?\Illuminate\Support\Carbon,
     *     metrics: array<string, list<array{at: int, min: float, avg: float, max: float}>>
     * }
     */
    public function fetch(Server $server, string $range): array
    {
        $range = self::isValidRange($range) ? $range : self::defaultRange();
        $bucketSeconds = self::RANGES[$range];
        $windowSeconds = self::WINDOW_SECONDS[$range];

        $to = now();
        $from = $to->copy()->subSeconds($windowSeconds);

        $snapshots = ServerMetricSnapshot::query()
            ->where('server_id', $server->id)
            ->where('captured_at', '>=', $from)
            ->orderBy('captured_at')
            ->get(['captured_at', 'payload']);

        $latest = ServerMetricSnapshot::query()
            ->where('server_id', $server->id)
            ->orderByDesc('captured_at')
            ->first();

        $metrics = [];
        foreach (self::METRICS as $metric) {
            $metrics[$metric] = $this->bucketSeries($snapshots, $metric, $bucketSeconds);
        }

        return [
            'range' => $range,
            'bucket_seconds' => $bucketSeconds,
            'from' => $from,
            'to' => $to,
            'sample_count' => $snapshots->count(),
            'latest_payload' => is_array($latest?->payload) ? $latest->payload : [],
            'latest_at' => $latest?->captured_at,
            'metrics' => $metrics,
        ];
    }

    /**
     * Group snapshots by floor(timestamp / bucketSeconds) and emit min/avg/max
     * per bucket. Buckets with no samples are skipped — the chart treats gaps
     * as gaps rather than zeros.
     *
     * @param  \Illuminate\Support\Collection<int, ServerMetricSnapshot>  $snapshots
     * @return list<array{at: int, min: float, avg: float, max: float}>
     */
    private function bucketSeries($snapshots, string $metric, int $bucketSeconds): array
    {
        $buckets = [];
        foreach ($snapshots as $snap) {
            $payload = is_array($snap->payload) ? $snap->payload : [];
            if (! array_key_exists($metric, $payload)) {
                continue;
            }
            $value = $payload[$metric];
            if ($value === null || ! is_numeric($value)) {
                continue;
            }
            $value = (float) $value;
            $bucketKey = (int) (floor((int) $snap->captured_at->getTimestamp() / $bucketSeconds) * $bucketSeconds);

            if (! isset($buckets[$bucketKey])) {
                $buckets[$bucketKey] = ['min' => $value, 'max' => $value, 'sum' => $value, 'n' => 1];

                continue;
            }

            $bucket = &$buckets[$bucketKey];
            if ($value < $bucket['min']) {
                $bucket['min'] = $value;
            }
            if ($value > $bucket['max']) {
                $bucket['max'] = $value;
            }
            $bucket['sum'] += $value;
            $bucket['n']++;
            unset($bucket);
        }

        ksort($buckets);

        $series = [];
        foreach ($buckets as $at => $b) {
            $series[] = [
                'at' => $at,
                'min' => round($b['min'], 4),
                'avg' => round($b['sum'] / max(1, $b['n']), 4),
                'max' => round($b['max'], 4),
            ];
        }

        return $series;
    }
}
