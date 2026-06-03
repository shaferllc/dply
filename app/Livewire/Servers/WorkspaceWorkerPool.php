<?php

namespace App\Livewire\Servers;

use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Jobs\ApplyWorkerPoolExposureJob;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\WorkerPool;
use App\Services\WorkerPools\WorkerCloneProvisioner;
use App\Services\WorkerPools\WorkerPoolManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Server workspace page for cloning + scaling a worker server as a Worker Pool.
 * Same-region v1: create a pool from a worker host, set a desired member count,
 * promote a member, or remove (drain + destroy) a replica.
 *
 * See doc/specs/worker-pools/02-specification.md.
 */
#[Layout('layouts.app')]
class WorkspaceWorkerPool extends Component
{
    use InteractsWithServerWorkspace;

    public string $pool_name = '';

    public int $desired_count = 1;

    // Cross-region add (Phase 2)
    public string $cr_provider = '';

    public string $cr_credential_id = '';

    public string $cr_region = '';

    public string $cr_size = '';

    public bool $cr_ack_secrets = false;

    /** Loaded region/size catalog for the chosen provider+credential (empty → text fallback). */
    public array $cr_regions = [];

    public array $cr_sizes = [];

    // Autoscale config
    public bool $as_enabled = false;

    public int $as_min = 1;

    public int $as_max = 5;

    public int $as_backlog = 100;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);

        $pool = $this->pool();
        if ($pool) {
            $this->desired_count = $pool->desired_count;
            $this->pool_name = $pool->name;
            $as = is_array($pool->meta['autoscale'] ?? null) ? $pool->meta['autoscale'] : [];
            $this->as_enabled = (bool) ($as['enabled'] ?? false);
            $this->as_min = (int) ($as['min'] ?? 1);
            $this->as_max = (int) ($as['max'] ?? min(5, $pool->max_size));
            $this->as_backlog = (int) ($as['per_worker_backlog'] ?? 100);
        } else {
            $this->pool_name = $server->name.' pool';
        }
        // Catalog (region/size dropdowns) is loaded lazily when the operator picks
        // a provider/credential — not on mount, to avoid a provider API call on
        // every page view. Until then the cross-region form uses text inputs.
    }

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

    public function saveAutoscale(): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool.'));

            return;
        }

        $min = max(1, $this->as_min);
        $max = max($min, min($this->as_max, $pool->max_size));
        $meta = is_array($pool->meta) ? $pool->meta : [];
        $existing = is_array($meta['autoscale'] ?? null) ? $meta['autoscale'] : [];
        $meta['autoscale'] = array_merge($existing, [
            'enabled' => $this->as_enabled,
            'min' => $min,
            'max' => $max,
            'per_worker_backlog' => max(1, $this->as_backlog),
            'cooldown_seconds' => (int) ($existing['cooldown_seconds'] ?? 300),
        ]);
        $pool->forceFill(['meta' => $meta])->save();

        $this->as_min = $min;
        $this->as_max = $max;
        $this->toastSuccess($this->as_enabled
            ? __('Autoscaling enabled (:min–:max workers).', ['min' => $min, 'max' => $max])
            : __('Autoscaling disabled.'));
    }

    public function pool(): ?WorkerPool
    {
        $id = $this->server->worker_pool_id;

        return $id ? WorkerPool::query()->with('servers')->find($id) : null;
    }

    public function createPool(WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        if (! $this->server->isWorkerHost()) {
            $this->toastError(__('Only worker servers can start a worker pool.'));

            return;
        }

        try {
            $pool = $manager->createPool(auth()->user(), $this->server->fresh(), trim($this->pool_name));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->server->refresh();
        $this->desired_count = $pool->desired_count;
        $this->toastSuccess(__('Worker pool created. This server is the primary.'));
    }

    public function scale(WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        if (! $pool) {
            $this->toastError(__('No pool to scale.'));

            return;
        }

        try {
            $manager->setDesiredCount($pool, (int) $this->desired_count);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Scaling to :n worker(s). Provisioning runs in the background.', ['n' => (int) $this->desired_count]));
    }

    public function promote(string $serverId, WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        $member = $pool?->servers->firstWhere('id', $serverId);
        if (! $pool || ! $member) {
            $this->toastError(__('Member not found.'));

            return;
        }

        try {
            $manager->promote($pool, $member);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->server->refresh();
        $this->toastSuccess(__(':name is now the pool primary.', ['name' => $member->name]));
    }

    public function removeMember(string $serverId, WorkerPoolManager $manager): void
    {
        Gate::authorize('update', $this->server);

        $pool = $this->pool();
        $member = $pool?->servers->firstWhere('id', $serverId);
        if (! $pool || ! $member) {
            $this->toastError(__('Member not found.'));

            return;
        }

        try {
            $manager->removeMember($pool, $member);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Draining :name, then it will be destroyed.', ['name' => $member->name]));
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

    public function render(): View
    {
        $pool = $this->pool();
        $members = $pool
            ? $pool->servers()->orderByRaw("CASE WHEN pool_role = 'primary' THEN 0 ELSE 1 END")->orderBy('created_at')->get()
            : collect();

        // Cross-provider selectors: providers we can clone onto that the org has
        // a credential for, plus the source provider (same-provider region change).
        $supported = collect(WorkerCloneProvisioner::supportedProviders())
            ->map(fn ($p) => $p->value);
        $creds = ProviderCredential::query()
            ->where('organization_id', $this->server->organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'provider']);
        $providerOptions = $creds->pluck('provider')->push($this->server->provider->value)
            ->unique()
            ->filter(fn ($p) => $supported->contains($p))
            ->values();
        $credentialOptions = $this->cr_provider !== ''
            ? $creds->where('provider', $this->cr_provider)->values()
            : collect();

        // Per-worker monthly estimate from the primary's billing tier (clones
        // default to the same size). Cents; 0 when specs aren't known yet.
        $perWorkerCents = ($pool?->primaryServer ?? $this->server)->billingTier()->priceCents();

        return view('livewire.servers.workspace-worker-pool', [
            'server' => $this->server,
            'pool' => $pool,
            'members' => $members,
            'providerOptions' => $providerOptions,
            'credentialOptions' => $credentialOptions,
            'perWorkerCents' => $perWorkerCents,
        ]);
    }
}
