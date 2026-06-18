<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Jobs\ApplyWorkerPoolExposureJob;
use App\Services\WorkerPools\WorkerPoolManager;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesWorkerPoolCrossRegion
{


    public function updatedCrProvider(): void
    {
        // New provider → its credentials/regions/sizes differ; reset dependents.
        $this->reset('cr_credential_id', 'cr_region', 'cr_size');
        $this->loadCrCatalog();
    }

    public function updatedCrCredentialId(): void
    {
        $this->loadCrCatalog();
    }

    /**
     * Pull the region + size catalog for the effective provider/credential so the
     * cross-region form can show dropdowns. Best-effort: provider-API failures
     * leave the lists empty and the blade falls back to free-text inputs.
     */
    public function loadCrCatalog(): void
    {
        $sameProvider = $this->cr_provider === '' || $this->cr_provider === $this->server->provider->value;
        $provider = $this->cr_provider !== '' ? $this->cr_provider : $this->server->provider->value;
        $credId = $this->cr_credential_id !== ''
            ? $this->cr_credential_id
            : ($sameProvider ? (string) $this->server->provider_credential_id : '');

        if ($credId === '') {
            $this->cr_regions = [];
            $this->cr_sizes = [];

            return;
        }

        try {
            $catalog = ResolveServerCreateCatalog::run(
                $this->server->organization,
                $provider,
                $credId,
                $this->cr_region,
            );
            $this->cr_regions = $catalog['regions'] ?? [];
            $this->cr_sizes = $catalog['sizes'] ?? [];
        } catch (\Throwable) {
            $this->cr_regions = [];
            $this->cr_sizes = [];
        }
    }

    public function applyExposure(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        ApplyWorkerPoolExposureJob::dispatch((string) $pool->id, (string) auth()->id());
        $this->toastSuccess(__('Applying backend exposure — binding backends and allowlisting worker IPs in the background.'));
    }

    public function addCrossRegion(WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool to scale.'));

            return;
        }
        if (! $this->cr_ack_secrets) {
            $this->toastError(__('Confirm that secrets will be replicated to the new region/provider first.'));

            return;
        }

        try {
            $member = $manager->addCrossRegionReplica(
                $pool,
                trim($this->cr_region),
                $this->cr_size !== '' ? trim($this->cr_size) : null,
                $this->cr_credential_id !== '' ? $this->cr_credential_id : null,
                $this->cr_provider !== '' ? $this->cr_provider : null,
            );
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->reset('cr_provider', 'cr_credential_id', 'cr_region', 'cr_size', 'cr_ack_secrets', 'cr_regions', 'cr_sizes');
        $this->toastSuccess(__('Provisioning :name in :region. Once ready it replays the sites and you’ll see which backends to expose.', [
            'name' => $member->name,
            'region' => $member->region,
        ]));
    }

    /**
     * Cross-region exposure requirements recorded on members (which private
     * backends must be exposed + the clone allowlisted). Flattened for the view.
     *
     * @return array<int, array<string, mixed>>
     */
    public function exposurePlan(): array
    {
        $pool = $this->pool();
        if (! $pool) {
            return [];
        }

        $plan = [];
        foreach ($pool->servers as $member) {
            $exposures = $member->meta['pool']['exposures'] ?? null;
            if (! is_array($exposures)) {
                continue;
            }
            foreach ($exposures as $e) {
                $plan[] = array_merge($e, ['member_name' => $member->name, 'member_ip' => $member->ip_address]);
            }
        }

        return $plan;
    }
}
