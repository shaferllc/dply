<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Actions\Edge\RedeployEdgeSite;
use App\Services\Edge\EdgeSiteCanceller;
use App\Services\Sites\SiteProvisioner;
use Livewire\Attributes\On;

trait ManagesEdgeSiteProvisioning
{
    #[On('site-provisioning-updated')]
    public function refreshProvisioningStatus(string $siteId): void
    {
        if ((string) $this->site->id !== $siteId) {
            return;
        }

        $this->site->refresh();
    }

    public function pollProvisioningStatus(): void
    {
        if ($this->site->isReadyForWorkspace()) {
            return;
        }

        $this->site->refresh();
    }

    public function retryProvisioning(SiteProvisioner $siteProvisioner): void
    {
        $this->authorize('update', $this->site);

        $this->site->refresh();

        if ($this->site->isReadyForWorkspace()) {
            $this->toastSuccess(__('This site is already configured.'));

            return;
        }

        try {
            (new RedeployEdgeSite)->handle($this->site->fresh());
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->site->refresh();
        $this->toastSuccess(__('Edge build queued again.'));
    }

    public function openCancelProvisioningModal(): void
    {
        $this->authorize('update', $this->site);

        $this->openConfirmActionModal(
            'cancelProvisioning',
            [],
            __('Cancel Edge build?'),
            __('This stops the build, removes any partial deployment from the edge network, and deletes the pending site. If you cancel this dialog, the build keeps running.'),
            __('Cancel and remove site'),
            true,
        );
    }

    public function cancelProvisioning(EdgeSiteCanceller $edgeCanceller): void
    {
        $this->authorize('update', $this->site);

        $this->site->refresh();

        // If the site has ever had a successful publish, cancelling the
        // current in-flight build should NOT delete the app — just abort
        // this deployment and leave the live one serving. Full teardown
        // is reserved for the first-deploy case where there's nothing
        // worth keeping. PublishEdgeDeploymentJob sets active_deployment_id
        // on first success, and it persists across redeploys.
        $activeDeploymentId = $this->site->edgeMeta()['active_deployment_id'] ?? null;
        if (is_string($activeDeploymentId) && $activeDeploymentId !== '') {
            $this->abortInFlightDeployment();

            return;
        }

        try {
            $edgeCanceller->cancel($this->site->fresh(['server', 'domains']));
            $this->redirect(route('edge.index'), navigate: true);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Mark the in-flight deployment as failed without tearing down the
     * site. Used by cancel when a previous deploy is already serving on
     * the edge — we want to abandon this specific build, not the app.
     *
     * The running BuildEdgeSiteJob in the queue worker isn't killed
     * (Horizon job-kill is out of scope for v1); it'll run to completion
     * and either silently no-op (publish path sees the deployment
     * already-failed and bails) or get its publish discarded by the
     * active_deployment_id guard. Either way the UI flips back to the
     * existing live deploy immediately.
     */
    protected function abortInFlightDeployment(): void
    {
        $this->site->refresh();

        $deployment = \App\Models\EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->whereIn('status', [
                \App\Models\EdgeDeployment::STATUS_BUILDING,
                \App\Models\EdgeDeployment::STATUS_PUBLISHING,
            ])
            ->orderByDesc('created_at')
            ->first();

        if ($deployment !== null) {
            $deployment->update([
                'status' => \App\Models\EdgeDeployment::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => __('Cancelled by user.'),
            ]);
        }

        // Site stays "active" — the previous successful deployment is
        // still serving on the edge.
        $this->site->update([
            'status' => \App\Models\Site::STATUS_EDGE_ACTIVE,
        ]);

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Build cancelled. The previous deployment is still serving.'));
        }
    }
}
