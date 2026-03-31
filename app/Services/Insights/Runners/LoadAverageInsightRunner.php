<?php

namespace App\Services\Insights\Runners;

use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;

class LoadAverageInsightRunner implements InsightRunnerInterface
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

        if ($latest === null) {
            return [];
        }

        $load = $latest->payload['load_1m'] ?? null;
        if (! is_numeric($load)) {
            return [];
        }

        $warn = (float) ($parameters['load_warn'] ?? config('insights.thresholds.load_warn', 4.0));
        if ((float) $load < $warn) {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'load_average_high',
                dedupeHash: 'threshold',
                severity: 'warning',
                title: __('Elevated load average'),
                body: __('1-minute load average is :load (threshold :t).', [
                    'load' => round((float) $load, 2),
                    't' => $warn,
                ]),
                meta: [
                    'load_1m' => (float) $load,
                    'snapshot_at' => $latest->captured_at?->toIso8601String(),
                ],
            ),
        ];
    }
}
