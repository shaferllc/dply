<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\EdgeAccessLog;
use App\Models\EdgePerformanceHourly;
use App\Models\EdgeWebVital;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Access logs + performance rollups for Edge traffic workspace.
 */
final class EdgeSiteAccessAnalytics
{
    /**
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function forSite(Site $site): array
    {
        $since = now()->subDays(7);

        $recentLogs = EdgeAccessLog::query()
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get(['hostname', 'method', 'path', 'status_code', 'duration_ms', 'bytes_egress', 'country', 'cache_status', 'occurred_at']);

        $hourly = EdgePerformanceHourly::query()
            ->where('site_id', $site->id)
            ->where('hour_start', '>=', $since->copy()->startOfHour())
            ->where('source', $this->performanceSource($site, $since))
            ->orderBy('hour_start')
            ->get();

        $requests7d = (int) $hourly->sum('requests');
        $avgDuration = $requests7d > 0
            ? (int) round(((int) $hourly->sum('duration_ms_total')) / max(1, $requests7d))
            : 0;

        $p95 = $this->approximateP95Duration($site, $since);

        $vitals = EdgeWebVital::query()
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $since)
            ->get(['lcp_ms', 'cls', 'inp_ms', 'fcp_ms', 'ttfb_ms']);

        return [
            'has_worker_logs' => $recentLogs->isNotEmpty() || $hourly->isNotEmpty(),
            'has_web_vitals' => $vitals->isNotEmpty(),
            'recent_logs' => $recentLogs->map(fn (EdgeAccessLog $log): array => [
                'hostname' => $log->hostname,
                'method' => $log->method,
                'path' => $log->path,
                'status_code' => $log->status_code,
                'duration_ms' => $log->duration_ms,
                'bytes_egress' => $log->bytes_egress,
                'country' => $log->country,
                'cache_status' => $log->cache_status,
                'occurred_at' => $log->occurred_at?->toIso8601String(),
            ])->all(),
            'performance' => [
                'requests_7d' => $requests7d,
                'avg_duration_ms' => $avgDuration,
                'p95_duration_ms' => $p95,
                'cache_hit_ratio' => $this->cacheHitRatio($hourly),
            ],
            'web_vitals' => [
                'samples_7d' => $vitals->count(),
                'lcp_p75_ms' => $this->percentile($vitals->pluck('lcp_ms')->filter()->values(), 75),
                'cls_p75' => $this->percentile($vitals->pluck('cls')->filter()->values(), 75),
                'inp_p75_ms' => $this->percentile($vitals->pluck('inp_ms')->filter()->values(), 75),
                'fcp_p75_ms' => $this->percentile($vitals->pluck('fcp_ms')->filter()->values(), 75),
                'ttfb_p75_ms' => $this->percentile($vitals->pluck('ttfb_ms')->filter()->values(), 75),
            ],
        ];
    }

    /**
     * @param  Collection<int, int|float>  $values
     */
    private function percentile($values, int $percentile): int|float|null
    {
        if ($values->isEmpty()) {
            return null;
        }

        $sorted = $values->sort()->values();
        $index = (int) max(0, min($sorted->count() - 1, (int) ceil($sorted->count() * ($percentile / 100)) - 1));
        $value = $sorted[$index];

        return is_float($value) ? round($value, 4) : (int) $value;
    }

    /**
     * @param  Collection<int, EdgePerformanceHourly>  $hourly
     */
    private function cacheHitRatio($hourly): ?float
    {
        $requests = (int) $hourly->sum('requests');
        $hits = (int) $hourly->sum('cache_hits');
        if ($requests === 0) {
            return null;
        }

        return round($hits / $requests, 3);
    }

    private function approximateP95Duration(Site $site, Carbon $since): ?int
    {
        $durations = EdgeAccessLog::query()
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('duration_ms')
            ->limit(200)
            ->pluck('duration_ms')
            ->map(fn ($value): int => (int) $value)
            ->sort()
            ->values();

        if ($durations->isEmpty()) {
            return null;
        }

        $index = (int) max(0, min($durations->count() - 1, (int) ceil($durations->count() * 0.95) - 1));

        return $durations[$index];
    }

    private function performanceSource(Site $site, Carbon $since): string
    {
        if (! filter_var((string) config('edge.analytics.prefer_analytics_engine', true), FILTER_VALIDATE_BOOLEAN)) {
            return 'worker';
        }

        $hasAnalyticsEngine = EdgePerformanceHourly::query()
            ->where('site_id', $site->id)
            ->where('hour_start', '>=', $since->copy()->startOfHour())
            ->where('source', 'analytics_engine')
            ->exists();

        return $hasAnalyticsEngine ? 'analytics_engine' : 'worker';
    }
}
