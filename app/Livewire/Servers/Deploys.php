<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Deploy activity for a single server. Same shape as the site
 * deployments-list page but scoped to every site hosted on the
 * server. Useful for "what's been happening on prod-1?" overviews.
 */
class Deploys extends Component
{
    use WithPagination;

    public Server $server;

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    public const ALLOWED_STATUSES = [
        SiteDeployment::STATUS_RUNNING,
        SiteDeployment::STATUS_SUCCESS,
        SiteDeployment::STATUS_FAILED,
        SiteDeployment::STATUS_SKIPPED,
    ];

    public function mount(Server $server): void
    {
        if ($server->organization_id !== auth()->user()?->currentOrganization()?->id) {
            abort(404);
        }
        $this->server = $server;
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->statusFilter = '';
        $this->resetPage();
    }

    public function render(): View
    {
        $siteIds = Site::query()
            ->where('server_id', $this->server->id)
            ->pluck('id');

        $query = SiteDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->orderByDesc('started_at');

        if (in_array($this->statusFilter, self::ALLOWED_STATUSES, true)) {
            $query->where('status', $this->statusFilter);
        }

        $deployments = $query->paginate(25);

        $sites = Site::query()
            ->whereIn('id', $deployments->pluck('site_id')->unique())
            ->get(['id', 'name', 'slug', 'server_id', 'runtime'])
            ->keyBy('id');

        return view('livewire.servers.deploys', [
            'deployments' => $deployments,
            'sites' => $sites,
            'statuses' => self::ALLOWED_STATUSES,
        ])->layout('layouts.app');
    }
}
