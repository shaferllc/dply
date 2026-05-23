<?php

declare(strict_types=1);

namespace App\Livewire\Edge;

use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Org-scoped index of dply Edge sites — static/SSG apps on the
 * managed edge delivery plane (git → build → R2 + CF Worker).
 * When {@see surface.edge} is inactive, renders a coming-soon shell
 * so Browse nav can link here without a 404.
 */
class Index extends Component
{
    /**
     * Filter the table by one of:
     *   - 'all': everything
     *   - 'failed' / 'provisioning': site status buckets
     *   - 'previews': ephemeral preview deploys only
     */
    #[Url]
    public string $filter = 'all';

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        if (! Feature::active('surface.edge')) {
            return view('livewire.edge.index', [
                'org' => $org,
                'edgeEnabled' => false,
                'sites' => collect(),
                'totals' => [
                    'all' => 0,
                    'failed' => 0,
                    'provisioning' => 0,
                    'previews' => 0,
                ],
            ])->layout('layouts.app');
        }

        $allSites = Site::query()
            ->where('organization_id', $org->id)
            ->where(function (Builder $q): void {
                $q->whereNotNull('edge_backend')
                    ->orWhere('meta->runtime_profile', 'edge_web');
            })
            ->with('server:id,name')
            ->orderByDesc('created_at')
            ->get();

        $isPreview = fn (Site $s) => ! empty($s->edgeMeta()['preview_parent_site_id'] ?? null);

        $sites = match ($this->filter) {
            'failed' => $allSites->where('status', Site::STATUS_EDGE_FAILED)->values(),
            'provisioning' => $allSites->where('status', Site::STATUS_EDGE_PROVISIONING)->values(),
            'previews' => $allSites->filter($isPreview)->values(),
            default => $allSites,
        };

        return view('livewire.edge.index', [
            'org' => $org,
            'edgeEnabled' => true,
            'sites' => $sites,
            'totals' => [
                'all' => $allSites->count(),
                'failed' => $allSites->where('status', Site::STATUS_EDGE_FAILED)->count(),
                'provisioning' => $allSites->where('status', Site::STATUS_EDGE_PROVISIONING)->count(),
                'previews' => $allSites->filter($isPreview)->count(),
            ],
        ])->layout('layouts.app');
    }
}
