<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Modules\Database\Actions\CreateCloudDatabase;
use App\Modules\Database\Jobs\AttachCloudDatabaseJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\CloudDatabase;
use App\Models\Server;
use App\Models\Site;
use App\Models\WorkerPool;
use App\Support\Sites\SiteWorkerCoverage;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Resources" tab — one page where the operator sees every backing
 * service attached to a site (databases, the
 * scheduler), attaches more with one modal, and detaches in place.
 *
 * Existing surfaces remain authoritative for the per-resource detail
 * pages (Databases, the workers operations); this page is
 * the high-leverage "what's wired to this site" view that Laravel
 * Cloud popularised. Only renders for container sites — VM / serverless
 * sites use their own runtime panels.
 *
 * Livewire #[Computed] methods are read as properties in PHP and Blade;
 * PHPStan cannot see that magic, so the contract is stated here.
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CloudDatabase> $attachedDatabases
 */
#[Layout('layouts.app')]
class Resources extends Component
{
    use DispatchesToastNotifications;

    public Server $server;

    public Site $site;

    /** When true, suppress page chrome (breadcrumb / hero) — already wrapped by Settings. */
    public bool $embedded = false;

    /**
     * Container (Cloud) sites get the full attach/detach CRUD against
     * CloudDatabase pivots. VM sites get a read-only roll-up of their
     * Supervisor + systemd workers that links out to the Workers page to
     * manage. Set in mount() from the runtime.
     */
    public bool $isContainer = true;

    /** Modal pane: '' (closed) | 'attach' (root picker) | 'database-existing' | 'database-new'. */
    public string $modal = '';

    // database-existing form
    public ?string $attach_database_id = null;

    // database-new form
    public string $new_database_name = '';

    public string $new_database_engine = CloudDatabase::ENGINE_POSTGRES;

    public string $new_database_size = 'small';





    public function mount(Server $server, Site $site): void
    {
        abort_unless($site->server_id === $server->id, 404);
        abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);
        Gate::authorize('view', $site);

        // A derived worker is a role of its parent app — it has no resources of
        // its own (it inherits the parent's). Don't expose a Resources surface.
        abort_if($site->isDerivedWorker(), 404);

        // Container sites have CloudDatabase pivots
        // and get the full attach/detach surface. VM sites are admitted too —
        // they show a read-only roll-up of their Supervisor + systemd workers
        // (their databases/services live on other panels). Serverless keeps its
        // dedicated Workers page, so it still bounces out.
        $this->isContainer = $site->usesContainerRuntime();
        abort_unless($this->isContainer || $site->runtimeTargetMode() === 'vm', 404);

        $this->server = $server;
        $this->site = $site;
    }

    /**
     * VM worker roll-up: every long-running process that keeps a queue /
     * Horizon / scheduler alive for this site — on the site's own box AND on any
     * worker server sharing its private network / worker pool. Read-only;
     * management lives on the Workers page. See {@see SiteWorkerCoverage}.
     *
     * @return \Illuminate\Support\Collection<int, array{name: string, type: string, command: string, source: string, server_id: string, server_name: string, off_box: bool, instances: int, active: bool}>
     */
    #[Computed]
    public function vmWorkers(): \Illuminate\Support\Collection
    {
        return SiteWorkerCoverage::workers($this->site);
    }

    /**
     * Worker SERVER pools attached to this site's workspace — the scalable
     * background fleet (distinct from {@see vmWorkers()}, which lists the
     * individual Supervisor processes). Surfaced here so operators see the
     * pool as a resource; scaling lives on the site's Worker-servers settings
     * section and the pool page. See {@see Site::attachedWorkerPools()}.
     *
     * @return \Illuminate\Support\Collection<int, WorkerPool>
     */
    #[Computed]
    public function attachedWorkerPools(): \Illuminate\Support\Collection
    {
        return $this->site->attachedWorkerPools();
    }

    public function openAttach(string $pane = 'attach'): void
    {
        Gate::authorize('update', $this->site);

        $this->resetForms();
        $this->modal = in_array($pane, ['attach', 'database-existing', 'database-new'], true)
            ? $pane
            : 'attach';
    }

    public function closeModal(): void
    {
        $this->resetForms();
        $this->modal = '';
    }

    /**
     * @return Collection<int, CloudDatabase>
     */
    #[Computed]
    public function attachedDatabases(): Collection
    {
        return CloudDatabase::query()
            ->whereHas('sites', fn ($q) => $q->where('sites.id', $this->site->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, CloudDatabase>
     */
    #[Computed]
    public function attachableDatabases(): Collection
    {
        $attachedIds = $this->attachedDatabases->modelKeys();

        return CloudDatabase::query()
            ->where('organization_id', $this->site->organization_id)
            ->whereNotIn('id', $attachedIds)
            ->whereIn('status', [CloudDatabase::STATUS_ACTIVE, CloudDatabase::STATUS_PROVISIONING])
            ->orderBy('name')
            ->get();
    }

    public function attachExistingDatabase(): void
    {
        Gate::authorize('update', $this->site);
        $this->validate(['attach_database_id' => 'required|string']);

        $database = CloudDatabase::query()->find($this->attach_database_id);
        if ($database === null || $database->organization_id !== $this->site->organization_id) {
            $this->toastError(__('That database is not available in this organization.'));

            return;
        }

        AttachCloudDatabaseJob::dispatch((string) $database->id, (string) $this->site->id);
        $this->toastSuccess(__('Attach queued — env vars and a redeploy land shortly.'));
        $this->closeModal();
    }

    public function createNewDatabase(): void
    {
        Gate::authorize('update', $this->site);
        $this->validate([
            'new_database_name' => 'required|string|min:3|max:60',
            'new_database_engine' => 'required|in:postgres,mysql,redis',
            'new_database_size' => 'required|in:small,medium,large',
        ]);

        try {
            $database = (new CreateCloudDatabase)->handle($this->site->organization, [
                'name' => $this->new_database_name,
                'engine' => $this->new_database_engine,
                'size' => $this->new_database_size,
                'region' => (string) ($this->site->container_region ?? ''),
            ]);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        // Pivot now; ProvisionCloudDatabaseJob's activation hook fans out
        // AttachCloudDatabaseJob to every pivoted site once the cluster is
        // online (same path the create-DB-alongside wizard uses).
        $database->sites()->syncWithoutDetaching([$this->site->id]);

        $this->toastSuccess(__('Provisioning — DB env vars + redeploy land automatically when the cluster is online (~5-10 min).'));
        $this->closeModal();
    }

    public function detachDatabase(string $databaseId): void
    {
        Gate::authorize('update', $this->site);

        $database = CloudDatabase::query()->find($databaseId);
        if ($database === null || $database->organization_id !== $this->site->organization_id) {
            return;
        }
        if (! $database->sites()->where('sites.id', $this->site->id)->exists()) {
            return;
        }

        AttachCloudDatabaseJob::dispatch((string) $database->id, (string) $this->site->id, detach: true);
        $this->toastSuccess(__('Detach queued — DB_* env vars will be removed and the site redeployed.'));
    }

    private function resetForms(): void
    {
        $this->attach_database_id = null;
        $this->new_database_name = '';
        $this->new_database_engine = CloudDatabase::ENGINE_POSTGRES;
        $this->new_database_size = 'small';
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.sites.resources');
    }
}
