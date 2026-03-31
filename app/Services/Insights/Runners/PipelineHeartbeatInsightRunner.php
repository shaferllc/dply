<?php

namespace App\Services\Insights\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;

/**
 * Synthetic check: proves the Insights job → runner → finding path ran. No SSH.
 * Updates the same open finding each run (fixed dedupe); use Overview “detected” time to confirm schedule.
 */
class PipelineHeartbeatInsightRunner implements InsightRunnerInterface
{
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }

        if (! $server->isReady()) {
            return [];
        }

        $tz = config('app.timezone') ?: 'UTC';
        $checkedAt = now()->timezone($tz);

        return [
            new InsightCandidate(
                insightKey: 'insights_pipeline_heartbeat',
                dedupeHash: 'singleton',
                severity: InsightFinding::SEVERITY_INFO,
                title: __('Insights pipeline is running'),
                body: __('Synthetic heartbeat — last run: :time (:tz). Disable this in Insights → Settings when you no longer need it.', [
                    'time' => $checkedAt->format('Y-m-d H:i:s'),
                    'tz' => (string) $tz,
                ]),
                meta: [
                    'checked_at' => $checkedAt->toIso8601String(),
                    'app' => config('app.name'),
                ],
            ),
        ];
    }
}
