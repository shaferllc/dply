<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    /**
     * Per-instance memo of (in-range snapshots, latest snapshot) keyed by
     * `{server_id}|{range}`. The engine-overview panel renders one chart card
     * per active engine — caddy backend + traefik edge can both be "active"
     * for the same server, so without this memo the same range fetch + most-
     * recent select run once per active engine.
     *
     * @var array<string, array{snapshots: Collection, latest: ?ServerMetricSnapshot, from: Carbon, to: Carbon}>
     */
    private array $snapshotCache = [];

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
     *     from: Carbon,
     *     to: Carbon,
     *     sample_count: int,
     *     latest_payload: array<string, mixed>,
     *     latest_at: ?Carbon,
     *     metrics: array<string, list<array{at: int, min: float, avg: float, max: float}>>
     * }
     */
    /** @return array<string, mixed> */
    public function fetch(Server $server, string $range): array
    {
        $range = self::isValidRange($range) ? $range : self::defaultRange();
        $bucketSeconds = self::RANGES[$range];

        ['snapshots' => $snapshots, 'latest' => $latest, 'from' => $from, 'to' => $to]
            = $this->loadSnapshots($server, $range);

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
     * Read in-range + latest snapshots for one (server, range) pair, memoized
     * per instance. Engine-health charts call this once per active engine,
     * but the underlying rows don't change per engine — we want one fetch.
     *
     * @return array{snapshots: Collection, latest: ?ServerMetricSnapshot, from: Carbon, to: Carbon}
     */
    private function loadSnapshots(Server $server, string $range): array
    {
        $cacheKey = $server->id.'|'.$range;
        if (isset($this->snapshotCache[$cacheKey])) {
            return $this->snapshotCache[$cacheKey];
        }

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

        return $this->snapshotCache[$cacheKey] = [
            'snapshots' => $snapshots,
            'latest' => $latest,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Per-engine health time-series for a single webserver / edge proxy.
     * Returns the same shape as fetch() but with metrics scoped to one
     * engine's health block inside payload.webserver_health[].
     *
     * Counters (requests_total, errors_5xx_total) are converted to rates
     * (req/sec, errors/min) by taking the delta between consecutive
     * buckets. Gauges (active_connections) are bucketed raw.
     *
     * @return array{
     *     engine: string,
     *     range: string,
     *     bucket_seconds: int,
     *     from: Carbon,
     *     to: Carbon,
     *     sample_count: int,
     *     latest_block: ?array<string, mixed>,
     *     latest_at: ?Carbon,
     *     metrics: array<string, list<array{at: int, min: float, avg: float, max: float}>>
     * }
     */
    /** @return array<string, mixed> */
    public function fetchEngineHealth(Server $server, string $engine, string $range): array
    {
        $range = self::isValidRange($range) ? $range : self::defaultRange();
        $bucketSeconds = self::RANGES[$range];

        ['snapshots' => $snapshots, 'latest' => $latest, 'from' => $from, 'to' => $to]
            = $this->loadSnapshots($server, $range);

        $activeSeries = $this->bucketEngineGauge($snapshots, $engine, 'active_connections', $bucketSeconds);
        $requestsRateSeries = $this->bucketEngineCounterRate($snapshots, $engine, 'requests_total', $bucketSeconds, perSecond: true);
        $errorsRateSeries = $this->bucketEngineCounterRate($snapshots, $engine, 'errors_5xx_total', $bucketSeconds, perSecond: false);

        return [
            'engine' => $engine,
            'range' => $range,
            'bucket_seconds' => $bucketSeconds,
            'from' => $from,
            'to' => $to,
            'sample_count' => $snapshots->count(),
            'latest_block' => $this->engineBlockFromPayload($latest->payload ?? [], $engine),
            'latest_at' => $latest?->captured_at,
            'metrics' => [
                'active_connections' => $activeSeries,
                'requests_per_sec' => $requestsRateSeries,
                'errors_5xx_per_min' => $errorsRateSeries,
            ],
        ];
    }

    /**
     * Find the health block for the given engine inside a payload's
     * webserver_health array. Returns null when the engine wasn't running
     * at capture time or scrape failed.
     *
     * @param  mixed  $payload
     * @return array<string, mixed>|null
     */
    private function engineBlockFromPayload($payload, string $engine): ?array
    {
        $payload = is_array($payload) ? $payload : [];
        $list = $payload['webserver_health'] ?? [];
        if (! is_array($list)) {
            return null;
        }
        foreach ($list as $block) {
            if (is_array($block) && ($block['engine'] ?? null) === $engine) {
                return $block;
            }
        }

        return null;
    }

    /**
     * Bucket a gauge metric (current-value-only, like active_connections)
     * from a specific engine's health block.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, ServerMetricSnapshot>  $snapshots
     * @return list<array{at: int, min: float, avg: float, max: float}>
     */
    private function bucketEngineGauge($snapshots, string $engine, string $metric, int $bucketSeconds): array
    {
        $buckets = [];
        foreach ($snapshots as $snap) {
            $block = $this->engineBlockFromPayload($snap->payload, $engine);
            if ($block === null || ! array_key_exists($metric, $block)) {
                continue;
            }
            $value = $block[$metric];
            if (! is_numeric($value)) {
                continue;
            }
            $value = (float) $value;
            $ts = $snap->captured_at->getTimestamp();
            $bucketAt = $ts - ($ts % $bucketSeconds);
            $buckets[$bucketAt] ??= ['min' => $value, 'max' => $value, 'sum' => 0.0, 'count' => 0];
            $buckets[$bucketAt]['min'] = min($buckets[$bucketAt]['min'], $value);
            $buckets[$bucketAt]['max'] = max($buckets[$bucketAt]['max'], $value);
            $buckets[$bucketAt]['sum'] += $value;
            $buckets[$bucketAt]['count']++;
        }
        ksort($buckets);

        return array_map(
            static fn (int $at, array $b): array => [
                'at' => $at,
                'min' => round($b['min'], 3),
                'avg' => round($b['sum'] / max(1, $b['count']), 3),
                'max' => round($b['max'], 3),
            ],
            array_keys($buckets),
            $buckets,
        );
    }

    /**
     * Bucket a counter metric (cumulative, like requests_total) into a
     * RATE series. Each bucket's value is (max - min) of the counter
     * within the bucket, divided by either bucket seconds (for req/sec)
     * or by minute equivalents (for errors/min). Gaps in the source
     * snapshots produce gaps in the output.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, ServerMetricSnapshot>  $snapshots
     * @return list<array{at: int, min: float, avg: float, max: float}>
     */
    private function bucketEngineCounterRate($snapshots, string $engine, string $metric, int $bucketSeconds, bool $perSecond): array
    {
        $perBucketSamples = [];
        foreach ($snapshots as $snap) {
            $block = $this->engineBlockFromPayload($snap->payload, $engine);
            if ($block === null || ! array_key_exists($metric, $block)) {
                continue;
            }
            $value = $block[$metric];
            if (! is_numeric($value)) {
                continue;
            }
            $ts = $snap->captured_at->getTimestamp();
            $bucketAt = $ts - ($ts % $bucketSeconds);
            $perBucketSamples[$bucketAt][] = (float) $value;
        }
        ksort($perBucketSamples);

        $divisor = $perSecond ? $bucketSeconds : ($bucketSeconds / 60.0);
        if ($divisor <= 0) {
            $divisor = 1.0;
        }

        $out = [];
        foreach ($perBucketSamples as $at => $samples) {
            $delta = max(0.0, max($samples) - min($samples));
            $rate = $delta / $divisor;
            $out[] = [
                'at' => $at,
                'min' => round($rate, 3),
                'avg' => round($rate, 3),
                'max' => round($rate, 3),
            ];
        }

        return $out;
    }

    /**
     * Group snapshots by floor(timestamp / bucketSeconds) and emit min/avg/max
     * per bucket. Buckets with no samples are skipped — the chart treats gaps
     * as gaps rather than zeros.
     *
     * @param  Collection<int, ServerMetricSnapshot>  $snapshots
     * @return list<array{at: int, min: float, avg: float, max: float}>
     */
    private function bucketSeries($snapshots, string $metric, int $bucketSeconds): array
    {
        $buckets = [];
        foreach ($snapshots as $snap) {
            $payload = ($snap->payload );
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
