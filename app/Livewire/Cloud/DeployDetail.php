<?php

declare(strict_types=1);

namespace App\Livewire\Cloud;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Cloud\Backends\CloudRouter;
use App\Modules\Cloud\Services\DigitalOceanAppPlatformService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Per-deploy detail view for a Cloud container site. Fetches the
 * deployment from the backend on render so the phases timeline,
 * per-component status grid, and log links reflect DO's current view.
 *
 * Re-uses the cancel-in-progress wiring already shipped — when the
 * deployment is in BUILDING/DEPLOYING/PENDING phase, the Cancel
 * button is enabled and dispatches via CloudBackend::cancelInProgressDeployment.
 *
 * For App Runner / Fake sites the page renders with a "details not
 * available for this backend" empty state — only DO App Platform
 * exposes the per-deployment shape we render here in v1.
 */
#[Layout('layouts.app')]
class DeployDetail extends Component
{
    use DispatchesToastNotifications;

    public Server $server;

    public Site $site;

    public string $deployId;

    /** @var array<string, mixed>|null */
    public ?array $deployment = null;

    public ?string $loadError = null;

    public function mount(Server $server, Site $site, string $deploy): void
    {
        $this->authorize('view', $site);

        $this->server = $server;
        $this->site = $site;
        $this->deployId = $deploy;

        $this->loadDeployment();
    }

    public function refreshDeployment(): void
    {
        $this->loadDeployment();
    }

    public function cancelDeploy(): void
    {
        $this->authorize('update', $this->site);

        $backend = CloudRouter::backendFor($this->site);
        $credential = CloudRouter::credentialFor($this->site);
        if ($backend === null || $credential === null) {
            $this->toastError(__('No backend or credential resolvable for this site.'));

            return;
        }

        try {
            $canceled = $backend->cancelInProgressDeployment($this->site, $credential);
        } catch (\Throwable $e) {
            $this->toastError(__('Cancel failed: :error', ['error' => $e->getMessage()]));

            return;
        }

        $this->toastSuccess($canceled
            ? __('Deploy canceled.')
            : __('No in-progress deploy to cancel.')
        );
        $this->loadDeployment();
    }

    private function loadDeployment(): void
    {
        $this->deployment = null;
        $this->loadError = null;

        if ($this->site->container_backend !== 'digitalocean_app_platform') {
            $this->loadError = __('Deploy detail is only available for DigitalOcean App Platform sites in v1.');

            return;
        }

        $credential = CloudRouter::credentialFor($this->site);
        if ($credential === null || ! is_string($this->site->container_backend_id) || $this->site->container_backend_id === '') {
            $this->loadError = __('Site has no backend app ID yet — try again once provisioning completes.');

            return;
        }

        try {
            $service = new DigitalOceanAppPlatformService($credential);
            $this->deployment = $service->getDeployment($this->site->container_backend_id, $this->deployId);
        } catch (\Throwable $e) {
            $this->loadError = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.cloud.deploy-detail');
    }
}
