<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\PushSiteEnvJob;
use App\Jobs\RecheckRequiredEnvJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Jobs\ViewServerEnvJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\GuardsBilledDeploys;
use App\Livewire\Concerns\ManagesSiteBindings;
use App\Livewire\Concerns\OptimizesPipeline;
use App\Livewire\Concerns\WatchesConsoleActionOutcomes;
use App\Livewire\Sites\Concerns\ManagesSiteDeployExecution;
use App\Livewire\Sites\Concerns\ManagesSiteDeploymentSchedules;
use App\Livewire\Sites\Concerns\ManagesSiteEnvironment;
use App\Livewire\Sites\Concerns\SurfacesDeploymentRemediation;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Deploy\DeploymentContractBuilder;
use App\Services\Deploy\DeploymentPreflightValidator;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Support\Sites\SiteSettingsViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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
    use GuardsBilledDeploys;
    use ManagesSiteBindings;
    use ManagesSiteDeployExecution;
    use ManagesSiteDeploymentSchedules;
    use ManagesSiteEnvironment;
    use OptimizesPipeline;
    use SurfacesDeploymentRemediation;
    use WatchesConsoleActionOutcomes;
    use WithPagination;

    public Server $server;

    public Site $site;

    /**
     * Modal inputs for the deploy-panel "Add variables" prompt (the gate that
     * blocked the last deploy), keyed by env KEY. Distinct from the
     * Environment tab's $missing_env_values (provided by ManagesSiteEnvironment)
     * because this one is seeded from the recorded deploy block, not the cache.
     *
     * @var array<string, string>
     */
    public array $blocked_env_values = [];

    /**
     * Selected site ids for the Sync tab's "deploy several sites together" action
     * (e.g. a main site + its worker). Pre-seeded with the repo/server peers the
     * first time the tab opens.
     *
     * @var array<int, string>
     */
    public array $syncSelectedSiteIds = [];

    public bool $syncSelectionSeeded = false;

    public const TAB_OVERVIEW = 'overview';

    public const TAB_REPOSITORY = 'repository';

    public const TAB_DEPLOY = 'deploy';

    public const TAB_SYNC = 'sync';

    public const TAB_ENVIRONMENT = 'environment';

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
        self::TAB_SYNC,
        self::TAB_ENVIRONMENT,
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

        // Rollout folded into Pipeline as a subtab (workspace-pipeline's own
        // Rollout subtab); old ?tab=rollout links resolve to the Pipeline tab.
        if ($this->tab === self::TAB_ROLLOUT) {
            $this->tab = self::TAB_PIPELINE;
        }

        // Repository is now its own standalone page (sites.repository) with the
        // site sidebar — it's no longer a tab in this hub. Old ?tab=repository
        // bookmarks / deep links resolve to the standalone page.
        if ($this->tab === self::TAB_REPOSITORY) {
            $this->redirect(route('sites.repository', ['server' => $server, 'site' => $site]), navigate: true);

            return;
        }

        // Environment is now its own first-class section (sites.environment) —
        // no longer a tab in this hub. Old ?tab=environment links resolve there.
        if ($this->tab === self::TAB_ENVIRONMENT) {
            $this->redirect(route('sites.environment', ['server' => $server, 'site' => $site]), navigate: true);

            return;
        }

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
        if ($tab === self::TAB_SYNC && ! $this->syncSelectionSeeded) {
            $this->syncSelectedSiteIds = $this->syncCandidates->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $this->syncSelectionSeeded = true;
        }
        if ($tab !== self::TAB_SETTINGS) {
            $this->settingsSection = '';
        }
    }

    /**
     * Sites the user can pick to deploy alongside this one — the repo peers
     * (a main site + its worker share a git repository), or same-server siblings
     * when no repo is set. Always includes this site.
     */
    public function getSyncCandidatesProperty(): Collection
    {
        $repo = trim((string) $this->site->git_repository_url);

        return Site::query()
            ->where('organization_id', $this->site->organization_id)
            ->where(function ($w) use ($repo): void {
                $w->where('id', $this->site->id);
                if ($repo !== '') {
                    $w->orWhere('git_repository_url', $repo);
                } else {
                    $w->orWhere('server_id', $this->site->server_id);
                }
            })
            // Full server (not server:id,name) — SitePolicy/ServerPolicy::update
            // reads user_id/organization_id/workspace_id to authorize the deploy.
            // A partial column load nulls those and skips every site as "no
            // permission" (the deployMultiple footgun).
            ->with('server')
            ->orderBy('name')
            ->get();
    }

    /**
     * Deploy every selected site together (parallel fan-out). Replaces the old
     * persistent "sync group" — an ad-hoc multi-select per deploy. Each site is
     * authorized + dispatched exactly like the single Deploy button.
     */
    public function deployMultiple(): void
    {
        $ids = array_values(array_unique(array_map('strval', $this->syncSelectedSiteIds)));
        if ($ids === []) {
            $this->toastError(__('Pick at least one site to deploy.'));

            return;
        }

        // All sync candidates share this org (scoped by organization_id), so a
        // single pause check gates the whole fan-out before any job dispatch.
        if ($this->blockedByDeployPause($this->site)) {
            return;
        }

        $candidates = $this->syncCandidates->keyBy(fn ($s): string => (string) $s->id);
        $queued = 0;
        $skipped = 0;
        foreach ($ids as $id) {
            $site = $candidates->get($id);
            if ($site === null || ! Gate::allows('update', $site)) {
                $skipped++;

                continue;
            }
            Cache::put('site-deploy-active:'.$site->id, [
                'started_at' => now()->toIso8601String(),
                'deployment_id' => null,
            ], 600);
            RunSiteDeploymentJob::dispatch($site, SiteDeployment::TRIGGER_MANUAL);
            $queued++;
        }

        $msg = trans_choice('{1}:count deployment queued.|[2,*]:count deployments queued.', $queued, ['count' => $queued]);
        if ($skipped > 0) {
            $msg .= ' '.__(':n skipped (no permission).', ['n' => $skipped]);
        }
        $this->toastSuccess($msg);
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

        if ($this->blockedByDeployPause($this->site)) {
            return;
        }

        RunSiteDeploymentJob::dispatch($this->site, SiteDeployment::TRIGGER_MANUAL);
        $this->toastSuccess(__('Deployment queued.'));
    }

    /**
     * The env keys the last deploy was blocked on (recorded by
     * {@see RunSiteDeploymentJob::assertRequiredEnvPresent()}). Drives the
     * "fill in your env" prompt on the Deploy panel.
     *
     * @return list<array{key: string, example: ?string}>
     */
    public function deployBlockedEnvKeys(): array
    {
        $blocked = $this->site->meta['deploy_blocked_env']['keys'] ?? null;
        if (! is_array($blocked)) {
            return [];
        }

        $out = [];
        foreach ($blocked as $entry) {
            $key = is_array($entry) ? (string) ($entry['key'] ?? '') : '';
            if ($key === '') {
                continue;
            }
            $out[] = ['key' => $key, 'example' => isset($entry['example']) ? (string) $entry['example'] : null];
        }

        return $out;
    }

    /**
     * Open the deploy-panel fill-in modal (the gate prompt), seeding each
     * input with the .env.example sample value so the operator can confirm or
     * edit.
     */
    public function openBlockedEnvModal(): void
    {
        Gate::authorize('update', $this->site);

        $seed = [];
        foreach ($this->deployBlockedEnvKeys() as $entry) {
            $seed[$entry['key']] = (string) ($entry['example'] ?? '');
        }
        $this->blocked_env_values = $seed;

        $this->dispatch('open-modal', 'deploy-missing-env-modal');
    }

    /**
     * Write the filled-in variables into the env cache and push them to the
     * server, then clear the block marker so the next deploy can proceed.
     * Blank inputs are skipped. Mirrors the Environment tab's writer; the push
     * here is a plain queued job (no console banner on this component).
     */
    public function addBlockedEnvVars(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        Gate::authorize('update', $this->site);

        $additions = [];
        foreach ($this->blocked_env_values as $key => $value) {
            $key = trim((string) $key);
            $value = (string) $value;
            if ($key === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) || trim($value) === '') {
                continue;
            }
            $additions[$key] = $value;
        }

        if ($additions === []) {
            $this->toastError(__('Enter a value for at least one variable.'));

            return;
        }

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $map = array_merge($parsed['variables'], $additions);

        $this->site->forceFill([
            'env_file_content' => $writer->render($map, $parsed['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();

        // Drop the keys we just supplied from the recorded block so the banner
        // updates immediately; a re-deploy re-validates against the server.
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        if (isset($meta['deploy_blocked_env']['keys']) && is_array($meta['deploy_blocked_env']['keys'])) {
            $remaining = array_values(array_filter(
                $meta['deploy_blocked_env']['keys'],
                static fn ($e): bool => is_array($e) && ! array_key_exists((string) ($e['key'] ?? ''), $additions),
            ));
            if ($remaining === []) {
                unset($meta['deploy_blocked_env']);
            } else {
                $meta['deploy_blocked_env']['keys'] = $remaining;
            }
            $this->site->forceFill(['meta' => $meta])->save();
        }

        $this->blocked_env_values = [];

        if ($this->server->hostCapabilities()->supportsEnvPushToHost()) {
            PushSiteEnvJob::dispatch($this->site->id, (string) (auth()->id() ?? ''));
        }

        $this->dispatch('close-modal', 'deploy-missing-env-modal');
        $this->toastSuccess(__(':count variable(s) added and pushed. Re-deploy to continue.', ['count' => count($additions)]));
    }

    /**
     * Fill the APP_KEY input in the deploy-panel "Add variables" modal with a
     * fresh Laravel key, so the operator can generate one without SSHing in.
     */
    public function generateBlockedAppKey(): void
    {
        Gate::authorize('update', $this->site);
        $this->blocked_env_values['APP_KEY'] = $this->freshAppKey();
    }

    /**
     * Skip the required-env gate for this site and deploy now. Persists the
     * opt-out (meta.skip_env_gate) so future deploys also skip it, clears the
     * recorded block, and dispatches a deploy — it's then on the operator if
     * the app errors from missing vars.
     */
    public function deployIgnoringEnvGate(): void
    {
        Gate::authorize('update', $this->site);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['skip_env_gate'] = true;
        unset($meta['deploy_blocked_env']);
        $this->site->forceFill(['meta' => $meta])->save();

        RunSiteDeploymentJob::dispatch($this->site->fresh(), SiteDeployment::TRIGGER_MANUAL);
        $this->toastSuccess(__('Required-env check turned off for this site. Deploy queued — it will run even with missing variables.'));
    }

    /**
     * Re-evaluate the required-env gate against the live server .env right now,
     * without deploying. Clears the "Deploy needs N variables" banner if the
     * vars are actually set — the non-destructive fix for a stale block. The
     * .env read is over SSH, so it runs in a queued job (never inline).
     */
    public function recheckBlockedEnv(): void
    {
        Gate::authorize('update', $this->site);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host runtime does not expose a server .env file to re-check.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('env_recheck');
        RecheckRequiredEnvJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction($run, __('Re-checked environment variables.'), __('Re-check did not finish.'));
        $this->toastConsoleActionQueued();
    }

    /**
     * Read-only view of the live server .env — a key inventory streamed to the
     * console drawer (values masked). Non-destructive: unlike Sync, it never
     * overwrites dply's cache, so an operator can just see which vars are set
     * on the box. SSH read runs in a queued job.
     */
    public function viewServerEnv(): void
    {
        Gate::authorize('update', $this->site);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host runtime does not expose a server .env file to view.'));

            return;
        }

        $run = $this->seedQueuedConsoleAction('env_view');
        ViewServerEnvJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
            (string) $run->id,
        );

        $this->dispatch('dply-console-action-focus');
        $this->watchConsoleAction($run, __('Read the server .env.'), __('Could not read the server .env.'));
        $this->toastConsoleActionQueued();
    }

    /**
     * Confirm-modal wrapper for deploy-and-skip in one shot.
     */
    public function confirmDeployIgnoringEnvGate(): void
    {
        Gate::authorize('update', $this->site);
        $this->openConfirmActionModal(
            method: 'deployIgnoringEnvGate',
            title: __('Deploy without required variables?'),
            message: __('The app may error at runtime until these are set. The required-env check will be turned off for this site and a deploy will start now.'),
            confirmLabel: __('Deploy anyway'),
        );
    }

    public function render(): View
    {
        // The paginated list + trigger facets feed ONLY the History panel; the
        // distinct/paginate queries are wasted on every other tab. Run them only
        // when History is active so switching to Environment / Webhook / etc.
        // doesn't pay for the deploy history each time.
        $isHistory = $this->tab === self::TAB_HISTORY;

        $deployments = null;
        $triggers = [];
        if ($isHistory) {
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
        }

        $runtimeMode = $this->site->runtimeTargetMode();
        $isVmDeployHub = $runtimeMode === 'vm'
            && ! $this->site->usesFunctionsRuntime()
            && ! $this->site->usesEdgeRuntime();

        $atomicReleases = $isVmDeployHub && $this->site->deploy_strategy === 'atomic';
        $latestDeployment = null;

        // Eager-load only the relation the active panel actually reads: the
        // releases list is Releases-only; the recent-deployments window (and the
        // $latestDeployment it yields) is the Deploy panel only. The fallback
        // tab also renders the Deploy panel, hence the in-array check. Other
        // tabs (Environment, Webhook, Hooks, Pipeline…) load neither.
        if ($isVmDeployHub) {
            $load = [];
            if ($this->tab === self::TAB_RELEASES) {
                $load['releases'] = fn ($q) => $q->orderByDesc('id')->limit(30);
            }

            $deployPanelTabs = [self::TAB_OVERVIEW, self::TAB_REPOSITORY, self::TAB_ENVIRONMENT, self::TAB_COMMITS,
                self::TAB_FILES, self::TAB_BRANCHES, self::TAB_PIPELINE, self::TAB_ROLLOUT, self::TAB_RELEASES,
                self::TAB_HISTORY, self::TAB_WEBHOOK, self::TAB_HOOKS, self::TAB_SETTINGS];
            $needsLatest = ! in_array($this->tab, $deployPanelTabs, true); // Deploy tab + unknown fallback

            if ($needsLatest) {
                $load['deployments'] = fn ($q) => $q->orderByDesc('started_at')->limit(5);
            }

            if ($load !== []) {
                $this->site->load($load);
            }
            if ($needsLatest) {
                $latestDeployment = $this->site->deployments->first();
            }
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
            // Rollout folded into Pipeline as a subtab.
            self::TAB_ROLLOUT => false,
            self::TAB_RELEASES => $atomicReleases,
            self::TAB_HISTORY => true,
            // Settings consolidated up into Webhook + Hooks tabs.
            self::TAB_SETTINGS => false,
        ];

        $overviewMetrics = $this->tab === self::TAB_OVERVIEW
            ? $this->computeOverviewMetrics()
            : null;

        // The deployment contract + preflight are display data for deploy-config
        // surfaces. The builder eager-loads relations and runs the secret /
        // resource-binding resolvers, and the validator adds more on top — real
        // per-render cost. Nothing on this page's panels or chrome actually reads
        // the keys they produce, so build them only for the deploy-config tabs
        // (and the unknown-tab fallback, which renders the Deploy panel) and skip
        // the work entirely on History / Webhook / Hooks / Pipeline / Releases /
        // Overview switches.
        $needsContract = in_array($this->tab, [self::TAB_DEPLOY, self::TAB_ENVIRONMENT], true)
            || ! in_array($this->tab, self::TABS, true);
        $deploymentContract = $needsContract ? app(DeploymentContractBuilder::class)->build($this->site) : null;
        $deploymentPreflight = $needsContract ? app(DeploymentPreflightValidator::class)->validate($this->site) : [];

        return view('livewire.sites.deployments-list', array_merge(
            SiteSettingsViewData::for(
                $this->server,
                $this->site,
                'deploy',
                $deploymentContract,
                $deploymentPreflight,
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

    public function activeConsoleRun(): ?ConsoleAction
    {
        if ($this->watchedConsoleRunId === null) {
            return null;
        }

        $run = ConsoleAction::query()->find($this->watchedConsoleRunId);

        return ($run !== null && ! $run->isDismissed()) ? $run : null;
    }
}
