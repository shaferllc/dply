<?php

namespace App\Livewire\Servers;

use App\Actions\Servers\ResolveServerCreateCatalog;
use App\Jobs\ApplyWorkerPoolExposureJob;
use App\Jobs\CollectWorkerPoolHorizonSnapshotJob;
use App\Jobs\CollectWorkerPoolStatsJob;
use App\Jobs\DetectWorkerPoolHorizonConfigJob;
use App\Jobs\PushWorkerPoolHorizonConfigJob;
use App\Jobs\ReconcileWorkerPoolJob;
use App\Jobs\RunWorkerPoolTestJobsJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesWorkerPoolCrossRegion;
use App\Livewire\Servers\Concerns\ManagesWorkerPoolFailedJobs;
use App\Livewire\Servers\Concerns\ManagesWorkerPoolHorizon;
use App\Livewire\Servers\Concerns\ManagesWorkerPoolScaling;
use App\Livewire\Servers\Concerns\ManagesWorkerPoolStats;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\ConsoleAction;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\WorkerPool;
use App\Services\WorkerPools\WorkerCloneProvisioner;
use App\Services\WorkerPools\WorkerPoolManager;
use App\Support\WorkerPools\WorkerPoolHorizonConfig;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Server workspace page for cloning + scaling a worker server as a Worker Pool.
 * Same-region v1: create a pool from a worker host, set a desired member count,
 * promote a member, or remove (drain + destroy) a replica.
 *
 * See doc/specs/worker-pools/02-specification.md.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceWorkerPool extends Component
{
    use ConfirmsActionWithModal;
    use DismissesServerConsoleActionRun;
    use InteractsWithServerWorkspace;
    use ManagesWorkerPoolCrossRegion;
    use ManagesWorkerPoolFailedJobs;
    use ManagesWorkerPoolHorizon;
    use ManagesWorkerPoolScaling;
    use ManagesWorkerPoolStats;
    use RendersWorkspacePlaceholder;

    #[Url(as: 'tab')]
    public string $tab = 'overview';

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

    // Horizon config (env-var driven; see WorkerPoolHorizonConfig).
    public string $hz_queues = 'default';

    public int $hz_min_processes = 1;

    public int $hz_max_processes = 4;

    public string $hz_balance = 'auto';

    public int $hz_memory = 128;

    public int $hz_timeout = 720;

    public int $hz_tries = 1;

    /** Process manager the pool's worker daemons run under: systemd | supervisor. */
    public string $hz_process_manager = WorkerPool::PM_SYSTEMD;

    /** True while a queue auto-detection run is in flight (drives the spinner + poll). */
    public bool $hzDetecting = false;

    /** ISO8601 stamp of when the current detection was requested, to recognise its result. */
    public ?string $hzDetectRequestedAt = null;

    /**
     * Live per-job feed, newest first, pushed over Reverb from the worker boxes
     * (see WorkerPoolJobEvent). Capped; this is a rolling window, not history.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $liveJobs = [];

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

            $hz = WorkerPoolHorizonConfig::for($pool);
            $this->hz_queues = implode(', ', $hz['queues']);
            $this->hz_min_processes = $hz['min_processes'];
            $this->hz_max_processes = $hz['max_processes'];
            $this->hz_balance = $hz['balance'];
            $this->hz_memory = $hz['memory'];
            $this->hz_timeout = $hz['timeout'];
            $this->hz_tries = $hz['tries'];
            $this->hz_process_manager = $pool->processManager();
        } else {
            $this->pool_name = $server->name.' pool';
        }

        // Landing straight on the Horizon tab (?tab=horizon): pull a fresh
        // snapshot immediately so stats + Live jobs fill in without waiting for
        // the first poll tick or a manual Refresh. Throttled, so a recent pull
        // is reused.
        if ($this->tab === 'horizon') {
            $this->pollHorizon();
        }
        // Catalog (region/size dropdowns) is loaded lazily when the operator picks
        // a provider/credential — not on mount, to avoid a provider API call on
        // every page view. Until then the cross-region form uses text inputs.
    }


    public function render(): View
    {
        $pool = $this->pool();
        $members = $pool
            ? $pool->servers()->orderByRaw("CASE WHEN pool_role = 'primary' THEN 0 ELSE 1 END")->orderBy('created_at')->get()
            : collect();

        // Self-heal a stale "scaling" status: the reconciler only flips the
        // column to steady when it fully converges, so a stopped/exhausted
        // reconcile can leave it stuck on "scaling" forever. If the pool is
        // actually settled (nothing converging or draining and active is at or
        // above desired), correct it here so the rest of the app agrees.
        if ($pool && $pool->status !== WorkerPool::STATUS_STEADY) {
            $converging = $members->filter(fn ($m) => ! $m->isPoolPrimary() && in_array($m->poolMemberState(), [WorkerPool::MEMBER_PROVISIONING, WorkerPool::MEMBER_REPLAYING, WorkerPool::MEMBER_DEPLOYING], true))->count();
            $draining = $members->filter(fn ($m) => $m->poolMemberState() === WorkerPool::MEMBER_DRAINING)->count();
            if ($converging === 0 && $draining === 0 && $pool->activeMemberCount() >= $pool->desired_count) {
                $pool->forceFill(['status' => WorkerPool::STATUS_STEADY])->save();
            }
        }

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
            'scaleRun' => $this->scaleRun(),
            'testRun' => $this->testRun(),
            'statsRun' => $this->statsRun(),
        ]);
    }
}
