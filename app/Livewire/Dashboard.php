<?php

namespace App\Livewire;

use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Insights\OrganizationInsightsMetricsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function render(OrganizationInsightsMetricsService $insightsMetrics): View
    {
        $user = auth()->user();
        $serverQuery = $user->servers()->latest();
        $servers = (clone $serverQuery)->withCount('sites')->take(5)->get();
        $serverCount = (clone $serverQuery)->count();
        $org = $user->currentOrganization();
        $fleetInsights = $insightsMetrics->fleetSummary($org);
        $hasProviderCredentials = $org
            ? ProviderCredential::query()->where('organization_id', $org->id)->exists()
            : false;

        $fleetAlert = $org ? $this->computeFleetAlert($org->id) : null;

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
    private function computeFleetAlert(string $organizationId): ?array
    {
        $serverIds = Server::query()
            ->where('organization_id', $organizationId)
            ->pluck('id');
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

        // Failed-latest: count of sites whose most recent settled deploy was failed.
        $failedLatest = 0;
        foreach ($siteIds as $siteId) {
            $latest = SiteDeployment::query()
                ->where('site_id', $siteId)
                ->whereIn('status', [
                    SiteDeployment::STATUS_SUCCESS,
                    SiteDeployment::STATUS_FAILED,
                    SiteDeployment::STATUS_SKIPPED,
                ])
                ->orderByDesc('started_at')
                ->first(['status']);
            if ($latest !== null && $latest->status === SiteDeployment::STATUS_FAILED) {
                $failedLatest++;
            }
        }

        // Drift: cheap signal — sites pinned to engines not on their server.
        $driftServers = 0;
        $servers = Server::query()
            ->whereIn('id', $serverIds)
            ->with('databaseEngines')
            ->get();
        foreach ($servers as $server) {
            $registered = $server->databaseEngines->pluck('engine')->all();
            $hasDrift = Site::query()
                ->where('server_id', $server->id)
                ->whereNotNull('database_engine')
                ->whereNotIn('database_engine', $registered)
                ->exists();
            if ($hasDrift) {
                $driftServers++;
            }
        }

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
