<?php

declare(strict_types=1);

namespace App\Modules\Database\Services;

use App\Models\CloudDatabase;
use App\Modules\Database\Backends\DatabaseBackend;
use App\Modules\Database\Backends\DatabaseRouter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cluster time series for the database detail page.
 *
 * Two shape problems sit between a provider's metrics API and the chart:
 * providers return one raw sample per scrape interval (a 7-day window is
 * thousands of points, far more than a 1000-unit-wide SVG can show), and the
 * `x-metrics-line-chart` component wants {at, min, avg, max} buckets so it can
 * draw a band rather than a jagged line.
 * Bucketing here gives both, and gives it identically for every backend.
 *
 * Provider failures are swallowed per-metric on purpose: the detail page draws
 * several charts and shows attached sites, users and connection details around
 * them. One 500 from a monitoring endpoint must cost one chart, not the page.
 */
class ManagedDatabaseMetrics
{
    /** Window slug → [seconds of history, number of buckets]. */
    private const WINDOWS = [
        '1h' => [3600, 60],
        '24h' => [86400, 96],
        '7d' => [604800, 168],
    ];

    public function __construct(
        private readonly DatabaseRouter $router,
    ) {}

    /** @return list<string> */
    public function windows(): array
    {
        return array_keys(self::WINDOWS);
    }

    public function supports(CloudDatabase $database): bool
    {
        if (! $database->isActive() || $database->isExternal() || blank($database->backend_id)) {
            return false;
        }

        try {
            return $this->router->backendFor($database)->supports(DatabaseBackend::CAP_METRICS);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Every chartable metric for this database over $window, in display order.
     *
     * @return list<array{key: string, label: string, format: string, series: list<array{at: int, min: float, avg: float, max: float}>, latest: ?float}>
     */
    public function forWindow(CloudDatabase $database, string $window): array
    {
        if (! $this->supports($database)) {
            return [];
        }

        [$seconds, $buckets] = self::WINDOWS[$window] ?? self::WINDOWS['24h'];
        $end = now()->getTimestamp();
        $start = $end - $seconds;

        $backend = $this->router->backendFor($database);

        $charts = [];
        foreach ($backend->metricCatalog($database) as $metric) {
            try {
                $points = $backend->metric($database, $metric['key'], $start, $end);
            } catch (Throwable $e) {
                Log::warning('database.metrics.fetch_failed', [
                    'cloud_database_id' => $database->id,
                    'metric' => $metric['key'],
                    'error' => $e->getMessage(),
                ]);
                $points = [];
            }

            $series = $this->bucket($points, $start, $end, $buckets);

            $charts[] = [
                'key' => $metric['key'],
                'label' => $metric['label'],
                'format' => $metric['format'],
                'series' => $series,
                'latest' => $series === [] ? null : $series[count($series) - 1]['avg'],
            ];
        }

        return $charts;
    }

    /**
     * Fold raw {t, v} samples into fixed-width buckets.
     *
     * Empty buckets are dropped rather than zero-filled: a gap in a provider's
     * scrape is not a moment of zero CPU, and plotting it as one invents a
     * cliff that never happened.
     *
     * @param  list<array{t: int, v: float}>  $points
     * @return list<array{at: int, min: float, avg: float, max: float}>
     */
    private function bucket(array $points, int $start, int $end, int $buckets): array
    {
        if ($points === [] || $buckets < 1 || $end <= $start) {
            return [];
        }

        $width = max(1, (int) ceil(($end - $start) / $buckets));

        /** @var array<int, list<float>> $bins */
        $bins = [];
        foreach ($points as $point) {
            $index = intdiv(max(0, $point['t'] - $start), $width);
            $bins[$index][] = $point['v'];
        }

        ksort($bins);

        $series = [];
        foreach ($bins as $index => $values) {
            $series[] = [
                'at' => $start + ($index * $width),
                'min' => (float) min($values),
                'avg' => round(array_sum($values) / count($values), 2),
                'max' => (float) max($values),
            ];
        }

        return $series;
    }
}
