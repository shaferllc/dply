<?php

declare(strict_types=1);

namespace App\Livewire\Edge\Concerns;

use App\Actions\Edge\CreateHybridEdgeStack;
use App\Models\Site;
use App\Modules\Cloud\Backends\CloudRouter;
use App\Modules\Edge\Support\EdgeSsrDetection;
use App\Modules\Edge\Support\HybridEdgeOriginMatcher;
use App\Support\Servers\FakeCloudProvision;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesEdgeHybridOrigin
{
    private function applyHybridOriginSuggestion(): void
    {
        if ($this->originUrlTouched) {
            return;
        }

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        $this->prefillingOrigin = true;

        try {
            $repo = HybridEdgeOriginMatcher::normalizeRepo(trim($this->repo));
            if ($repo !== '') {
                $matched = HybridEdgeOriginMatcher::findForRepo($org, $repo);
                if ($matched !== null) {
                    $this->form->origin_cloud_site_id = (string) $matched->id;
                    $this->form->origin_url = $matched->containerLiveUrl() ?? '';

                    return;
                }
            }

            $name = trim($this->form->name) ?: $this->defaultNameFromRepo();
            if ($name === '') {
                $this->form->origin_cloud_site_id = '';
                $this->form->origin_url = '';

                return;
            }

            $matched = HybridEdgeOriginMatcher::findForEdgeName($org, $name);
            if ($matched !== null) {
                $this->form->origin_cloud_site_id = (string) $matched->id;
                $this->form->origin_url = $matched->containerLiveUrl() ?? '';

                return;
            }

            $this->form->origin_cloud_site_id = '';
            $this->form->origin_url = $this->suggestedHybridOriginUrl($name);
        } finally {
            $this->prefillingOrigin = false;
        }
    }

    public function suggestedHybridOriginUrlForName(): string
    {
        $name = trim($this->form->name);

        return $name !== '' ? $this->suggestedHybridOriginUrl($name) : '';
    }

    private function suggestedHybridOriginUrl(string $name): string
    {
        if ($this->canProvisionCloudOrigin()) {
            return '';
        }

        if (FakeCloudProvision::enabled()) {
            $slug = Str::slug($name) ?: 'app';

            return 'https://'.$slug.'.fake-cloud.dply.local';
        }

        return '';
    }

    private function findOrgCloudSite(string $siteId): ?Site
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return null;
        }

        return Site::query()
            ->where('organization_id', $org->id)
            ->whereIn('container_backend', ['digitalocean_app_platform', 'aws_app_runner', 'dply_cloud'])
            ->find($siteId);
    }

    /**
     * @return list<array{id: string, label: string, live_url: ?string, repo: ?string}>
     */
    private function orgCloudSitesForPicker(): array
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return [];
        }

        return Site::query()
            ->where('organization_id', $org->id)
            ->whereIn('container_backend', ['digitalocean_app_platform', 'aws_app_runner', 'dply_cloud'])
            ->orderBy('name')
            ->get()
            ->map(function (Site $site): array {
                $container = is_array($site->meta['container'] ?? null) ? $site->meta['container'] : [];
                $source = is_array($container['source'] ?? null) ? $container['source'] : [];

                return [
                    'id' => (string) $site->id,
                    'label' => (string) $site->name,
                    'live_url' => $site->containerLiveUrl(),
                    'repo' => is_string($source['repo'] ?? null) ? (string) $source['repo'] : null,
                ];
            })
            ->values()
            ->all();
    }

    public function openHybridStackModal(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validateCreateForm();

        $this->confirmingHybridStack = true;
        $this->dispatch('open-modal', 'edge-create-hybrid-stack-confirmation');
    }

    public function closeHybridStackModal(): void
    {
        $this->confirmingHybridStack = false;
        $this->dispatch('close-modal', 'edge-create-hybrid-stack-confirmation');
    }

    public function deployHybridStack(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validateCreateForm();

        if ($this->detectedPlan !== [] && ! EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan)) {
            $this->toastError(__('Hybrid stack deploy is only available for server-rendered JavaScript frameworks.'));

            return;
        }

        try {
            $result = (new CreateHybridEdgeStack)->handle(
                auth()->user(),
                $org,
                $this->form->hybridStackPayload($this->detectedPlan, $this->repo, $this->branch),
            );
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->closeHybridStackModal();

        if ($result['redirect_to'] === 'edge' && $result['edge_site'] instanceof Site) {
            $this->toastSuccess(__('Edge hybrid app build queued. We\'ll keep the site workspace updated as it goes live.'));
            $this->redirect(route('sites.show', ['server' => $result['edge_site']->server, 'site' => $result['edge_site']]), navigate: true);

            return;
        }

        $this->toastSuccess(__('Cloud origin queued. We\'ll create the Edge hybrid app when the origin is live.'));
        $this->redirect(route('sites.show', ['server' => $result['cloud_site']->server, 'site' => $result['cloud_site']]), navigate: true);
    }

    private function canProvisionCloudOrigin(): bool
    {
        if (! Feature::active('surface.cloud')) {
            return false;
        }

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return false;
        }

        return CloudRouter::pickAutoBackend($org->id) !== null;
    }

    private function showHybridStackCta(): bool
    {
        return $this->shouldAutoProvisionHybridOrigin();
    }

    private function shouldAutoProvisionHybridOrigin(): bool
    {
        return $this->form->runtime_mode === 'hybrid'
            && trim($this->form->origin_url) === ''
            && $this->detectedPlan !== []
            && EdgeSsrDetection::planLooksLikeSsr($this->detectedPlan)
            && $this->canProvisionCloudOrigin();
    }
}
