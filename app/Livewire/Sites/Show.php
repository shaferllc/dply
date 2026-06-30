<?php

namespace App\Livewire\Sites;

use App\Enums\DeploymentMethod;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\ManagesEdgeRedeploy;
use App\Livewire\Concerns\ManagesServerlessRuntime;
use App\Livewire\Concerns\MountsSiteWorkspace;
use App\Livewire\Concerns\OptimizesPipeline;
use App\Livewire\Concerns\RefreshesLinkedSourceControlAccounts;
use App\Livewire\Concerns\WatchesConsoleActionOutcomes;
use App\Livewire\Sites\Concerns\HandlesSiteRemovalFlow;
use App\Livewire\Sites\Concerns\InteractsWithScaffoldJourney;
use App\Livewire\Sites\Concerns\ManagesSiteDeployExecution;
use App\Livewire\Sites\Concerns\ManagesSiteDeployHooks;
use App\Livewire\Sites\Concerns\ManagesSiteDeploymentSettings;
use App\Livewire\Sites\Concerns\ManagesSiteDeploySteps;
use App\Livewire\Sites\Concerns\ManagesSiteDomainsRouting;
use App\Livewire\Sites\Concerns\ManagesSiteEnvVars;
use App\Livewire\Sites\Concerns\ManagesSiteLifecycleActions;
use App\Livewire\Sites\Concerns\ManagesSitePhpFpm;
use App\Livewire\Sites\Concerns\ManagesSiteProvisioning;
use App\Livewire\Sites\Concerns\ManagesSiteRedirects;
use App\Livewire\Sites\Concerns\ManagesSiteRepositoryConfig;
use App\Livewire\Sites\Concerns\ManagesSiteServerErrors;
use App\Livewire\Sites\Concerns\ManagesSiteWebhookSecurity;
use App\Models\ConsoleAction;
use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Deploy\Services\DeploymentContractBuilder;
use App\Modules\Deploy\Services\DeploymentPreflightValidator;
use App\Services\Servers\ServerPhpManager;
use App\Modules\SourceControl\Services\SourceControlRepositoryBrowser;
use App\Support\Sites\SiteShowViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use HandlesSiteRemovalFlow;
    use InteractsWithScaffoldJourney;
    use ManagesEdgeRedeploy;
    use ManagesServerlessRuntime;
    use ManagesSiteDeployExecution;
    use ManagesSiteDeployHooks;
    use ManagesSiteDeploymentSettings;
    use ManagesSiteDeploySteps;
    use ManagesSiteDomainsRouting;
    use ManagesSiteEnvVars;
    use ManagesSiteLifecycleActions;
    use ManagesSitePhpFpm;
    use ManagesSiteProvisioning;
    use ManagesSiteRedirects;
    use ManagesSiteRepositoryConfig;
    use ManagesSiteServerErrors;
    use ManagesSiteWebhookSecurity;
    use MountsSiteWorkspace;
    use OptimizesPipeline;
    use RefreshesLinkedSourceControlAccounts;
    use WatchesConsoleActionOutcomes;

    public Server $server;

    public Site $site;

    /** Active tab on the post-provisioning dashboard (overview|deploys|runtime|logs|ssl). */
    public string $dashboard_tab = 'overview';

    /** Recorded in site meta for Rails apps (e.g. production, staging). */
    public string $rails_env = 'production';

    public string $octane_port = '';

    /** Laravel Octane application server: swoole, roadrunner, or frankenphp (stored in meta.laravel_octane). */
    public string $octane_server = 'swoole';

    /** Local port for Laravel Reverb (meta.laravel_reverb.port); used with Supervisor / proxies. */
    public string $laravel_reverb_port = '';

    /** WebSocket path for Laravel Echo + Reverb (meta.laravel_reverb.ws_path). */
    public string $laravel_reverb_ws_path = '/app';

    /** Dashboard paths and notes (meta.laravel_horizon, meta.laravel_pulse). */
    public string $laravel_horizon_path = '/horizon';

    public string $laravel_horizon_notes = '';

    public string $laravel_pulse_path = '/pulse';

    public string $laravel_pulse_notes = '';

    public bool $laravel_scheduler = false;

    /** Localhost port for reverse-proxy runtimes (Node, Rails, Puma, containers, etc.). */
    public string $runtime_app_port = '';

    public function mount(Server $server, Site $site): void
    {
        $this->mountSiteWorkspace($server, $site);
        $this->syncFormFromSite();

        if ($this->site->usesEdgeRuntime()) {
            return;
        }

        $this->loadFunctionsSourceControlState(app(SourceControlRepositoryBrowser::class));
        $this->refreshFunctionsDetection();
    }

    protected function syncFormFromSite(): void
    {
        if ($this->site->usesEdgeRuntime()) {
            return;
        }

        $functionsConfig = $this->site->functionsConfig();
        $this->git_repository_url = (string) ($this->site->git_repository_url ?? '');
        $this->git_branch = (string) ($this->site->git_branch ?: 'main');
        $this->functions_repo_source = (string) ($functionsConfig['repo_source'] ?? 'manual');
        $this->functions_source_control_account_id = (string) ($functionsConfig['source_control_account_id'] ?? '');
        $this->functions_repository_selection = '';
        $this->functions_repository_subdirectory = (string) ($functionsConfig['repository_subdirectory'] ?? '');
        $this->functions_runtime = (string) ($functionsConfig['runtime'] ?? '');
        $this->functions_entrypoint = (string) ($functionsConfig['entrypoint'] ?? '');
        $this->functions_build_command = (string) ($functionsConfig['build_command'] ?? '');
        $this->functions_artifact_output_path = (string) ($functionsConfig['artifact_output_path'] ?? '');
        $this->syncServerlessRuntimeFromSite();
        $this->functionsDetection = is_array($functionsConfig['detected_runtime'] ?? null)
            ? $functionsConfig['detected_runtime']
            : [];
        $this->post_deploy_command = (string) ($this->site->post_deploy_command ?? '');
        $this->env_file_path_override = (string) ($this->site->env_file_path ?? '');
        $this->deploy_strategy = (string) ($this->site->deploy_strategy ?? 'simple');
        $this->deploy_method = DeploymentMethod::forSite($this->site)->value;
        $this->zero_downtime_enabled = $this->deploy_strategy === 'atomic';
        $dm = is_array($this->site->meta) ? $this->site->meta : [];
        $this->ephemeral_deploy_credentials_enabled = (bool) data_get($dm, 'deploy.ephemeral_credentials', false);
        $this->deploy_health_enabled = (bool) ($dm['deploy_health_enabled'] ?? false);
        $this->deploy_health_auto_rollback = (bool) ($dm['deploy_health_auto_rollback'] ?? false);
        $this->deploy_health_path = (string) ($dm['deploy_health_path'] ?? '/up');
        $this->deploy_health_expect_status = (int) ($dm['deploy_health_expect_status'] ?? 200);
        $this->deploy_health_attempts = (int) ($dm['deploy_health_attempts'] ?? 5);
        $this->deploy_health_delay_ms = (int) ($dm['deploy_health_delay_ms'] ?? 500);
        $scheme = strtolower((string) ($dm['deploy_health_scheme'] ?? 'http'));
        $this->deploy_health_scheme = in_array($scheme, ['http', 'https'], true) ? $scheme : 'http';
        $this->deploy_health_host = trim((string) ($dm['deploy_health_host'] ?? '127.0.0.1'));
        if ($this->deploy_health_host === '') {
            $this->deploy_health_host = '127.0.0.1';
        }
        $p = $dm['deploy_health_port'] ?? null;
        $this->deploy_health_port = $p !== null && $p !== '' && is_numeric($p)
            ? (string) max(1, min(65535, (int) $p))
            : '';
        $railsRuntime = is_array($dm['rails_runtime'] ?? null) ? $dm['rails_runtime'] : [];
        $this->rails_env = (string) ($railsRuntime['env'] ?? 'production');
        $this->releases_to_keep = (int) ($this->site->releases_to_keep ?? 5);
        $this->nginx_extra_raw = (string) ($this->site->nginx_extra_raw ?? '');
        $this->engine_http_cache_enabled = (bool) ($this->site->engine_http_cache_enabled ?? false);
        $this->octane_port = $this->site->octane_port !== null ? (string) $this->site->octane_port : '';
        $this->octane_server = $this->site->octaneServer();
        $this->laravel_reverb_port = (string) $this->site->reverbLocalPort();
        $this->laravel_reverb_ws_path = $this->site->reverbWebSocketPath();
        $lh = is_array($dm['laravel_horizon'] ?? null) ? $dm['laravel_horizon'] : [];
        $this->laravel_horizon_path = (string) ($lh['path'] ?? '/horizon');
        $this->laravel_horizon_notes = (string) ($lh['notes'] ?? '');
        $lp = is_array($dm['laravel_pulse'] ?? null) ? $dm['laravel_pulse'] : [];
        $this->laravel_pulse_path = (string) ($lp['path'] ?? '/pulse');
        $this->laravel_pulse_notes = (string) ($lp['notes'] ?? '');
        $this->laravel_scheduler = (bool) $this->site->laravel_scheduler;
        $this->restart_supervisor_programs_after_deploy = (bool) ($this->site->restart_supervisor_programs_after_deploy ?? false);
        $this->deployment_environment = (string) ($this->site->deployment_environment ?? 'production');
        $this->php_fpm_user = (string) ($this->site->php_fpm_user ?? '');
        $this->php_version = (string) ($this->site->phpVersion() ?? '');
        $phpRuntime = is_array($this->site->meta['php_runtime'] ?? null) ? $this->site->meta['php_runtime'] : [];
        $this->php_memory_limit = (string) ($phpRuntime['memory_limit'] ?? '');
        $this->php_upload_max_filesize = (string) ($phpRuntime['upload_max_filesize'] ?? '');
        $this->php_max_execution_time = (string) ($phpRuntime['max_execution_time'] ?? '');
        $this->php_post_max_size = (string) ($phpRuntime['post_max_size'] ?? '');
        $this->php_max_input_time = (string) ($phpRuntime['max_input_time'] ?? '');
        $this->php_max_input_vars = (string) ($phpRuntime['max_input_vars'] ?? '');
        $this->php_max_file_uploads = (string) ($phpRuntime['max_file_uploads'] ?? '');
        $this->php_timezone = (string) ($phpRuntime['timezone'] ?? '');
        $fpmPool = $this->site->phpFpmPoolSettings();
        $this->fpm_pm = $fpmPool['pm'];
        $this->fpm_max_children = (string) $fpmPool['max_children'];
        $this->fpm_max_requests = (string) $fpmPool['max_requests'];
        $this->fpm_request_terminate_timeout = (string) $fpmPool['request_terminate_timeout'];
        $this->runtime_app_port = $this->site->app_port !== null ? (string) $this->site->app_port : '';
        $ips = $this->site->webhook_allowed_ips;
        $this->webhook_allowed_ips_text = is_array($ips) && $ips !== []
            ? implode("\n", $ips)
            : '';
        $this->settings_suspended_message = $this->site->suspendedPublicMessage();
        $repoMeta = $this->site->repositoryMeta();
        $kind = (string) ($repoMeta['git_provider_kind'] ?? 'custom');
        $this->git_provider_kind = in_array($kind, ['github', 'gitlab', 'bitbucket', 'custom'], true) ? $kind : 'custom';
        $this->git_source_control_account_id = (string) ($repoMeta['git_source_control_account_id'] ?? '');
        $this->quick_deploy_enabled_ui = (bool) ($repoMeta['quick_deploy_enabled'] ?? false);
        $this->deploy_sync_include_peers_on_manual = (bool) ($repoMeta['deploy_sync_include_peers_on_manual'] ?? true);
    }

    /**
     * Pre-seeds a `queued` console_actions row for the current site. Shared by
     * finalizeRoutingMutation() and the per-Livewire-action callers (e.g. sync)
     * so the banner appears the moment a job is dispatched, with the operator's
     * intended label already attached.
     *
     * Auto-dismisses any existing completed/failed rows for the same subject so
     * the banner shows ONE thing — the just-started run — rather than stacking
     * stale completion banners behind a fresh run.
     */
    protected function seedQueuedConsoleAction(string $kind, ?string $label = null): ConsoleAction
    {
        // Auto-dismiss completed / failed runs (the "always supersede" rule).
        ConsoleAction::query()
            ->where('subject_type', $this->site->getMorphClass())
            ->where('subject_id', $this->site->id)
            ->whereNull('dismissed_at', 'and', false)
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED], 'and', false)
            ->update(['dismissed_at' => now()]);

        // Also dismiss orphaned queued/running rows past their staleness
        // threshold — jobs whose workers never picked them up (queue down,
        // redis flushed) or that died mid-run. Without this, the new dispatch
        // would race against a zombie banner and the operator would be unsure
        // which run is theirs. isStale() honours per-kind overrides so a
        // legitimately long run (a multi-hour backup) is never mistaken for a
        // zombie just because it outlived the 10-minute global default.
        ConsoleAction::query()
            ->where('subject_type', $this->site->getMorphClass())
            ->where('subject_id', $this->site->id)
            ->whereNull('dismissed_at', 'and', false)
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING], 'and', false)
            ->get()
            ->filter(fn (ConsoleAction $row): bool => $row->isStale())
            ->each(fn (ConsoleAction $row) => $row->forceFill(['dismissed_at' => now()])->save());

        return ConsoleAction::query()->create([
            'subject_type' => $this->site->getMorphClass(),
            'subject_id' => $this->site->id,
            'kind' => $kind,
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => request()->user()?->id,
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
    }

    public function shouldShowSystemUserPanel(): bool
    {
        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            return false;
        }

        return $this->site->shouldShowPhpOctaneRolloutSettings();
    }

    /** Overview SSL card: probe in flight (see {@see \App\Jobs\DetectSiteCloudflareTlsJob}). */
    public bool $ssl_recheck_running = false;

    /** `checked_at` seen at dispatch, so the poll can tell when a fresh result lands. */
    public ?string $ssl_recheck_requested_at = null;

    /**
     * Re-evaluate the site's SSL from the overview card. dply's own origin cert
     * may read `failed` while the domain is actually secured at Cloudflare's edge
     * (orange-clouded) — this fires the header-based Cloudflare TLS probe so the
     * card can reflect that instead of a misleading "failed". Outbound HTTP only,
     * no SSH (the job runs off the request).
     */
    public function recheckSsl(): void
    {
        $this->authorize('update', $this->site);

        $this->ssl_recheck_requested_at = $this->site->cloudflareTlsCheckedAt();
        $this->ssl_recheck_running = true;
        \App\Jobs\DetectSiteCloudflareTlsJob::dispatch($this->site->id);
    }

    /** Driven by wire:poll while a recheck is in flight; resolves once meta updates. */
    public function pollSslRecheck(): void
    {
        if (! $this->ssl_recheck_running) {
            return;
        }

        $this->site->refresh();
        $checkedAt = $this->site->cloudflareTlsCheckedAt();

        // A fresh result has landed once checked_at advances past dispatch time.
        if ($checkedAt !== null && $checkedAt !== $this->ssl_recheck_requested_at) {
            $this->ssl_recheck_running = false;
            $this->toastSuccess(
                $this->site->cloudflareTerminatesTls()
                    ? __('SSL is active — TLS is terminated at Cloudflare’s edge for this domain.')
                    : __('Recheck complete — Cloudflare edge TLS was not detected for this domain.'),
            );
        }
    }

    /**
     * Load the server's workspace (header/breadcrumb) but reuse the row the site
     * just loaded when both share it — the common case — so the render doesn't
     * fire a second identical `workspaces` PK lookup. Call only after the site's
     * own `workspace` relation has been loaded.
     */
    protected function hydrateServerWorkspace(): void
    {
        // Server and site almost always share one workspace. Load it ONCE on the
        // site and hand that same instance to the server, so we don't fire two
        // identical `select * from workspaces where id in (...)` queries — one
        // here for the server and one when the site's workspace is loaded
        // elsewhere (e.g. DeploymentSecretInventory's workspace.variables).
        if (
            $this->server->workspace_id !== null
            && (string) $this->server->workspace_id === (string) $this->site->workspace_id
        ) {
            $this->site->loadMissing('workspace');

            if ($this->site->workspace !== null) {
                $this->server->setRelation('workspace', $this->site->workspace);
            }
        } else {
            $this->server->loadMissing('workspace');
        }

        $this->shareOrganizationInstance();
    }

    /**
     * Site, server and the workspace are all in the same organization — load it
     * once (on the site) and share that instance onto the server and workspace,
     * so an auth check on workspace->organization and $site->organization don't
     * each fire `select * from organizations where id = ?`.
     */
    private function shareOrganizationInstance(): void
    {
        $this->site->loadMissing('organization');
        $org = $this->site->organization;
        if ($org === null) {
            return;
        }

        if (
            $this->server->organization_id !== null
            && (string) $this->server->organization_id === (string) $org->id
            && ! $this->server->relationLoaded('organization')
        ) {
            $this->server->setRelation('organization', $org);
        }

        $workspace = $this->site->workspace;
        if (
            $workspace !== null
            && (string) $workspace->organization_id === (string) $org->id
            && ! $workspace->relationLoaded('organization')
        ) {
            $workspace->setRelation('organization', $org);
        }
    }

    public function render(): View
    {
        $this->resolveWatchedConsoleAction();

        $ready = $this->site->isReadyForWorkspace();
        $activeTab = $this->resolveDashboardTab();

        $relations = [
            'domains',
            'domainAliases',
            'previewDomains',
            'certificates',
            'certificates.previewDomain',
        ];

        if ($ready) {
            $relations[] = 'workspace';

            if ($activeTab === 'deploys') {
                $relations['deployments'] = fn ($q) => $q->limit(25);
                $relations['releases'] = fn ($q) => $q->orderByDesc('id')->limit(30);
            } else {
                $relations['deployments'] = fn ($q) => $q->limit(1);
            }
        }

        if ($this->site->usesEdgeRuntime()) {
            $relations['edgeDeployments'] = fn ($q) => $q->limit($ready ? 10 : 1);
        }

        $this->site->load($relations);
        $this->hydrateServerWorkspace();

        $openSiteInsightsCount = InsightFinding::query()
            ->where('site_id', $this->site->id)
            ->where('status', InsightFinding::STATUS_OPEN)
            ->count();

        $deploymentContract = $ready
            ? app(DeploymentContractBuilder::class)->build($this->site)
            : null;
        $deploymentPreflight = $ready
            ? app(DeploymentPreflightValidator::class)->validate($this->site)
            : [];

        $sitePhpData = $this->server->hostCapabilities()->supportsMachinePhpManagement()
            ? app(ServerPhpManager::class)->sitePhpData($this->server, $this->site)
            : null;

        // The scaffold-install partial needs its step/retry/reveal payload —
        // whether it owns the pre-workspace surface (brand-new scaffold) OR is
        // shown as a banner inside an already-provisioned site's workspace while
        // an install runs.
        $scaffoldData = ($this->site->isScaffoldJourneyActive() || $this->site->isScaffoldInstalling())
            ? $this->scaffoldJourneyData()
            : [];

        return view('livewire.sites.show', array_merge(
            SiteShowViewData::for(
                $this->server,
                $this->site,
                $this,
                $deploymentContract,
                $deploymentPreflight,
                $activeTab,
            ),
            [
                'deployHookUrl' => $this->site->deployHookUrl(),
                'openSiteInsightsCount' => $openSiteInsightsCount,
                'deploymentContract' => $deploymentContract,
                'deploymentPreflight' => $deploymentPreflight,
                'sitePhpData' => $sitePhpData,
            ],
            $scaffoldData,
        ));
    }

    private function resolveDashboardTab(): string
    {
        if (! $this->site->isReadyForWorkspace()) {
            return 'overview';
        }

        $showRuntimeTab = $this->site->usesFunctionsRuntime()
            || $this->site->usesDockerRuntime()
            || $this->site->usesKubernetesRuntime();
        $showSslTab = ! $this->site->usesDockerRuntime()
            && ($this->site->primaryPreviewDomain() || $this->site->certificates()->exists());

        $allowed = ['overview', 'deploys', 'logs'];
        if ($showRuntimeTab) {
            $allowed[] = 'runtime';
        }
        if ($showSslTab) {
            $allowed[] = 'ssl';
        }

        return in_array($this->dashboard_tab, $allowed, true) ? $this->dashboard_tab : 'overview';
    }

    protected function afterLinkedSourceControlAccountsRefreshed(): void
    {
        $this->loadFunctionsSourceControlState(app(SourceControlRepositoryBrowser::class));
    }
}
