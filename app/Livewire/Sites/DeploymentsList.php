<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Jobs\PushSiteEnvJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\WatchesConsoleActionOutcomes;
use App\Livewire\Sites\Concerns\ManagesSiteDeployExecution;
use App\Livewire\Sites\Concerns\ManagesSiteEnvironment;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Services\Deploy\DeploymentContractBuilder;
use App\Services\Deploy\DeploymentPreflightValidator;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
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
    use ManagesSiteEnvironment;
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

    public const TAB_OVERVIEW = 'overview';

    public const TAB_REPOSITORY = 'repository';

    public const TAB_DEPLOY = 'deploy';

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
     * Ignore missing required variables for this site WITHOUT deploying — just
     * turns off the gate and clears the recorded block so the banners go away.
     * The operator can re-enable later. Use deployIgnoringEnvGate() instead to
     * skip and ship in one step.
     */
    public function ignoreMissingEnv(): void
    {
        Gate::authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['skip_env_gate'] = true;
        unset($meta['deploy_blocked_env']);
        $this->site->forceFill(['meta' => $meta])->save();
        $this->toastSuccess(__('Ignoring missing required variables for this site — deploys won\'t be blocked by them.'));
    }

    /**
     * Re-enable the required-env gate (undo deployIgnoringEnvGate / ignoreMissingEnv).
     */
    public function enableEnvGate(): void
    {
        Gate::authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        unset($meta['skip_env_gate']);
        $this->site->forceFill(['meta' => $meta])->save();
        $this->toastSuccess(__('Required-env check re-enabled. Deploys stop if required variables are missing.'));
    }

    /** Whether the required-env gate is currently disabled for this site. */
    public function envGateSkipped(): bool
    {
        return ($this->site->meta['skip_env_gate'] ?? false) === true;
    }

    /**
     * Confirm-modal wrappers (so these use the app's confirm modal rather than
     * a native browser alert). Each opens {@see ConfirmsActionWithModal} which
     * calls the real method on confirm.
     */
    public function confirmIgnoreMissingEnv(): void
    {
        Gate::authorize('update', $this->site);
        $this->openConfirmActionModal(
            method: 'ignoreMissingEnv',
            title: __('Ignore missing variables?'),
            message: __('Stop blocking and warning on the missing required variables for this site. Deploys will proceed even if they are unset — it\'s on you if the app errors. You can re-enable this later.'),
            confirmLabel: __('Ignore them'),
        );
    }

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

    /** Confirm-modal wrapper for per-variable ignore (modal, not a browser alert). */
    public function confirmIgnoreEnvKey(string $key): void
    {
        Gate::authorize('update', $this->site);
        $key = trim($key);
        if ($key === '') {
            return;
        }
        $this->openConfirmActionModal(
            method: 'ignoreEnvKey',
            arguments: [$key],
            title: __('Ignore :key?', ['key' => $key]),
            message: __('Mark :key as intentionally unset for this site. It won\'t count as a missing required variable or block deploys. You can un-ignore it later.', ['key' => $key]),
            confirmLabel: __('Ignore'),
        );
    }

    /** Per-variable ignore: mark one required key as intentionally unset. */
    public function ignoreEnvKey(string $key): void
    {
        Gate::authorize('update', $this->site);
        $key = trim($key);
        if ($key === '') {
            return;
        }
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $ignored = array_values(array_unique([...((array) ($meta['ignored_env_keys'] ?? [])), $key]));
        $meta['ignored_env_keys'] = $ignored;
        // Drop it from the recorded deploy block so the banner updates.
        if (isset($meta['deploy_blocked_env']['keys']) && is_array($meta['deploy_blocked_env']['keys'])) {
            $meta['deploy_blocked_env']['keys'] = array_values(array_filter(
                $meta['deploy_blocked_env']['keys'],
                static fn ($e): bool => is_array($e) && (string) ($e['key'] ?? '') !== $key,
            ));
            if ($meta['deploy_blocked_env']['keys'] === []) {
                unset($meta['deploy_blocked_env']);
            }
        }
        $this->site->forceFill(['meta' => $meta])->save();
        $this->toastSuccess(__(':key will be ignored — deploys won\'t require it.', ['key' => $key]));
    }

    /** Undo a per-variable ignore. */
    public function unignoreEnvKey(string $key): void
    {
        Gate::authorize('update', $this->site);
        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['ignored_env_keys'] = array_values(array_filter(
            (array) ($meta['ignored_env_keys'] ?? []),
            static fn ($k): bool => (string) $k !== trim($key),
        ));
        $this->site->forceFill(['meta' => $meta])->save();
        $this->toastSuccess(__(':key is no longer ignored.', ['key' => $key]));
    }

    /**
     * Keys the operator has individually ignored.
     *
     * @return list<string>
     */
    public function ignoredEnvKeys(): array
    {
        return array_values(array_map('strval', (array) ($this->site->meta['ignored_env_keys'] ?? [])));
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
