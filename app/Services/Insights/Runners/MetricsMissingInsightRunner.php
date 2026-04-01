<?php

namespace App\Services\Insights\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;

class MetricsMissingInsightRunner implements InsightRunnerInterface
{
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }

        $latest = ServerMetricSnapshot::query()
            ->where('server_id', $server->id)
            ->orderByDesc('captured_at')
            ->first();

        $staleAfterMinutes = max(
            5,
            (int) ($parameters['stale_after_minutes'] ?? config('insights.thresholds.metrics_missing_minutes', 15))
        );

        if ($latest === null) {
            return [
                new InsightCandidate(
                    insightKey: 'metrics_missing_or_stale',
                    dedupeHash: 'missing',
                    severity: InsightFinding::SEVERITY_WARNING,
                    title: __('Server metrics are not arriving'),
                    body: __('No metrics snapshots have been stored for this ready server yet. Install monitoring or confirm the metrics pipeline is sending data.'),
                    meta: [
                        'state' => 'missing',
                        'stale_after_minutes' => $staleAfterMinutes,
                    ],
                ),
            ];
        }

        if ($latest->captured_at === null || $latest->captured_at->lt(now()->subMinutes($staleAfterMinutes))) {
            return [
                new InsightCandidate(
                    insightKey: 'metrics_missing_or_stale',
                    dedupeHash: 'stale',
                    severity: InsightFinding::SEVERITY_WARNING,
                    title: __('Server metrics are stale'),
                    body: __('Last metrics snapshot was captured :time. Dply expects a newer sample within about :minutes minutes.', [
                        'time' => $latest->captured_at?->diffForHumans() ?? __('at an unknown time'),
                        'minutes' => $staleAfterMinutes,
                    ]),
                    meta: [
                        'state' => 'stale',
                        'latest_snapshot_at' => $latest->captured_at?->toIso8601String(),
                        'stale_after_minutes' => $staleAfterMinutes,
                    ],
                ),
            ];
        }

        return [];
    }
}
