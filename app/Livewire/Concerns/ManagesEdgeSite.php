<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Edge\CreateEdgePreviewSite;
use App\Actions\Edge\RedeployEdgeSite;
use App\Actions\Edge\RollbackEdgeDeployment;
use App\Jobs\TeardownEdgeSiteJob;
use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Services\Edge\EdgeCustomDomainProvisioner;
use App\Services\Edge\EdgeGithubWebhookProvisioner;
use App\Services\Edge\EdgeRouter;
use Illuminate\Database\Eloquent\Collection;

/**
 * Edge dashboard actions for Sites\Settings — redeploy, rollback,
 * preview teardown, custom domains. Mirrors {@see ManagesContainerSite}.
 */
trait ManagesEdgeSite
{
    public string $edge_domain_input = '';

    public string $edge_webhook_account_id = '';

    public function mountEdgeWebhookAccount(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }

        $webhook = is_array($this->site->edgeMeta()['webhook'] ?? null) ? $this->site->edgeMeta()['webhook'] : null;
        $accountId = is_array($webhook) ? (string) ($webhook['account_id'] ?? '') : '';
        if ($accountId !== '') {
            $this->edge_webhook_account_id = $accountId;
        }
    }

    public function enableEdgeGithubWebhook(EdgeGithubWebhookProvisioner $provisioner): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        if ($this->edge_webhook_account_id === '') {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Select a linked GitHub account first.'));
            }

            return;
        }

        $account = auth()->user()?->socialAccounts()->find($this->edge_webhook_account_id);
        if ($account === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('That GitHub account is no longer linked.'));
            }

            return;
        }

        $result = $provisioner->enable($this->site->fresh(), $account);
        if (! ($result['ok'] ?? false)) {
            if (method_exists($this, 'toastError')) {
                $this->toastError((string) ($result['message'] ?? __('Could not connect GitHub webhook.')));
            }

            return;
        }

        $this->site->refresh();
        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess((string) ($result['message'] ?? __('GitHub webhook connected.')));
        }
    }

    public function disableEdgeGithubWebhook(EdgeGithubWebhookProvisioner $provisioner): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $account = null;
        if ($this->edge_webhook_account_id !== '') {
            $account = auth()->user()?->socialAccounts()->find($this->edge_webhook_account_id);
        }

        $provisioner->disable($this->site->fresh(), $account);
        $this->site->refresh();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('GitHub webhook disconnected.'));
        }
    }

    public function redeployEdge(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            (new RedeployEdgeSite)->handle($this->site);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Edge redeploy queued.'));
        }
    }

    public function rollbackEdgeDeployment(string $deploymentId): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        try {
            (new RollbackEdgeDeployment)->handle($this->site, $deploymentId);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Rollback queued — the selected deployment will go live shortly.'));
        }
    }

    public function tearDownEdgePreview(string $previewSiteId): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $preview = Site::query()->find($previewSiteId);
        if ($preview === null
            || $preview->organization_id !== $this->site->organization_id
            || ($preview->edgeMeta()['preview_parent_site_id'] ?? null) !== $this->site->id) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Preview not found or not a child of this site.'));
            }

            return;
        }

        TeardownEdgeSiteJob::dispatch($preview->id);

        if (method_exists($this, 'toastSuccess')) {
            $branch = (string) ($preview->edgeMeta()['preview_branch'] ?? '');
            $this->toastSuccess(__('Preview teardown queued for branch :branch.', ['branch' => $branch]));
        }
    }

    public function attachEdgeDomain(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $hostname = strtolower(trim($this->edge_domain_input));
        $hostname = preg_replace('#^https?://#', '', (string) $hostname);
        $hostname = rtrim((string) $hostname, '/');
        if ($hostname === '' || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i', $hostname)) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Hostname does not look valid.'));
            }

            return;
        }

        $backend = EdgeRouter::backendFor($this->site);
        if ($backend === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('No edge backend available for this site.'));
            }

            return;
        }

        try {
            $backend->attachDomain($this->site->fresh(), $hostname);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        $this->edge_domain_input = '';
        $this->site->refresh();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Custom domain attached. Configure DNS, then verify when ready.'));
        }
    }

    public function verifyEdgeDomain(string $hostname): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $entry = app(EdgeCustomDomainProvisioner::class)->verify($this->site->fresh(), $hostname);
        $this->site->refresh();

        $status = is_array($entry) ? (string) ($entry['dns_status'] ?? '') : '';
        if ($status === 'ready') {
            if (method_exists($this, 'toastSuccess')) {
                $this->toastSuccess(__('DNS verified — :hostname is live on Edge.', ['hostname' => $hostname]));
            }

            return;
        }

        $error = is_array($entry) ? (string) ($entry['error'] ?? '') : '';
        if (method_exists($this, 'toastError')) {
            $this->toastError($error !== '' ? $error : __('DNS verification failed. Check your CNAME and try again.'));
        }
    }

    public function detachEdgeDomain(string $hostname): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $backend = EdgeRouter::backendFor($this->site);
        if ($backend === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('No edge backend available for this site.'));
            }

            return;
        }

        try {
            app(EdgeCustomDomainProvisioner::class)->remove($this->site->fresh(), $hostname);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Custom domain removed.'));
        }
    }

    public function openEdgeTeardownModal(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('delete', $this->site);
        $this->dispatch('open-modal', 'edge-teardown-confirmation');
    }

    public function tearDownEdge(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('delete', $this->site);

        TeardownEdgeSiteJob::dispatch($this->site->id);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Edge site teardown queued.'));
        }
    }

    /**
     * @return Collection<int, EdgeDeployment>
     */
    public function edgeDeploymentHistory(int $limit = 10): Collection
    {
        return $this->site->edgeDeployments()->limit($limit)->get();
    }

    /**
     * @return Collection<int, Site>
     */
    public function edgePreviewSites(): Collection
    {
        return CreateEdgePreviewSite::listForParent($this->site);
    }
}
