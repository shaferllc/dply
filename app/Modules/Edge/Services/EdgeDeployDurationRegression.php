<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;

/**
 * Detects when an Edge deploy took markedly longer than the site's recent norm.
 *
 * Compares the just-published deployment's wall-clock duration against the
 * median (p50) of the previous `window` successful deploys for the same site.
 * Anything slower than `multiplier` x p50 is a regression.
 *
 * Median rather than mean: build times are heavy-tailed (a cold Docker cache or
 * a stalled npm registry produces one enormous outlier), and a mean dragged up
 * by that outlier would mask every subsequent regression. The median ignores it.
 *
 * `min_samples` suppresses the signal until the baseline means something — with
 * one or two prior deploys the "p50" is just the previous build, and normal
 * build-to-build jitter would fire an alert on nearly every deploy.
 */
class EdgeDeployDurationRegression
{
    /**
     * @return array{duration_ms: int, p50_ms: int, ratio: float, samples: int}|null
     *                                                                               Null when disabled, when there is not enough history, or when the
     *                                                                               deploy was not slower than the threshold.
     */
    public function evaluate(EdgeDeployment $deployment): ?array
    {
        if (! config('edge.duration_regression.enabled', true)) {
            return null;
        }

        $duration = $this->durationMs($deployment);
        if ($duration === null || $duration <= 0) {
            return null;
        }

        $window = max(1, (int) config('edge.duration_regression.window', 10));
        $minSamples = max(1, (int) config('edge.duration_regression.min_samples', 5));
        $multiplier = (float) config('edge.duration_regression.multiplier', 1.5);

        $baseline = $this->baselineDurations($deployment, $window);
        if (count($baseline) < $minSamples) {
            return null;
        }

        $p50 = $this->median($baseline);
        if ($p50 <= 0) {
            return null;
        }

        $ratio = $duration / $p50;
        if ($ratio <= $multiplier) {
            return null;
        }

        return [
            'duration_ms' => $duration,
            'p50_ms' => (int) round($p50),
            'ratio' => round($ratio, 2),
            'samples' => count($baseline),
        ];
    }

    /**
     * Durations of the site's most recent successful deploys, excluding this one.
     *
     * `superseded` is included: those deployments *were* live, they have simply
     * been replaced since. Restricting to `live` would leave exactly one row —
     * the current deployment's predecessor gets superseded the moment this one
     * publishes — and the baseline could never reach min_samples.
     *
     * @return list<int>
     */
    private function baselineDurations(EdgeDeployment $deployment, int $window): array
    {
        $previous = EdgeDeployment::query()
            ->where('site_id', $deployment->site_id)
            ->whereKeyNot($deployment->getKey())
            ->whereIn('status', [EdgeDeployment::STATUS_LIVE, EdgeDeployment::STATUS_SUPERSEDED])
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->limit($window)
            ->get();

        $durations = [];
        foreach ($previous as $row) {
            $ms = $this->durationMs($row);
            if ($ms !== null && $ms > 0) {
                $durations[] = $ms;
            }
        }

        return $durations;
    }

    private function durationMs(EdgeDeployment $deployment): ?int
    {
        if ($deployment->published_at === null || $deployment->created_at === null) {
            return null;
        }

        // Argument order matters: Carbon 3 returns a SIGNED diff, so
        // $published->diffInMilliseconds($created) is negative and any max(0, …)
        // around it silently clamps every duration to zero. Earlier -> later.
        return (int) max(0, $deployment->created_at->diffInMilliseconds($deployment->published_at));
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
