<?php

namespace App\Livewire;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Insights\Services\OrganizationInsightsMetricsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function render(OrganizationInsightsMetricsService $insightsMetrics): View
    {
        $user = auth()->user();
        // Edge / serverless placeholder hosts are product-line inventory, not
        // the BYO Servers fleet — keep the dashboard card aligned with /servers.
        $serverQuery = $user->servers()
            ->withoutEdgeHosts()
            ->withoutServerlessHosts()
            ->latest();
        $servers = (clone $serverQuery)->withCount('sites')->take(5)->get();
        $serverCount = (clone $serverQuery)->count();
        $org = $user->currentOrganization();
        $fleetInsights = $insightsMetrics->fleetSummary($org);
        $hasProviderCredentials = $org
            ? ProviderCredential::query()->where('organization_id', $org->id)->exists()
            : false;

        $fleetAlert = $org ? $this->computeFleetAlert($org) : null;

        return view('livewire.dashboard', [
            'organization' => $org,
            'servers' => $servers,
            'serverCount' => $serverCount,
            'fleetInsights' => $fleetInsights,
            'hasProviderCredentials' => $hasProviderCredentials,
            'fleetAlert' => $fleetAlert,
        ]);
    }

    /**
     * Snapshot of fleet trouble for the dashboard banner. Returns null
     * when nothing's wrong (banner stays hidden); otherwise returns
     * counts that the view turns into a sentence.
     *
     * @return array{
     *     failed_latest: int,
     *     long_running: int,
     *     drift_servers: int
     * }|null
     */
    private function computeFleetAlert(Organization $organization): ?array
    {
        $serverIds = $organization->serverIds();
        if ($serverIds->isEmpty()) {
            return null;
        }

        $siteIds = Site::query()
            ->whereIn('server_id', $serverIds)
            ->pluck('id');

        $longRunning = SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->where('started_at', '<', now()->subMinutes(15))
            ->count();

        // Failed-latest: count of sites whose most recent settled deploy failed.
        //
        // This used to run one "latest settled deploy" query per site, so the
        // dashboard's query count grew with the fleet. Postgres DISTINCT ON does
        // the same latest-row-per-group pick in a single statement, and the
        // `order by site_id, started_at desc` reproduces the old
        // orderByDesc()->first() exactly — including Postgres's NULLS FIRST on a
        // DESC sort, so a deploy with no started_at still wins its site.
        $latestSettledPerSite = SiteDeployment::query()
            ->selectRaw('distinct on (site_id) site_id, status')
            ->whereIn('site_id', $siteIds)
            ->whereIn('status', [
                SiteDeployment::STATUS_SUCCESS,
                SiteDeployment::STATUS_FAILED,
                SiteDeployment::STATUS_SKIPPED,
            ])
            ->orderBy('site_id')
            ->orderByDesc('started_at');

        $failedLatest = DB::query()
            ->fromSub($latestSettledPerSite, 'latest_settled')
            ->where('status', SiteDeployment::STATUS_FAILED)
            ->count();

        // Drift: cheap signal — sites pinned to engines not on their server.
        //
        // Also collapsed from "fetch every server + its engines, then one exists()
        // per server" to a single NOT EXISTS. Servers with no registered engines
        // still count as drift, matching the old whereNotIn() against an empty
        // list (which Laravel compiles to a tautology).
        $driftServers = Site::query()
            ->whereIn('server_id', $serverIds)
            ->whereNotNull('database_engine')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('server_database_engines')
                    ->whereColumn('server_database_engines.server_id', 'sites.server_id')
                    ->whereColumn('server_database_engines.engine', 'sites.database_engine');
            })
            ->distinct()
            ->count('server_id');

        if ($failedLatest === 0 && $longRunning === 0 && $driftServers === 0) {
            return null;
        }

        return [
            'failed_latest' => $failedLatest,
            'long_running' => $longRunning,
            'drift_servers' => $driftServers,
        ];
    }
}
