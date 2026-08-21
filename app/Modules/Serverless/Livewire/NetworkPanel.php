<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\PrivateNetwork;
use App\Models\Site;
use App\Modules\Serverless\Services\ServerlessNetworkService;
use App\Support\Sites\SiteRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Pick the network this app's database lives in.
 *
 * The sibling of {@see DatabasePanel}, and deliberately rendered above it: a
 * network houses the database, so the choice comes before provisioning one.
 * See {@see ServerlessNetworkService} for what a network does and does not buy
 * you here — including why the org-shared cache is out of scope.
 */
class NetworkPanel extends Component
{
    use DispatchesToastNotifications;

    public string $siteId = '';

    public string $networkId = '';

    /** Set once the org's VPCs have been pulled, so the empty state can offer it. */
    public bool $synced = false;

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->siteId = $site->id;
        $this->networkId = (string) ($site->serverlessConfig()['network_id'] ?? '');
    }

    private function site(): Site
    {
        // Through the registry — the sibling serverless panels on this page
        // each resolve the same row (see DatabasePanel).
        return app(SiteRegistry::class)->findOrFail($this->siteId);
    }

    /**
     * Import the account's VPCs for this region as attachable networks. An
     * org with no droplets has never had one recorded, so without this the
     * picker is empty even though DigitalOcean gives every region a default.
     */
    public function sync(): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $count = app(ServerlessNetworkService::class)->sync($site);
        $this->synced = true;

        $count > 0
            ? $this->toastSuccess(trans_choice('Found :count network.|Found :count networks.', $count, ['count' => $count]))
            : $this->toastError(__('No networks were found in this app\'s region.'));
    }

    public function attach(): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $networks = app(ServerlessNetworkService::class);

        if (trim($this->networkId) === '') {
            $networks->detach($site);
            $this->toastSuccess(__('This app is no longer attached to a network.'));

            return;
        }

        $network = $networks->available($site)->firstWhere('id', $this->networkId);

        if (! $network instanceof PrivateNetwork) {
            // Stale option — the row was removed, or is in another region.
            $this->networkId = (string) ($site->serverlessConfig()['network_id'] ?? '');
            $this->toastError(__('That network is not available to this app.'));

            return;
        }

        $networks->attach($site, $network);
        $this->toastSuccess(__('Attached. New databases will be created inside this network.'));
    }

    public function render(): View
    {
        $site = $this->site();
        $networks = app(ServerlessNetworkService::class);
        $attached = $networks->attached($site);

        return view('livewire.serverless.network-panel', [
            'available' => $networks->available($site),
            'attached' => $attached,
            'members' => $attached ? $networks->members($attached) : collect(),
            'stale' => $networks->hasClustersOutsideNetwork($site),
        ]);
    }
}
