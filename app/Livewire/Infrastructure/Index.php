<?php

declare(strict_types=1);

namespace App\Livewire\Infrastructure;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org-scoped hub above typed compute indexes (servers, cloud, serverless),
 * and the landing page for the cross-product Operations views (health,
 * deploys, domains, env, blast radius, previews, contracts) that used to
 * live under a separate /fleet section.
 *
 * Deliberately NOT gated on multi_surface_active(): the Operations views are
 * org-wide and useful to VM-only orgs, so the hub has to stay reachable even
 * when Cloud/Edge/Serverless are all off. The compute grid below simply shows
 * fewer cards in that case.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $serversQuery = $this->serversQuery($org->id);
        $serverTotal = (clone $serversQuery)->count();
        $serverReady = (clone $serversQuery)->where('status', Server::STATUS_READY)->count();

        return view('livewire.infrastructure.index', [
            'org' => $org,
            'counts' => [
                'servers' => [
                    'ready' => $serverReady,
                    'total' => $serverTotal,
                ],
            ],
            'runningDeploys' => $this->runningDeployCount($org->id),
            'successRate' => $this->deploySuccessRate($org->id),
        ]);
    }

    /**
     * Deploys currently in flight across every site in the org. Drives the
     * Operations strip on the hub (previously the standalone overview page).
     */
    protected function runningDeployCount(string $organizationId): int
    {
        return SiteDeployment::query()
            ->whereIn('site_id', $this->orgSiteIds($organizationId))
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->count();
    }

    /**
     * Deploy success rate over the last 7 days (settled deploys only).
     *
     * @return array{percent: ?int, total: int}
     */
    protected function deploySuccessRate(string $organizationId): array
    {
        $base = SiteDeployment::query()
            ->whereIn('site_id', $this->orgSiteIds($organizationId))
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

    /**
     * @return Collection<int, string>
     */
    protected function orgSiteIds(string $organizationId): Collection
    {
        return Site::query()
            ->whereIn('server_id', Server::query()->where('organization_id', $organizationId)->select('id'))
            ->pluck('id');
    }

    /**
     * @return Builder<Server>
     */
    protected function serversQuery(string $organizationId): Builder
    {
        // Machines only, same as /servers — this is the fleet view.
        $query = Server::query()
            ->onlyMachineHosts()
            ->where(function (Builder $q) use ($organizationId): void {
                $q->where('organization_id', $organizationId)
                    ->orWhere(fn (Builder $q2) => $q2->whereNull('organization_id')->where('user_id', auth()->id()));
            });

        $team = auth()->user()?->currentTeam();
        if ($team) {
            $query->where('team_id', $team->id);
        }

        return $query;
    }
}
