<?php

namespace App\Services\Projects;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\Workspace;

class WorkspaceHealthSummaryService
{
    /**
     * @return array{
     *     servers_total: int,
     *     servers_ready: int,
     *     servers_unreachable: int,
     *     sites_total: int,
     *     sites_active_ssl: int,
     *     sites_failed_ssl: int,
     *     sites_error: int,
     *     pending_deploys: int,
     *     healthy: bool,
     *     status_label: string,
     *     issues: list<string>
     * }
     */
    public function summarize(Workspace $workspace): array
    {
        $workspace->loadMissing(['servers', 'sites.deployments']);

        $servers = $workspace->servers;
        $sites = $workspace->sites;

        $serversReady = $servers->where('status', Server::STATUS_READY)->count();
        $serversUnreachable = $servers->where('health_status', Server::HEALTH_UNREACHABLE)->count();
        $sitesActiveSsl = $sites->where('ssl_status', Site::SSL_ACTIVE)->count();
        $sitesFailedSsl = $sites->where('ssl_status', Site::SSL_FAILED)->count();
        $sitesError = $sites->where('status', Site::STATUS_ERROR)->count();
        $pendingDeploys = $sites->filter(function (Site $site): bool {
            $latest = $site->deployments->first();

            return $latest?->status === SiteDeployment::STATUS_RUNNING;
        })->count();

        $issues = [];

        if ($serversUnreachable > 0) {
            $issues[] = trans_choice(':count server is unreachable|:count servers are unreachable', $serversUnreachable, ['count' => $serversUnreachable]);
        }

        if ($sitesFailedSsl > 0) {
            $issues[] = trans_choice(':count site has failed SSL|:count sites have failed SSL', $sitesFailedSsl, ['count' => $sitesFailedSsl]);
        }

        if ($sitesError > 0) {
            $issues[] = trans_choice(':count site is in error|:count sites are in error', $sitesError, ['count' => $sitesError]);
        }

        if ($pendingDeploys > 0) {
            $issues[] = trans_choice(':count deploy is still running|:count deploys are still running', $pendingDeploys, ['count' => $pendingDeploys]);
        }

        return [
            'servers_total' => $servers->count(),
            'servers_ready' => $serversReady,
            'servers_unreachable' => $serversUnreachable,
            'sites_total' => $sites->count(),
            'sites_active_ssl' => $sitesActiveSsl,
            'sites_failed_ssl' => $sitesFailedSsl,
            'sites_error' => $sitesError,
            'pending_deploys' => $pendingDeploys,
            'healthy' => $issues === [],
            'status_label' => $issues === [] ? 'Healthy' : 'Needs attention',
            'issues' => $issues,
        ];
    }
}
