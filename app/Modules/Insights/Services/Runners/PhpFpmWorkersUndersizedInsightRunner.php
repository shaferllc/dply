<?php

namespace App\Modules\Insights\Services\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Insights\Services\Contracts\InsightRunnerInterface;
use App\Modules\Insights\Services\InsightCandidate;
use App\Services\Servers\ServerPhpFpmProbe;
use App\Support\Servers\ServerInstalledServices;

/**
 * Suggest bumping `pm.max_children` when PHP-FPM is running close to its configured
 * worker ceiling. One-shot SSH probe per scheduled run — no metric-collection
 * dependency. Single-snapshot signal can be noisy; the suggestion lifecycle
 * (kind=suggestion + ignore + cooldown) handles dismissal cleanly.
 */
class PhpFpmWorkersUndersizedInsightRunner implements InsightRunnerInterface
{
    public function __construct(
        protected ServerPhpFpmProbe $probe,
    ) {}

    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }

        $phpVersion = ServerInstalledServices::phpVersionFor($server);
        if ($phpVersion === null) {
            return [];
        }

        $sample = $this->probe->probe($server, $phpVersion);
        if ($sample === null) {
            return [];
        }

        $threshold = (float) ($parameters['saturation_ratio'] ?? 0.85);
        $threshold = max(0.5, min(0.99, $threshold));

        $ratio = $sample['active_workers'] / max(1, $sample['max_children']);
        if ($ratio < $threshold) {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'php_fpm_workers_undersized',
                dedupeHash: 'pool:www',
                severity: InsightFinding::SEVERITY_INFO,
                title: __('PHP-FPM nearing worker ceiling'),
                body: __('PHP-FPM has :active of :max workers active (:pct%). Consider bumping pm.max_children — Apply fix proposes a value based on server RAM.', [
                    'active' => $sample['active_workers'],
                    'max' => $sample['max_children'],
                    'pct' => (int) round($ratio * 100),
                ]),
                meta: [
                    'signal' => [
                        'php_version' => $sample['php_version'],
                        'max_children' => $sample['max_children'],
                        'active_workers' => $sample['active_workers'],
                        'ratio' => round($ratio, 3),
                        'threshold' => $threshold,
                        'sampled_at' => now()->toIso8601String(),
                    ],
                ],
                kind: InsightFinding::KIND_SUGGESTION,
            ),
        ];
    }
}
