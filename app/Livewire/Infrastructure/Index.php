<?php

declare(strict_types=1);

namespace App\Livewire\Infrastructure;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org-scoped hub above typed compute indexes (servers, cloud, serverless).
 * Surfaces cross-model counts so operators can orient before drilling in.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    public function mount(): void
    {
        // Infrastructure dashboard is the cross-surface triage view; when
        // only VM is active for the org, it's noise — /servers is the
        // single source of truth. Reappears the moment any non-VM surface
        // is enabled (admin toggle, env override).
        abort_unless(multi_surface_active(), 404);

        // Fleet Command Center is the default multi-surface home — the
        // compute inventory lives there with cross-product ops tiles.
        if (Feature::active('surface.fleet')) {
            $this->redirect(route('fleet.index'), navigate: true);
        }
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $serversQuery = $this->serversQuery($org->id);
        $serverTotal = (clone $serversQuery)->count();
        $serverReady = (clone $serversQuery)->where('status', Server::STATUS_READY)->count();

        $cloudQuery = Site::query()
            ->where('organization_id', $org->id)
            ->where(function (Builder $q): void {
                $q->where('type', SiteType::Container)
                    ->orWhereNotNull('container_backend');
            });

        $cloudTotal = (clone $cloudQuery)->count();
        $cloudActive = (clone $cloudQuery)
            ->where('status', Site::STATUS_CONTAINER_ACTIVE)
            ->count();

        $serverlessTotal = Site::query()
            ->where('organization_id', $org->id)
            ->whereIn('meta->runtime_profile', ['digitalocean_functions_web', 'aws_lambda_bref_web'])
            ->count();

        $edgeQuery = Site::query()
            ->where('organization_id', $org->id)
            ->where(function (Builder $q): void {
                $q->whereNotNull('edge_backend')
                    ->orWhere('meta->runtime_profile', 'edge_web');
            });

        $edgeTotal = (clone $edgeQuery)->count();
        $edgeActive = (clone $edgeQuery)
            ->where('status', Site::STATUS_EDGE_ACTIVE)
            ->count();

        return view('livewire.infrastructure.index', [
            'org' => $org,
            'counts' => [
                'servers' => [
                    'ready' => $serverReady,
                    'total' => $serverTotal,
                ],
                'cloud' => [
                    'active' => $cloudActive,
                    'total' => $cloudTotal,
                ],
                'serverless' => [
                    'total' => $serverlessTotal,
                ],
                'edge' => [
                    'active' => $edgeActive,
                    'total' => $edgeTotal,
                ],
            ],
            'cloudEnabled' => Feature::active('surface.cloud'),
            'edgeEnabled' => Feature::active('surface.edge'),
            'serverlessEnabled' => Feature::active('surface.serverless'),
            'fleetEnabled' => Feature::active('surface.fleet'),
        ]);
    }

    protected function serversQuery(string $organizationId): Builder
    {
        $query = Server::query()
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
