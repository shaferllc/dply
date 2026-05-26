<?php

declare(strict_types=1);

namespace App\Livewire\Cloud;

use App\Enums\SiteType;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Services\Cloud\CloudRouter;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Org-scoped index of cloud apps. Distinct from /sites (the merged
 * VM + container view) — Cloud is the app-first PaaS surface where
 * the underlying container backend is hidden as an implementation
 * detail. Columns reflect what app operators care about: region,
 * source, status, live URL.
 */
class Index extends Component
{
    /**
     * Filter the table by one of:
     *   - 'all': everything
     *   - status ('failed' / 'provisioning')
     *   - mode ('source' / 'image')
     *   - 'previews': only ephemeral preview deploys
     */
    #[Url]
    public string $filter = 'all';

    public function mount(): void
    {
        abort_unless(Feature::active('surface.cloud'), 404);
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        // Pull the full set first; filter (and totals) are computed
        // on the collection so the totals can stay consistent across
        // filter switches without an extra DB round-trip per total.
        $allSites = Site::query()
            ->where('organization_id', $org->id)
            ->where(function ($q): void {
                $q->where('type', SiteType::Container)
                    ->orWhereNotNull('container_backend');
            })
            ->with('server:id,name')
            ->orderByDesc('created_at')
            ->get();

        $isSource = fn (Site $s) => is_array($s->meta['container']['source'] ?? null);
        $isPreview = fn (Site $s) => ! empty($s->meta['container']['preview_parent_site_id'] ?? null);

        $sites = match ($this->filter) {
            'failed' => $allSites->where('status', Site::STATUS_CONTAINER_FAILED)->values(),
            'provisioning' => $allSites->where('status', Site::STATUS_CONTAINER_PROVISIONING)->values(),
            'source' => $allSites->filter($isSource)->values(),
            'image' => $allSites->reject($isSource)->values(),
            'previews' => $allSites->filter($isPreview)->values(),
            default => $allSites,
        };

        $hasAnyBackendCredential = ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->whereIn('provider', CloudRouter::credentialProviderKeys())
            ->exists();

        return view('livewire.cloud.index', [
            'org' => $org,
            'sites' => $sites,
            'totals' => [
                'all' => $allSites->count(),
                'failed' => $allSites->where('status', Site::STATUS_CONTAINER_FAILED)->count(),
                'provisioning' => $allSites->where('status', Site::STATUS_CONTAINER_PROVISIONING)->count(),
                'source' => $allSites->filter($isSource)->count(),
                'image' => $allSites->reject($isSource)->count(),
                'previews' => $allSites->filter($isPreview)->count(),
            ],
            'hasAnyBackendCredential' => $hasAnyBackendCredential,
        ])->layout('layouts.app');
    }
}
