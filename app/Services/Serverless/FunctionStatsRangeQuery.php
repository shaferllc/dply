<?php

declare(strict_types=1);

namespace App\Services\Serverless;

use App\Models\FunctionInvocation;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Bucketed time-series of a serverless function's activity, for the Monitor
 * dashboard — the function counterpart to {@see \App\Services\Servers\ServerMetricsRangeQuery}.
 *
 * `function_invocations` is dply's source of truth for what hit a function
 * (the DO activations list API is empty). Given a Site + named range, this
 * groups those rows into time buckets and emits, per bucket, the invocation
 * count, error rate, duration spread, and cold-start rate — plus a summary
 * for the whole window. Buckets are computed in PHP; no rollup table.
 */
final class FunctionStatsRangeQuery
{
    /** Range key → bucket size in seconds. */
    public const RANGES = [
        '1h' => 5 * 60,
        '24h' => 60 * 60,
        '7d' => 6 * 3600,
    ];

    /** Range key → window length in seconds. */
    public const WINDOW_SECONDS = [
        '1h' => 3600,
        '24h' => 24 * 3600,
        '7d' => 7 * 86400,
    ];

    public static function defaultRange(): string
    {
        return '24h';
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
     *     summary: array{invocations: int, errors: int, error_rate: int, avg_duration: int, p95_duration: int, cold: int, cold_rate: int, by_source: array<string, int>},
     *     series: array{invocations: list<array{at:int,min:float,avg:float,max:float}>, error_rate: list<array{at:int,min:float,avg:float,max:float}>, duration: list<array{at:int,min:float,avg:float,max:float}>, cold_rate: list<array{at:int,min:float,avg:float,max:float}>}
     * }
     */
    public function forSite(Site $site, string $range): array
    {
        $range = self::isValidRange($range) ? $range : self::defaultRange();
        $bucketSeconds = self::RANGES[$range];

        $to = now();
        $from = $to->copy()->subSeconds(self::WINDOW_SECONDS[$range]);

        $rows = FunctionInvocation::query()
            ->where('site_id', $site->id)
            ->where('created_at', '>=', $from)
            ->orderBy('created_at')
            ->get(['created_at', 'source', 'success', 'duration_ms', 'cold']);

        // Group rows into time buckets.
        $buckets = [];
        foreach ($rows as $row) {
            $ts = (int) $row->created_at->getTimestamp();
            $key = (int) (floor($ts / $bucketSeconds) * $bucketSeconds);
            $buckets[$key] ??= ['n' => 0, 'errors' => 0, 'cold' => 0, 'durations' => []];
            $buckets[$key]['n']++;
            if (! $row->success) {
                $buckets[$key]['errors']++;
            }
            if ($row->cold) {
                $buckets[$key]['cold']++;
            }
            $buckets[$key]['durations'][] = (int) $row->duration_ms;
        }

        // Walk every bucket across the window so the charts are continuous.
        $invocations = $errorRate = $coldRate = $duration = [];
        $startKey = (int) (floor($from->getTimestamp() / $bucketSeconds) * $bucketSeconds);
        $endKey = (int) (floor($to->getTimestamp() / $bucketSeconds) * $bucketSeconds);

        for ($at = $startKey; $at <= $endKey; $at += $bucketSeconds) {
            $bucket = $buckets[$at] ?? null;
            $n = $bucket['n'] ?? 0;

            $invocations[] = $this->flat($at, $n);
            $errorRate[] = $this->flat($at, $n > 0 ? round($bucket['errors'] / $n * 100, 1) : 0);
            $coldRate[] = $this->flat($at, $n > 0 ? round($bucket['cold'] / $n * 100, 1) : 0);

            // Duration uses a real spread — min / avg / p95 — so the chart's
            // band shows latency variance, not a flat line. Empty buckets are
            // gaps, not zeros, so the line doesn't dive to 0 on quiet windows.
            if ($n > 0) {
                $durations = $bucket['durations'];
                $duration[] = [
                    'at' => $at,
                    'min' => (float) min($durations),
                    'avg' => round(array_sum($durations) / $n, 1),
                    'max' => (float) $this->percentile($durations, 95),
                ];
            }
        }

        return [
            'range' => $range,
            'bucket_seconds' => $bucketSeconds,
            'from' => $from,
            'to' => $to,
            'summary' => $this->summary($rows),
            'series' => [
                'invocations' => $invocations,
                'error_rate' => $errorRate,
                'duration' => $duration,
                'cold_rate' => $coldRate,
            ],
        ];
    }

    /**
     * Whole-window totals + a per-source split.
     *
     * @param  \Illuminate\Support\Collection<int, FunctionInvocation>  $rows
     * @return array{invocations: int, errors: int, error_rate: int, avg_duration: int, p95_duration: int, cold: int, cold_rate: int, by_source: array<string, int>}
     */
    private function summary($rows): array
    {
        $total = $rows->count();
        $errors = $rows->filter(fn (FunctionInvocation $r): bool => ! $r->success)->count();
        $cold = $rows->filter(fn (FunctionInvocation $r): bool => $r->cold)->count();
        $durations = $rows->map(fn (FunctionInvocation $r): int => (int) $r->duration_ms)->all();

        return [
            'invocations' => $total,
            'errors' => $errors,
            'error_rate' => $total > 0 ? (int) round($errors / $total * 100) : 0,
            'avg_duration' => $total > 0 ? (int) round(array_sum($durations) / $total) : 0,
            'p95_duration' => $this->percentile($durations, 95),
            'cold' => $cold,
            'cold_rate' => $total > 0 ? (int) round($cold / $total * 100) : 0,
            'by_source' => [
                'tick' => $rows->where('source', FunctionInvocation::SOURCE_TICK)->count(),
                'test' => $rows->where('source', FunctionInvocation::SOURCE_TEST)->count(),
                'web' => $rows->where('source', FunctionInvocation::SOURCE_WEB)->count(),
            ],
        ];
    }

    /** A flat {at,min,avg,max} point — a single value with no band. */
    private function flat(int $at, float|int $value): array
    {
        return ['at' => $at, 'min' => (float) $value, 'avg' => (float) $value, 'max' => (float) $value];
    }

    /**
     * Nearest-rank percentile of an integer list (0 when empty).
     *
     * @param  list<int>  $values
     */
    private function percentile(array $values, int $p): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);
        $index = (int) ceil($p / 100 * count($values)) - 1;

        return (int) $values[max(0, min($index, count($values) - 1))];
    }
}
