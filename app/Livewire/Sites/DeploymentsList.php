<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Sites\Concerns\ManagesSiteDeployExecution;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Deploy\DeploymentContractBuilder;
use App\Services\Deploy\DeploymentPreflightValidator;
use App\Support\Sites\SiteSettingsViewData;
use App\Support\Sites\SiteShowViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Index of every deployment for a site, with status + trigger
 * filtering. Pairs with the deployment-detail page (one row →
 * detail) so operators can browse historic deploys without
 * scrolling through the recent-deployments collapsibles.
 */
class DeploymentsList extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesSiteDeployExecution;
    use WithPagination;

    public Server $server;

    public Site $site;

    #[Url(as: 'status', except: '')]
    public string $statusFilter = '';

    #[Url(as: 'trigger', except: '')]
    public string $triggerFilter = '';

    /** @var array<int, string> */
    public const ALLOWED_STATUSES = [
        SiteDeployment::STATUS_RUNNING,
        SiteDeployment::STATUS_SUCCESS,
        SiteDeployment::STATUS_FAILED,
        SiteDeployment::STATUS_SKIPPED,
    ];

    public function mount(Server $server, Site $site): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }
        if ($server->organization_id !== auth()->user()?->currentOrganization()?->id) {
            abort(404);
        }

        $this->server = $server;
        $this->site = $site;
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTriggerFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->statusFilter = '';
        $this->triggerFilter = '';
        $this->resetPage();
    }

    /**
     * Trigger a fresh deploy. Used by non-serverless runtimes — a serverless
     * function redeploys through the embedded journey panel instead, which
     * also watches the deploy run.
     */
    public function redeploy(): void
    {
        Gate::authorize('update', $this->site);

        RunSiteDeploymentJob::dispatch($this->site, SiteDeployment::TRIGGER_MANUAL);
        $this->toastSuccess(__('Deployment queued.'));
    }

    public function render(): View
    {
        $query = SiteDeployment::query()
            ->where('site_id', $this->site->id)
            ->orderByDesc('started_at');

        if (in_array($this->statusFilter, self::ALLOWED_STATUSES, true)) {
            $query->where('status', $this->statusFilter);
        }
        if ($this->triggerFilter !== '') {
            $query->where('trigger', $this->triggerFilter);
        }

        $deployments = $query->paginate(25);

        $triggers = SiteDeployment::query()
            ->where('site_id', $this->site->id)
            ->whereNotNull('trigger')
            ->distinct()
            ->orderBy('trigger')
            ->pluck('trigger')
            ->all();

        $runtimeMode = $this->site->runtimeTargetMode();
        $isVmDeployHub = $runtimeMode === 'vm'
            && ! $this->site->usesFunctionsRuntime()
            && ! $this->site->usesEdgeRuntime();

        $deploymentConsoles = collect();
        $atomicReleases = false;

        if ($isVmDeployHub) {
            $this->site->load([
                'releases' => fn ($q) => $q->orderByDesc('id')->limit(30),
                'deployments' => fn ($q) => $q->orderByDesc('started_at')->limit(5),
            ]);
            $atomicReleases = $this->site->deploy_strategy === 'atomic';
            if ($this->site->relationLoaded('deployments')) {
                $deploymentConsoles = SiteShowViewData::deploymentConsolesFor($this->site->deployments);
            }
        }

        return view('livewire.sites.deployments-list', array_merge(
            SiteSettingsViewData::for(
                $this->server,
                $this->site,
                'deploy',
                app(DeploymentContractBuilder::class)->build($this->site),
                app(DeploymentPreflightValidator::class)->validate($this->site),
                auth()->user(),
            ),
            [
                'deployments' => $deployments,
                'triggers' => $triggers,
                'statuses' => self::ALLOWED_STATUSES,
                'isVmDeployHub' => $isVmDeployHub,
                'deploymentConsoles' => $deploymentConsoles,
                'atomicReleases' => $atomicReleases,
                'section' => 'deploy',
                'routingTab' => 'domains',
                'laravel_tab' => 'commands',
            ],
        ))->layout('layouts.app');
    }
}
