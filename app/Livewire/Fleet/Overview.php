<?php

declare(strict_types=1);

namespace App\Livewire\Fleet;

use App\Livewire\Concerns\RequiresFeature;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Fleet ops landing page. Explains what the Fleet surface is — a set of
 * cross-product, read-only views over every server and site in the org —
 * and routes operators into each section. Surfaces a handful of headline
 * counts (servers, sites, in-flight deploys, 7-day success) so the page
 * doubles as an at-a-glance entry point. Read-only.
 */
class Overview extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'surface.fleet';

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $serverIds = Server::query()
            ->where('organization_id', $org->id)
            ->pluck('id');

        $siteIds = Site::query()
            ->whereIn('server_id', $serverIds)
            ->pluck('id');

        $running = SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->count();

        return view('livewire.fleet.overview', [
            'org' => $org,
            'serverCount' => $serverIds->count(),
            'siteCount' => $siteIds->count(),
            'runningDeploys' => $running,
            'successRate' => $this->computeSuccessRate($siteIds),
        ])->layout('layouts.app');
    }

    /**
     * Deploy success rate over the last 7 days (settled deploys only).
     *
     * @param  Collection<int, string>  $siteIds
     * @return array{percent: ?int, total: int}
     */
    private function computeSuccessRate($siteIds): array
    {
        $base = SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->where('started_at', '>=', now()->subDays(7))
            ->whereIn('status', [
                SiteDeployment::STATUS_SUCCESS,
                SiteDeployment::STATUS_FAILED,
            ]);

        $total = (clone $base)->count();
        $success = (clone $base)->where('status', SiteDeployment::STATUS_SUCCESS)->count();

        return [
            'percent' => $total > 0 ? (int) round($success / $total * 100) : null,
            'total' => $total,
        ];
    }
}
