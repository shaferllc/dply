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

        // Per-site primary URL checks (CheckSiteUrlHealthJob) already
        // probe every site with a domain every 10 min, so an operator
        // who only cares about per-site liveness already has coverage.
        // This insight is about the SERVER-level health probe getting
        // upgraded from TCP-port to HTTP — useful for load-balancer
        // probes or single-app boxes — so we only nudge when at least
        // one site is missing primary-URL coverage.
        $sitesWithoutUrlCheck = $server->sites()
            ->whereDoesntHave('domains')
            ->count();
        if ($sitesWithoutUrlCheck === 0) {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'health_check_url_missing',
                dedupeHash: 'missing',
                severity: InsightFinding::SEVERITY_INFO,
                title: __('Add a server-level health check URL'),
                body: __('Per-site URL checks run every 10 minutes for sites with a primary domain, but the server health probe still uses a TCP port check by default. Set a server-level health URL (e.g. an LB probe target) to upgrade it to an HTTP probe.'),
                meta: [
                    'site_count' => $server->sites()->count(),
                    'sites_without_url_check' => $sitesWithoutUrlCheck,
                ],
            ),
        ];
    }
}
