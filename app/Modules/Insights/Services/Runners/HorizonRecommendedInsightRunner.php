<?php

namespace App\Modules\Insights\Services\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Modules\Insights\Services\Contracts\InsightRunnerInterface;
use App\Modules\Insights\Services\InsightCandidate;

/**
 * Suggest Laravel Horizon on Laravel sites that already run a queue worker via supervisor
 * but aren't using Horizon. Detection is from the deploy-time runtime detector
 * ({@see Site::resolvedRuntimeAppDetection()}); the queue-work signal is a DB join against
 * the site's active SupervisorProgram entries, no SSH probe.
 *
 * Suggestion contract: emits with kind=suggestion + severity=info, never pages.
 */
class HorizonRecommendedInsightRunner implements InsightRunnerInterface
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
        if (! empty($detection['laravel_horizon'])) {
            return [];
        }

        $hasQueueWorker = SupervisorProgram::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('command', 'like', '%queue:work%')
                    ->orWhere('command', 'like', '%queue:listen%');
            })
            ->exists();

        if (! $hasQueueWorker) {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'horizon_recommended',
                dedupeHash: 'site:'.$site->id,
                severity: InsightFinding::SEVERITY_INFO,
                title: __('Consider Laravel Horizon'),
                body: __('This site already runs a queue worker via supervisor. Horizon adds a UI, metrics, and tag-based throttling — useful as queue load grows.'),
                meta: [
                    'signal' => [
                        'has_supervisor_queue_worker' => true,
                    ],
                ],
                kind: InsightFinding::KIND_SUGGESTION,
            ),
        ];
    }
}
