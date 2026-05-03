<?php

declare(strict_types=1);

namespace App\Livewire\Edge;

use App\Enums\SiteType;
use App\Models\ProviderCredential;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Org-scoped index of edge container sites. Distinct from
 * /sites (which is the merged VM + container view) because
 * the columns operators care about for edge are very different
 * (backend, region, image tag, live URL) — a separate page
 * keeps the cognitive load low for both surfaces.
 */
class Index extends Component
{
    /** Filter: 'all', a backend slug, or 'failed' to show only failed sites. */
    #[Url]
    public string $filter = 'all';

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $query = Site::query()
            ->where('organization_id', $org->id)
            ->where(function ($q): void {
                $q->where('type', SiteType::Container)
                    ->orWhereNotNull('container_backend');
            })
            ->with('server:id,name')
            ->orderByDesc('created_at');

        match ($this->filter) {
            'digitalocean_app_platform', 'aws_app_runner' => $query->where('container_backend', $this->filter),
            'failed' => $query->where('status', Site::STATUS_CONTAINER_FAILED),
            'provisioning' => $query->where('status', Site::STATUS_CONTAINER_PROVISIONING),
            default => null,
        };

        $sites = $query->get();

        $hasAnyBackendCredential = ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->whereIn('provider', ['digitalocean_app_platform', 'aws_app_runner'])
            ->exists();

        $byBackend = $sites->groupBy('container_backend')->map->count()->all();

        return view('livewire.edge.index', [
            'org' => $org,
            'sites' => $sites,
            'totals' => [
                'all' => $sites->count(),
                'digitalocean_app_platform' => $byBackend['digitalocean_app_platform'] ?? 0,
                'aws_app_runner' => $byBackend['aws_app_runner'] ?? 0,
                'failed' => $sites->where('status', Site::STATUS_CONTAINER_FAILED)->count(),
                'provisioning' => $sites->where('status', Site::STATUS_CONTAINER_PROVISIONING)->count(),
            ],
            'hasAnyBackendCredential' => $hasAnyBackendCredential,
        ])->layout('layouts.app');
    }
}
