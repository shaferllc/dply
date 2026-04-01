<?php

namespace App\Services\Insights\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;

class HealthCheckUrlMissingInsightRunner implements InsightRunnerInterface
{
    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site !== null) {
            return [];
        }

        if (! $server->sites()->exists()) {
            return [];
        }

        $healthCheckUrl = trim((string) (($server->meta ?? [])['health_check_url'] ?? ''));
        if ($healthCheckUrl !== '') {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'health_check_url_missing',
                dedupeHash: 'missing',
                severity: InsightFinding::SEVERITY_INFO,
                title: __('Add an HTTP health check URL'),
                body: __('This server hosts sites, but overview is still using SSH reachability only. Add a health check URL to monitor the app itself.'),
                meta: [
                    'site_count' => $server->sites()->count(),
                ],
            ),
        ];
    }
}
