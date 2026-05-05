<?php

namespace App\Services\Insights\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;

/**
 * Suggest enabling Laravel Octane on Laravel sites that aren't already on it,
 * when the host is doing sustained real work (high load average over recent
 * samples). Detection comes from the deploy-time runtime detector
 * ({@see Site::resolvedRuntimeAppDetection()}) — no SSH probe.
 *
 * Suggestion contract: emits with kind=suggestion + severity=info, never pages.
 * The signal is captured in meta.signal so the user sees why we're suggesting.
 */
class OctaneRecommendedInsightRunner implements InsightRunnerInterface
{
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site === null) {
            return [];
        }
        if (! $site->isLaravelFrameworkDetected()) {
            return [];
        }

        $detection = $site->resolvedRuntimeAppDetection();
        if (! is_array($detection)) {
            return [];
        }
        if (! empty($detection['laravel_octane'])) {
            return [];
        }

        $minLoad = (float) ($parameters['load_threshold'] ?? 4.0);
        $minSamples = max(3, (int) ($parameters['min_samples'] ?? 12));
        $sampleLimit = max($minSamples, (int) ($parameters['sample_limit'] ?? 60));

        $snaps = ServerMetricSnapshot::query()
            ->where('server_id', $server->id)
            ->orderByDesc('captured_at')
            ->limit($sampleLimit)
            ->get();

        if ($snaps->count() < $minSamples) {
            return [];
        }

        $loads = $snaps
            ->map(fn (ServerMetricSnapshot $s) => $s->payload['load_1m'] ?? null)
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (float) $v);

        if ($loads->count() < $minSamples) {
            return [];
        }

        $loadAvg = $loads->avg();
        if ($loadAvg < $minLoad) {
            return [];
        }

        $latest = $snaps->first();

        return [
            new InsightCandidate(
                insightKey: 'octane_recommended',
                // Per-site: we only ever want one open suggestion of this kind per site.
                dedupeHash: 'site:'.$site->id,
                severity: InsightFinding::SEVERITY_INFO,
                title: __('Consider enabling Laravel Octane'),
                body: __('Sustained load average of :load over recent samples — Octane keeps the framework booted between requests and can lift throughput on busy Laravel apps.', [
                    'load' => round($loadAvg, 1),
                ]),
                meta: [
                    'signal' => [
                        'load_1m_avg' => round($loadAvg, 2),
                        'load_threshold' => $minLoad,
                        'samples' => $loads->count(),
                        'window_until' => $latest?->captured_at?->toIso8601String(),
                    ],
                ],
                kind: InsightFinding::KIND_SUGGESTION,
            ),
        ];
    }
}
