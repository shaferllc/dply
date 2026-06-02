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

    public const TAB_OVERVIEW = 'overview';

    public const TAB_REPOSITORY = 'repository';

    public const TAB_DEPLOY = 'deploy';

    public const TAB_COMMITS = 'commits';

    public const TAB_FILES = 'files';

    public const TAB_BRANCHES = 'branches';

    public const TAB_PIPELINE = 'pipeline';

    public const TAB_ROLLOUT = 'rollout';

    public const TAB_RELEASES = 'releases';

    public const TAB_HISTORY = 'history';

    public const TAB_SETTINGS = 'settings';

    public const TAB_WEBHOOK = 'webhook';

    public const TAB_HOOKS = 'hooks';

    public const TABS = [
        self::TAB_OVERVIEW,
        self::TAB_REPOSITORY,
        self::TAB_DEPLOY,
        self::TAB_WEBHOOK,
        self::TAB_HOOKS,
        // TAB_COMMITS / TAB_FILES / TAB_BRANCHES intentionally absent — they
        // live as sub-tabs under Repository now. Any ?tab=commits / =files
        // / =branches URL resets to TAB_DEPLOY via the in_array() guard in
        // mount(); the constants stay defined so other code references keep
        // compiling.
        // TAB_SETTINGS intentionally absent — Webhook + Hooks moved up to
        // top-level tabs; the Settings tab and its panel are no longer
        // surfaced. Constant kept defined for compatibility with code that
        // references it (e.g. mount/setTab fall-through guards).
        self::TAB_PIPELINE,
        self::TAB_ROLLOUT,
        self::TAB_RELEASES,
        self::TAB_HISTORY,
    ];

    public const SETTINGS_SECTIONS = ['pipeline', 'repository', 'hooks'];

    #[Url(as: 'tab', except: self::TAB_DEPLOY)]
    public string $tab = self::TAB_DEPLOY;

    /**
     * Sub-section anchor within the Settings tab (e.g. ?section=pipeline). Stored under a
     * non-`$section` name to avoid shadowing the sidebar's `$section` view variable, which
     * the Livewire renderer would otherwise inject from this property and break sidebar
     * highlighting for the Deployments item. The URL key stays `section` via the `as:`.
     */
    #[Url(as: 'section', except: '')]
    public string $settingsSection = '';

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

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = self::TAB_DEPLOY;
        }
        if ($this->tab === self::TAB_RELEASES && $site->deploy_strategy !== 'atomic') {
            $this->tab = self::TAB_DEPLOY;
        }
        if ($this->tab !== self::TAB_SETTINGS) {
            $this->settingsSection = '';
        } elseif (! in_array($this->settingsSection, self::SETTINGS_SECTIONS, true)) {
            $this->settingsSection = '';
        }
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, self::TABS, true)) {
            return;
        }
        if ($tab === self::TAB_RELEASES && $this->site->deploy_strategy !== 'atomic') {
            return;
        }
        $this->tab = $tab;
        if ($tab !== self::TAB_SETTINGS) {
            $this->settingsSection = '';
        }
    }

    public function setSection(string $section): void
    {
        if (! in_array($section, self::SETTINGS_SECTIONS, true)) {
            return;
        }
        $this->tab = self::TAB_SETTINGS;
        $this->settingsSection = $section;
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

        $atomicReleases = $isVmDeployHub && $this->site->deploy_strategy === 'atomic';
        $latestDeployment = null;

        if ($isVmDeployHub) {
            $this->site->load([
                'releases' => fn ($q) => $q->orderByDesc('id')->limit(30),
                'deployments' => fn ($q) => $q->orderByDesc('started_at')->limit(5),
            ]);
            $latestDeployment = $this->site->deployments->first();
        }

        $tabsVisible = [
            self::TAB_OVERVIEW => true,
            self::TAB_REPOSITORY => true,
            self::TAB_DEPLOY => true,
            self::TAB_WEBHOOK => true,
            // Hooks editor only applies to DigitalOcean Functions hosts.
            self::TAB_HOOKS => (bool) $this->site->server?->isDigitalOceanFunctionsHost(),
            // Commits / Files / Branches live under Repository now.
            self::TAB_COMMITS => false,
            self::TAB_FILES => false,
            self::TAB_BRANCHES => false,
            self::TAB_PIPELINE => $isVmDeployHub,
            self::TAB_ROLLOUT => $isVmDeployHub,
            self::TAB_RELEASES => $atomicReleases,
            self::TAB_HISTORY => true,
            // Settings consolidated up into Webhook + Hooks tabs.
            self::TAB_SETTINGS => false,
        ];

        $overviewMetrics = $this->tab === self::TAB_OVERVIEW
            ? $this->computeOverviewMetrics()
            : null;

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
                'atomicReleases' => $atomicReleases,
                'latestDeployment' => $latestDeployment,
                'tabsVisible' => $tabsVisible,
                'overviewMetrics' => $overviewMetrics,
                'section' => 'deploy',
                'routingTab' => 'domains',
                'laravel_tab' => 'commands',
            ],
        ))->layout('layouts.app');
    }

    /**
     * Summarise the last 30 days of deploys for the Overview panel.
     *
     * @return array{
     *   window_days:int,
     *   total:int,
     *   success_count:int,
     *   failed_count:int,
     *   success_rate:?float,
     *   median_duration_ms:?int,
     *   daily:array<int, array{date:string, total:int, success:int, failed:int}>,
     *   top_failure_phase:?string,
     * }
     */
    private function computeOverviewMetrics(): array
    {
        $windowDays = 30;
        $since = now()->subDays($windowDays);

        $rows = SiteDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get(['status', 'started_at', 'finished_at', 'phase_results', 'created_at']);

        $total = $rows->count();
        $successCount = $rows->where('status', SiteDeployment::STATUS_SUCCESS)->count();
        $failedCount = $rows->where('status', SiteDeployment::STATUS_FAILED)->count();
        $rated = $successCount + $failedCount;
        $successRate = $rated > 0 ? round(($successCount / $rated) * 100, 1) : null;

        $durations = $rows
            ->map(fn (SiteDeployment $d): int => $d->phaseTotalDurationMs())
            ->filter(fn (int $ms): bool => $ms > 0)
            ->values();
        $medianDurationMs = null;
        if ($durations->isNotEmpty()) {
            $sorted = $durations->sort()->values();
            $count = $sorted->count();
            $medianDurationMs = (int) ($count % 2
                ? $sorted[(int) (($count - 1) / 2)]
                : (int) (($sorted[$count / 2 - 1] + $sorted[$count / 2]) / 2));
        }

        $daily = [];
        for ($i = $windowDays - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $daily[$day] = ['date' => $day, 'total' => 0, 'success' => 0, 'failed' => 0];
        }
        foreach ($rows as $row) {
            $day = $row->created_at?->toDateString();
            if ($day === null || ! isset($daily[$day])) {
                continue;
            }
            $daily[$day]['total']++;
            if ($row->status === SiteDeployment::STATUS_SUCCESS) {
                $daily[$day]['success']++;
            } elseif ($row->status === SiteDeployment::STATUS_FAILED) {
                $daily[$day]['failed']++;
            }
        }

        $failurePhases = [];
        foreach ($rows->where('status', SiteDeployment::STATUS_FAILED) as $row) {
            foreach (['build', 'swap', 'release', 'restart'] as $phase) {
                if ($row->hasPhase($phase) && ! $row->phaseOk($phase)) {
                    $failurePhases[$phase] = ($failurePhases[$phase] ?? 0) + 1;
                    break;
                }
            }
        }
        $topFailurePhase = null;
        if ($failurePhases !== []) {
            arsort($failurePhases);
            $topFailurePhase = (string) array_key_first($failurePhases);
        }

        return [
            'window_days' => $windowDays,
            'total' => $total,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'success_rate' => $successRate,
            'median_duration_ms' => $medianDurationMs,
            'daily' => array_values($daily),
            'top_failure_phase' => $topFailurePhase,
        ];
    }
}
