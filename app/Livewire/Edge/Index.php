<?php

declare(strict_types=1);

namespace App\Livewire\Edge;

use App\Jobs\TeardownEdgeSiteJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Edge\EdgeSiteCanceller;
use Carbon\Carbon;
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
    use DispatchesToastNotifications;

    /**
     * Filter the table by one of:
     *   - 'all': everything
     *   - 'failed' / 'provisioning': site status buckets
     *   - 'previews': ephemeral preview deploys only
     */
    #[Url]
    public string $filter = 'all';

    public ?string $confirmingDeleteSiteId = null;

    public string $deleteMode = 'now';

    public string $scheduledDeleteAt = '';

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        if (! Feature::active('surface.edge')) {
            return view('livewire.edge.index', [
                'org' => $org,
                'edgeEnabled' => false,
                'sites' => collect(),
                'deleteCandidate' => null,
                'totals' => [
                    'all' => 0,
                    'failed' => 0,
                    'provisioning' => 0,
                    'previews' => 0,
                ],
            ])->layout('layouts.app');
        }

        $allSites = $this->edgeSitesQuery($org)
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

        $deleteCandidate = null;
        if (is_string($this->confirmingDeleteSiteId) && $this->confirmingDeleteSiteId !== '') {
            $deleteCandidate = $allSites->firstWhere('id', $this->confirmingDeleteSiteId);
        }

        return view('livewire.edge.index', [
            'org' => $org,
            'edgeEnabled' => true,
            'sites' => $sites,
            'deleteCandidate' => $deleteCandidate,
            'totals' => [
                'all' => $allSites->count(),
                'active' => $allSites->where('status', Site::STATUS_EDGE_ACTIVE)->count(),
                'failed' => $allSites->where('status', Site::STATUS_EDGE_FAILED)->count(),
                'provisioning' => $allSites->where('status', Site::STATUS_EDGE_PROVISIONING)->count(),
                'previews' => $allSites->filter($isPreview)->count(),
            ],
        ])->layout('layouts.app');
    }

    public function openDeleteSiteModal(string $siteId): void
    {
        $site = $this->edgeSitesQueryForCurrentOrganization()->find($siteId);
        if (! $site instanceof Site) {
            $this->toastError(__('That Edge site could not be found.'));

            return;
        }

        $this->authorize('delete', $site);

        $this->confirmingDeleteSiteId = (string) $site->id;
        $this->deleteMode = 'now';
        $this->scheduledDeleteAt = now()->addDay()->startOfHour()->format('Y-m-d\TH:i');
        $this->resetValidation();
        $this->dispatch('open-modal', 'edge-index-delete-site-confirmation');
    }

    public function closeDeleteSiteModal(): void
    {
        $this->confirmingDeleteSiteId = null;
        $this->deleteMode = 'now';
        $this->scheduledDeleteAt = '';
        $this->resetValidation();
        $this->dispatch('close-modal', 'edge-index-delete-site-confirmation');
    }

    public function deleteSite(EdgeSiteCanceller $canceller): void
    {
        if (! is_string($this->confirmingDeleteSiteId) || $this->confirmingDeleteSiteId === '') {
            return;
        }

        $site = $this->edgeSitesQueryForCurrentOrganization()->find($this->confirmingDeleteSiteId);
        if (! $site instanceof Site) {
            $this->closeDeleteSiteModal();
            $this->toastError(__('That Edge site could not be found.'));

            return;
        }

        $this->authorize('delete', $site);

        $siteName = $site->name;
        if ($this->deleteMode === 'in_30') {
            $scheduledFor = now()->addMinutes(30);
            $this->scheduleEdgeSiteDeletion($site, $scheduledFor);
            $this->closeDeleteSiteModal();
            $this->toastSuccess(__(':name will be deleted in 30 minutes.', ['name' => $siteName]));
            $this->redirect(route('edge.index'), navigate: true);

            return;
        }

        if ($this->deleteMode === 'scheduled') {
            $this->validate([
                'scheduledDeleteAt' => ['required', 'date'],
            ]);

            $scheduledFor = Carbon::parse($this->scheduledDeleteAt, config('app.timezone'));
            if ($scheduledFor->lte(now())) {
                $this->addError('scheduledDeleteAt', __('Choose a date and time in the future.'));

                return;
            }

            $this->scheduleEdgeSiteDeletion($site, $scheduledFor);
            $this->closeDeleteSiteModal();
            $this->toastSuccess(__(':name is scheduled for deletion on :date.', [
                'name' => $siteName,
                'date' => $scheduledFor->timezone(config('app.timezone'))->format('M j, Y g:i A'),
            ]));
            $this->redirect(route('edge.index'), navigate: true);

            return;
        }

        $canceller->cancel($site);
        $this->closeDeleteSiteModal();
        $this->toastSuccess(__('Deleted Edge site ":name".', ['name' => $siteName]));
        $this->redirect(route('edge.index'), navigate: true);
    }

    private function scheduleEdgeSiteDeletion(Site $site, Carbon $scheduledFor): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $edgeMeta = is_array($meta['edge'] ?? null) ? $meta['edge'] : [];
        $edgeMeta['scheduled_deletion_at'] = $scheduledFor->toIso8601String();
        $meta['edge'] = $edgeMeta;

        $site->update(['meta' => $meta]);

        TeardownEdgeSiteJob::dispatch((string) $site->id)->delay($scheduledFor);
    }

    private function edgeSitesQueryForCurrentOrganization(): Builder
    {
        $org = auth()->user()?->currentOrganization();
        abort_if(! $org instanceof Organization, 403);

        return $this->edgeSitesQuery($org);
    }

    private function edgeSitesQuery(Organization $organization): Builder
    {
        return Site::query()
            ->where('organization_id', $organization->id)
            ->where(function (Builder $q): void {
                $q->whereNotNull('edge_backend')
                    ->orWhere('meta->runtime_profile', 'edge_web');
            });
    }
}
