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

        if ($this->site->isReadyForWorkspace()) {
            $this->toastError(__('This site is already configured. Delete it from the site actions instead.'));

            return;
        }

        try {
            $edgeCanceller->cancel($this->site->fresh(['server', 'domains']));
            $this->redirect(route('edge.index'), navigate: true);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }
}
