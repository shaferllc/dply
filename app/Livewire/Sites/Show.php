<?php

namespace App\Livewire\Sites;

use App\Enums\SiteRedirectKind;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Jobs\AttachEdgeDomainJob;
use App\Jobs\DetachEdgeDomainJob;
use App\Jobs\ExecuteSiteCertificateJob;
use App\Jobs\IssueSiteSslJob;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\PushSiteEnvJob;
use App\Jobs\RemoveSiteRepositoryJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Jobs\SyncEnvFromServerJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesServerlessRuntime;
use App\Models\ConsoleAction;
use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\SiteCertificate;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
use App\Models\SiteDeployStep;
use App\Models\SiteDomain;
use App\Models\SiteRedirect;
use App\Models\SiteRelease;
use App\Models\SocialAccount;
use App\Services\Deploy\DeploymentContractBuilder;
use App\Services\Deploy\DeploymentPreflightValidator;
use App\Services\Deploy\ServerlessRepositoryCheckout;
use App\Services\Deploy\ServerlessRuntimeDetector;
use App\Services\Deploy\ServerlessTargetCapabilityResolver;
use App\Services\Deploy\SiteRuntimeActionExecutor;
use App\Services\RemoteCli\RiskLevel;
use App\Services\RemoteCli\SiteAuditWriter;
use App\Services\Servers\ServerPhpManager;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\PrimaryHostnameRenamePlanner;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Services\Sites\SiteDeploySyncCoordinator;
use App\Services\Sites\SiteProvisioner;
use App\Services\Sites\SiteProvisioningCanceller;
use App\Services\Sites\SiteReleaseRollback;
use App\Services\Certificates\CertificateRequestService;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use App\Support\HostnameValidator;
use App\Support\SiteDeployKeyGenerator;
use App\Support\SiteRedirectConfigSupport;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use ManagesServerlessRuntime;

    public Server $server;

    public Site $site;

    /** Active tab on the post-provisioning dashboard (overview|deploys|runtime|logs|ssl). */
    public string $dashboard_tab = 'overview';

    public string $git_repository_url = '';

    public string $git_branch = 'main';

    public string $functions_repo_source = 'manual';

    public string $functions_source_control_account_id = '';

    public string $functions_repository_selection = '';

    public string $functions_repository_subdirectory = '';

    public string $functions_runtime = '';

    public string $functions_entrypoint = '';

    public string $functions_build_command = '';

    public string $functions_artifact_output_path = '';

    public string $post_deploy_command = '';

    public string $new_domain_hostname = '';

    /** Optional intent comment captured at add-time and rendered on the row. */
    public string $new_domain_comment = '';

    /** Multi-line bulk paste — one hostname per line. */
    public string $bulk_domain_input = '';

    /** When non-null, the domains list shows an inline edit form for this row. */
    public ?string $editing_domain_id = null;

    /**
     * Cascade preview for a primary-hostname rename, set by saveEditedDomain()
     * when the edited row is the primary AND the hostname actually changed AND
     * the rename has non-trivial cascades (existing cert, container backend, …).
     * Consumed by the confirmation modal in routing.blade.php. Null when no
     * rename is pending. Shape matches {@see PrimaryHostnameRenamePlanner::plan()}.
     *
     * @var array{old: string, new: string, auto: list<array{key: string, label: string}>, optIn: list<array{key: string, label: string, detail?: array<string, mixed>}>, manual: list<string>}|null
     */
    public ?array $rename_plan = null;

    /** Opt-in: re-issue SSL cert covering the new hostname during rename confirmation. */
    public bool $rename_reissue_cert = false;

    /** Opt-in: detach old + attach new on the site's container backend during rename confirmation. */
    public bool $rename_cycle_backend = false;

    public string $editing_domain_hostname = '';

    public string $editing_domain_comment = '';

    public ?string $revealed_webhook_secret = null;

    public string $deploy_strategy = 'simple';

    /** Mirrors {@see Site::$deploy_strategy} `atomic` for the zero-downtime card UI. */
    public bool $zero_downtime_enabled = false;

    public bool $deploy_health_enabled = false;

    public bool $deploy_health_auto_rollback = false;

    public string $deploy_health_path = '/health';

    public int $deploy_health_expect_status = 200;

    public int $deploy_health_attempts = 5;

    public int $deploy_health_delay_ms = 500;

    /** http|https — stored in meta `deploy_health_scheme`. */
    public string $deploy_health_scheme = 'http';

    /** Target host for curl (default loopback). */
    public string $deploy_health_host = '127.0.0.1';

    /** Optional TCP port; empty = default for scheme (80/443). */
    public string $deploy_health_port = '';

    /** Recorded in site meta for Rails apps (e.g. production, staging). */
    public string $rails_env = 'production';

    public int $releases_to_keep = 5;

    public string $nginx_extra_raw = '';

    /** Managed VM webserver: nginx FastCGI / proxy cache, Apache static Expires, etc. */
    public bool $engine_http_cache_enabled = false;

    /** Optional message shown on the public suspended page; stored in meta `suspended_message` (VM sites only). */
    public string $settings_suspended_message = '';

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

    public bool $restart_supervisor_programs_after_deploy = false;

    public string $deployment_environment = 'production';

    public string $php_fpm_user = '';

    public string $php_version = '';

    public string $php_memory_limit = '';

    public string $php_upload_max_filesize = '';

    public string $php_max_execution_time = '';

    /** Localhost port for reverse-proxy runtimes (Node, Rails, Puma, containers, etc.). */
    public string $runtime_app_port = '';

    public string $new_env_key = '';

    public string $new_env_value = '';

    /** Optional `# comment` rendered above the KEY=value line on the server. */
    public string $new_env_comment = '';

    /** Multi-line .env block pasted into the bulk-import disclosure inside the Add modal. */
    public string $bulk_env_input = '';

    /** When non-null, the keys list shows an inline edit form for this key. */
    public ?string $editing_env_key = null;

    public string $editing_env_value = '';

    public string $editing_env_comment = '';

    /**
     * Server-side reveal state — keys the operator has clicked Show for, this render.
     * Stored on the component (not Alpine) so reveals survive re-renders triggered
     * by edit/save actions on neighboring rows.
     *
     * @var list<string>
     */
    public array $revealed_env_keys = [];

    /**
     * Operator-overridable absolute path on the host where the .env file is
     * read/written. Empty = use the default ($effectiveEnvDirectory/.env).
     * Stored on the Site row's `env_file_path` column when saved.
     */
    public string $env_file_path_override = '';

    public string $new_redirect_from = '';

    public string $new_redirect_to = '';

    /** @var value-of<SiteRedirectKind> */
    public string $new_redirect_kind = 'http';

    public int $new_redirect_code = 301;

    /**
     * Optional HTTP response headers for new redirect rows (HTTP redirects only).
     *
     * @var list<array{name: string, value: string}>
     */
    public array $new_redirect_header_rows = [['name' => '', 'value' => '']];

    public string $new_redirect_comment = '';

    /**
     * Bulk paste for redirects — one rule per line, comma-separated:
     * `from,to[,code]`. Internal rewrites still go through the single-add form.
     */
    public string $bulk_redirect_input = '';

    /** When non-null, the redirects list shows an inline edit form for this row. */
    public ?string $editing_redirect_id = null;

    /** @var value-of<SiteRedirectKind> */
    public string $editing_redirect_kind = 'http';

    public string $editing_redirect_from = '';

    public string $editing_redirect_to = '';

    public int $editing_redirect_code = 301;

    /** @var list<array{name: string, value: string}> */
    public array $editing_redirect_header_rows = [['name' => '', 'value' => '']];

    public string $editing_redirect_comment = '';

    public string $new_hook_phase = 'after_clone';

    public string $new_hook_script = '';

    public int $new_hook_order = 0;

    public int $new_hook_timeout_seconds = 900;

    public string $new_deploy_step_type = SiteDeployStep::TYPE_COMPOSER_INSTALL;

    public string $new_deploy_step_command = '';

    public int $new_deploy_step_timeout = 900;

    public string $webhook_allowed_ips_text = '';

    /** @var 'github'|'gitlab'|'bitbucket'|'custom' */
    public string $git_provider_kind = 'custom';

    public string $git_source_control_account_id = '';

    public bool $quick_deploy_enabled_ui = false;

    public bool $deploy_sync_include_peers_on_manual = true;

    /**
     * @var list<array{id: string, provider: string, label: string}>
     */
    public array $linkedSourceControlAccounts = [];

    /**
     * @var list<array{label: string, url: string, branch: string}>
     */
    public array $availableFunctionsRepositories = [];

    /**
     * @var array<string, mixed>
     */
    public array $functionsDetection = [];

    public bool $functionsOverridesTouched = false;

    public function mount(Server $server, Site $site): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }
        if ($server->organization_id !== request()->user()?->currentOrganization()?->id) {
            abort(404);
        }

        $this->authorize('view', $site);
        $this->server = $server;
        $this->site = $site;
        $this->syncFormFromSite();
        $this->loadFunctionsSourceControlState(app(SourceControlRepositoryBrowser::class));
        $this->refreshFunctionsDetection();
    }

    protected function syncFormFromSite(): void
    {
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
        $this->zero_downtime_enabled = $this->deploy_strategy === 'atomic';
        $dm = is_array($this->site->meta) ? $this->site->meta : [];
        $this->deploy_health_enabled = (bool) ($dm['deploy_health_enabled'] ?? false);
        $this->deploy_health_auto_rollback = (bool) ($dm['deploy_health_auto_rollback'] ?? false);
        $this->deploy_health_path = (string) ($dm['deploy_health_path'] ?? '/health');
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

    #[On('site-provisioning-updated')]
    public function refreshProvisioningStatus(string $siteId): void
    {
        if ((string) $this->site->id !== $siteId) {
            return;
        }

        $this->site->refresh();
        $this->syncFormFromSite();
    }

    public function pollProvisioningStatus(): void
    {
        if ($this->site->isReadyForWorkspace()) {
            return;
        }

        $this->site->refresh();
        $this->syncFormFromSite();
    }

    public function savePhpSettings(ServerPhpManager $phpManager): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsMachinePhpManagement()) {
            $this->toastError(__('This host runtime does not expose machine PHP settings.'));

            return;
        }

        $phpData = $phpManager->sitePhpData($this->server->fresh(), $this->site->fresh());
        $installedVersions = collect($phpData['installed_versions'] ?? [])
            ->filter(fn (mixed $version): bool => is_array($version) && (bool) ($version['is_supported'] ?? false))
            ->pluck('id')
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->values()
            ->all();

        $rules = [
            'php_version' => ['required', 'string'],
            'php_memory_limit' => ['nullable', 'string', 'max:32', 'regex:/^\d+[KMG]?$/i'],
            'php_upload_max_filesize' => ['nullable', 'string', 'max:32', 'regex:/^\d+[KMG]?$/i'],
            'php_max_execution_time' => ['nullable', 'integer', 'min:1', 'max:3600'],
        ];

        if ($installedVersions !== []) {
            $rules['php_version'][] = 'in:'.implode(',', $installedVersions);
        }

        $validated = $this->validate($rules, [
            'php_version.in' => __('Choose a PHP version that is currently installed on this server.'),
            'php_memory_limit.regex' => __('Use a PHP size like 256M or 1G.'),
            'php_upload_max_filesize.regex' => __('Use a PHP size like 64M or 1G.'),
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['php_runtime'] = [
            'memory_limit' => $validated['php_memory_limit'] !== '' ? $validated['php_memory_limit'] : null,
            'upload_max_filesize' => $validated['php_upload_max_filesize'] !== '' ? $validated['php_upload_max_filesize'] : null,
            'max_execution_time' => $validated['php_max_execution_time'] !== '' ? (string) $validated['php_max_execution_time'] : null,
        ];

        // PHP-version writes now flow through runtime_version (the
        // canonical column post-php_version-drop). Always pin runtime
        // to 'php' on the way out so future reads of runtimeKey() agree.
        $oldVersion = $this->site->runtime_version;
        $this->site->runtime = 'php';
        $this->site->runtime_version = $validated['php_version'];
        $this->site->meta = $meta;
        $this->site->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.php_settings_updated', $this->site, [
                'runtime_version' => $oldVersion,
            ], [
                'runtime_version' => $validated['php_version'],
                'memory_limit' => $meta['php_runtime']['memory_limit'] ?? null,
                'upload_max_filesize' => $meta['php_runtime']['upload_max_filesize'] ?? null,
                'max_execution_time' => $meta['php_runtime']['max_execution_time'] ?? null,
            ]);
        }

        $this->toastSuccess('PHP settings saved.');
        $this->syncFormFromSite();
    }

    public function saveWebhookSecurity(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'webhook_allowed_ips_text' => 'nullable|string|max:4000',
        ]);
        $lines = preg_split('/\r\n|\r|\n/', $this->webhook_allowed_ips_text) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (! $this->validIpOrCidr($line)) {
                $this->addError('webhook_allowed_ips_text', 'Invalid IP or CIDR: '.$line);

                return;
            }
            $clean[] = $line;
        }
        $this->site->webhook_allowed_ips = $clean !== [] ? $clean : null;
        $this->site->save();
        $this->toastSuccess('Webhook IP allow list saved. Leave empty to allow any source (signature still required).');
        $this->syncFormFromSite();
    }

    protected function validIpOrCidr(string $value): bool
    {
        if (str_contains($value, '/')) {
            return (bool) preg_match('#^(\d{1,3}\.){3}\d{1,3}/(3[0-2]|[12]?\d)$#', $value);
        }

        return (bool) filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6);
    }

    public function shouldAutoReapplyManagedWebserverConfig(): bool
    {
        return $this->server->hostCapabilities()->supportsWebserverProvisioning()
            && ! $this->site->usesFunctionsRuntime()
            && ! $this->site->usesDockerRuntime()
            && ! $this->site->usesKubernetesRuntime();
    }

    /**
     * Dispatches the webserver-config apply for the current site (when the host
     * runtime supports it) and shows a toast.
     *
     * `$bannerLabel` is the user-perceived action shown in the page-top
     * console-action banner — "Removing credential", "Saving site settings",
     * etc. NULL falls back to the kind's default copy ("Applying webserver
     * config to :host …"). Setting it lets a single shared apply job carry
     * different banner titles depending on which UI path triggered it.
     */
    protected function finalizeRoutingMutation(string $successMessage, ?string $bannerLabel = null): void
    {
        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->toastSuccess($successMessage);

            return;
        }

        // Pre-seed a queued console_actions row so the banner appears immediately
        // (before the worker picks the job up), and so we can stamp the per-action
        // label. The job's beginConsoleAction() reuses this row instead of
        // creating a new one.
        $this->seedQueuedConsoleAction('webserver_config', $bannerLabel);

        // Queued: errors surface via the apply banner (status=failed) on the next
        // poll, not as inline toasts. Inline-running this used to time out HTTP requests
        // because SSH/nginx work was happening synchronously on the web worker.
        ApplySiteWebserverConfigJob::dispatch($this->site->id);
        $this->toastSuccess($successMessage.' '.__('Webserver config queued.'));
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

        // Also dismiss orphaned queued/running rows past the staleness threshold
        // — they represent jobs whose workers never picked them up (queue down,
        // redis flushed) or that died mid-run. Without this, the new dispatch
        // would race against a zombie banner and the operator would be unsure
        // which run is theirs.
        $staleSeconds = (int) config('console_actions.stale_after_seconds', 600);
        ConsoleAction::query()
            ->where('subject_type', $this->site->getMorphClass())
            ->where('subject_id', $this->site->id)
            ->whereNull('dismissed_at', 'and', false)
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING], 'and', false)
            ->where('created_at', '<', now()->subSeconds($staleSeconds))
            ->update(['dismissed_at' => now()]);

        return ConsoleAction::query()->create([
            'subject_type' => $this->site->getMorphClass(),
            'subject_id' => $this->site->id,
            'kind' => $kind,
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => request()->user()?->id ?? 0,
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);
    }

    public function installNginx(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsWebserverProvisioning()) {
            $this->toastError(__('This host runtime does not use managed webserver config.'));

            return;
        }

        ApplySiteWebserverConfigJob::dispatch($this->site->id);
        $this->toastSuccess(__('Webserver config write queued.'));
    }

    public function issueSsl(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsWebserverProvisioning()) {
            $this->toastError(__('This host runtime does not issue SSL from the server workspace.'));

            return;
        }

        IssueSiteSslJob::dispatch($this->site->id);

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.ssl.issuance_queued', $this->site, null, [
                'primary_hostname' => optional($this->site->primaryDomain)->hostname,
            ]);
        }

        $this->toastSuccess(__('SSL certificate issuance queued.'));
    }

    public function retryProvisioning(SiteProvisioner $siteProvisioner): void
    {
        $this->authorize('update', $this->site);

        $this->site->refresh();

        if ($this->site->isReadyForWorkspace()) {
            $this->toastSuccess(__('This site is already configured.'));

            return;
        }

        $this->site->status = Site::STATUS_PENDING;
        $this->site->save();

        $siteProvisioner->markQueued($this->site->fresh());
        ProvisionSiteJob::dispatch($this->site->id);

        $this->site->refresh();
        $this->toastSuccess(__('Site provisioning has been queued again.'));
    }

    public function openCancelProvisioningModal(): void
    {
        $this->authorize('update', $this->site);

        $this->openConfirmActionModal(
            'cancelProvisioning',
            [],
            __('Halt provisioning?'),
            __('This stops the install, removes the generated testing DNS record, cleans up any web server config that was written, and deletes the pending site. If you cancel this dialog, provisioning keeps running.'),
            __('Halt and remove site'),
            true,
        );
    }

    public function cancelProvisioning(SiteProvisioningCanceller $canceller): void
    {
        $this->authorize('update', $this->site);

        $this->site->refresh();

        if ($this->site->isReadyForWorkspace()) {
            $this->toastError(__('This site is already configured. Delete it from the site actions instead.'));

            return;
        }

        try {
            $canceller->cancel($this->site->fresh(['server', 'domains']));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->redirect(route('sites.create', $this->server), navigate: true);
    }

    public function deployNow(): void
    {
        $this->authorize('update', $this->site);
        try {
            RunSiteDeploymentJob::dispatchSync($this->site, SiteDeployment::TRIGGER_MANUAL);
            $this->site->refresh();
            $this->toastSuccess(config('insights.queue_after_deploy', true)
                ? __('Deployment finished. Server and site insight runs have been queued.')
                : __('Deployment finished.'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function queueDeploy(SiteDeploySyncCoordinator $coordinator): void
    {
        $this->authorize('update', $this->site);
        $coordinator->dispatchManualForGroup($this->site->fresh());
        $base = __('Deployment queued. If another run is in progress, the new one may be recorded as skipped. Refresh deployments below.');
        $this->toastSuccess(config('insights.queue_after_deploy', true)
            ? $base.' '.__('After a successful deploy, server and site insight runs are queued automatically.')
            : $base);
    }

    public function runRuntimeAction(string $action, SiteRuntimeActionExecutor $executor): void
    {
        $this->authorize('update', $this->site);

        try {
            $result = $executor->run($this->site->fresh(), $action);
            $this->storeRuntimeActionResult($action, $result);
            $this->site->refresh();
            $this->toastSuccess(match ($action) {
                'rebuild' => __('Runtime rebuilt.'),
                'start' => __('Runtime started.'),
                'stop' => __('Runtime stopped.'),
                'restart' => __('Runtime restarted.'),
                'inspect' => __('Docker details refreshed.'),
                'errors' => __('Runtime errors refreshed.'),
                'logs' => __('Runtime logs refreshed.'),
                'destroy' => __('Runtime destroyed.'),
                default => __('Runtime status refreshed.'),
            });
        } catch (\Throwable $e) {
            $this->storeRuntimeActionFailure($action, $e->getMessage());
            $this->site->refresh();
            $this->toastError($e->getMessage());
        }
    }

    public function getDeployLockInfoProperty(): ?array
    {
        return Cache::get('site-deploy-active:'.$this->site->id);
    }

    public function releaseDeployLock(): void
    {
        $this->authorize('update', $this->site);
        Cache::lock('site-deploy:'.$this->site->id)->forceRelease();
        Cache::forget('site-deploy-active:'.$this->site->id);
        $this->toastSuccess('Deploy lock cleared. If a worker is still running, stop it on the queue host; otherwise you can deploy again.');
    }

    /**
     * @param  array{status: string, output: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}  $result
     */
    private function storeRuntimeActionResult(string $action, array $result): void
    {
        $site = $this->site->fresh();
        $meta = is_array($site->meta) ? $site->meta : [];
        $runtimeTarget = is_array($meta['runtime_target'] ?? null) ? $meta['runtime_target'] : $site->runtimeTarget();
        $dockerRuntime = is_array($meta['docker_runtime'] ?? null) ? $meta['docker_runtime'] : [];
        $logs = collect($runtimeTarget['logs'] ?? [])
            ->filter(fn (mixed $log): bool => is_array($log))
            ->push([
                'action' => $action,
                'status' => $result['status'],
                'output' => $result['output'],
                'ran_at' => now()->toIso8601String(),
            ])
            ->take(-10)
            ->values()
            ->all();

        $runtimeTargetUpdates = [
            'status' => $result['status'],
            'last_operation' => $action,
            'last_operation_status' => $result['status'],
            'last_operation_at' => now()->toIso8601String(),
            'last_operation_output' => $result['output'],
            'logs' => $logs,
        ];

        if ($action === 'destroy') {
            $runtimeTargetUpdates['publication'] = [];
            $dockerRuntime['runtime_details'] = [];
        } else {
            if (is_array($result['publication'] ?? null)) {
                $runtimeTargetUpdates['publication'] = $result['publication'];
            }

            if (is_array($result['runtime_details'] ?? null)) {
                $dockerRuntime['runtime_details'] = $result['runtime_details'];
            }
        }

        $runtimeTarget = array_merge($runtimeTarget, $runtimeTargetUpdates);

        $meta['runtime_target'] = $runtimeTarget;
        if ($dockerRuntime !== []) {
            $meta['docker_runtime'] = $dockerRuntime;
        }

        $site->forceFill(['meta' => $meta])->save();
    }

    private function storeRuntimeActionFailure(string $action, string $message): void
    {
        $site = $this->site->fresh();
        $meta = is_array($site->meta) ? $site->meta : [];
        $runtimeTarget = is_array($meta['runtime_target'] ?? null) ? $meta['runtime_target'] : $site->runtimeTarget();
        $logs = collect($runtimeTarget['logs'] ?? [])
            ->filter(fn (mixed $log): bool => is_array($log))
            ->push([
                'action' => $action,
                'status' => 'failed',
                'output' => $message,
                'ran_at' => now()->toIso8601String(),
            ])
            ->take(-10)
            ->values()
            ->all();

        $runtimeTarget = array_merge($runtimeTarget, [
            'last_operation' => $action,
            'last_operation_status' => 'failed',
            'last_operation_at' => now()->toIso8601String(),
            'last_operation_output' => $message,
            'logs' => $logs,
        ]);

        $meta['runtime_target'] = $runtimeTarget;
        $site->forceFill(['meta' => $meta])->save();
    }

    public function retryCertificate(string $certificateId): void
    {
        $this->authorize('update', $this->site);
        $certificate = SiteCertificate::query()
            ->where('site_id', $this->site->id)
            ->findOrFail($certificateId);

        try {
            ExecuteSiteCertificateJob::dispatchSync($certificate->id);
            $this->site->refresh();
            $this->toastSuccess(__('Certificate retry finished.'));
        } catch (\Throwable $e) {
            $this->site->refresh();
            $this->toastError($e->getMessage());
        }
    }

    public function saveGit(): void
    {
        $this->authorize('update', $this->site);
        $rules = [
            'git_repository_url' => 'nullable|string|max:500',
            'git_branch' => 'nullable|string|max:120',
            'post_deploy_command' => 'nullable|string|max:4000',
        ];

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            if (($this->functionsDetection['unsupported_for_target'] ?? false) === true) {
                $this->toastError((string) ($this->functionsDetection['warnings'][0] ?? __('This repository runtime is not supported by the selected target.')));

                return;
            }

            $rules = array_merge($rules, [
                'functions_repo_source' => 'required|string|in:manual,provider',
                'functions_source_control_account_id' => 'nullable|string|max:26',
                'functions_repository_selection' => 'nullable|string|max:500',
                'functions_repository_subdirectory' => 'nullable|string|max:255',
                'functions_runtime' => 'required|string|max:50',
                'functions_entrypoint' => 'required|string|max:255',
                'functions_build_command' => 'required|string|max:4000',
                'functions_artifact_output_path' => 'required|string|max:255',
                'git_repository_url' => 'required|string|max:500',
                'git_branch' => 'required|string|max:120',
            ]);

            if ($this->functions_repo_source === 'provider') {
                $rules['functions_source_control_account_id'] = 'required|string|max:26';
            }
        }

        $this->validate($rules);

        $updates = [
            'git_repository_url' => trim($this->git_repository_url) ?: null,
            'git_branch' => trim($this->git_branch) ?: 'main',
            'post_deploy_command' => trim($this->post_deploy_command) ?: null,
        ];

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $meta = is_array($this->site->meta) ? $this->site->meta : [];
            $functionsConfig = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
            $meta['serverless'] = array_merge($functionsConfig, [
                'repo_source' => trim($this->functions_repo_source),
                'source_control_account_id' => $this->functions_repo_source === 'provider'
                    ? trim($this->functions_source_control_account_id)
                    : null,
                'repository_subdirectory' => trim($this->functions_repository_subdirectory),
                'runtime' => trim($this->functions_runtime),
                'entrypoint' => trim($this->functions_entrypoint),
                'build_command' => trim($this->functions_build_command),
                'artifact_output_path' => trim($this->functions_artifact_output_path),
                'detected_runtime' => $this->functionsDetection !== [] ? $this->functionsDetection : null,
            ]);
            $updates['meta'] = $meta;
        }

        $oldRepoSnapshot = [
            'git_repository_url' => $this->site->git_repository_url,
            'git_branch' => $this->site->git_branch,
            'post_deploy_command' => $this->site->post_deploy_command,
        ];
        $this->site->update($updates);
        $org = $this->site->server?->organization;
        if ($org && $oldRepoSnapshot !== array_intersect_key($updates, $oldRepoSnapshot)) {
            audit_log($org, auth()->user(), 'site.repository_updated', $this->site, $oldRepoSnapshot, array_intersect_key($updates, $oldRepoSnapshot));
        }
        $this->toastSuccess('Git settings saved.');
        $this->syncFormFromSite();
    }

    public function saveRepositoryWorkspace(): void
    {
        $this->authorize('update', $this->site);
        if (request()->user()?->currentOrganization()?->userIsDeployer(request()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot change repository settings.'));

            return;
        }

        $rules = [
            'git_repository_url' => 'nullable|string|max:500',
            'git_branch' => 'nullable|string|max:120',
            'git_provider_kind' => 'required|string|in:github,gitlab,bitbucket,custom',
            'git_source_control_account_id' => 'nullable|string|max:26',
            'deploy_sync_include_peers_on_manual' => 'boolean',
        ];
        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $rules['git_repository_url'] = 'required|string|max:500';
            $rules['git_branch'] = 'required|string|max:120';
        }
        if ($this->git_provider_kind !== 'custom' && $this->git_source_control_account_id === '') {
            $this->addError('git_source_control_account_id', __('Select a linked source control account or choose Custom.'));

            return;
        }

        $this->validate($rules);

        $this->site->mergeRepositoryMeta([
            'git_provider_kind' => $this->git_provider_kind,
            'git_source_control_account_id' => $this->git_source_control_account_id !== '' ? $this->git_source_control_account_id : null,
            'deploy_sync_include_peers_on_manual' => $this->deploy_sync_include_peers_on_manual,
        ]);

        $this->site->fill([
            'git_repository_url' => trim($this->git_repository_url) ?: null,
            'git_branch' => trim($this->git_branch) ?: 'main',
            'post_deploy_command' => trim($this->post_deploy_command) ?: null,
        ]);
        $this->site->save();
        $this->toastSuccess(__('Repository settings saved.'));
        $this->syncFormFromSite();
    }

    public function enableQuickDeploy(RepositoryWebhookProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);
        if (request()->user()?->currentOrganization()?->userIsDeployer(request()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot enable Quick deploy.'));

            return;
        }

        $account = $this->git_source_control_account_id !== ''
            ? SocialAccount::query()->where('user_id', request()->user()?->id ?? 0)->findOrFail($this->git_source_control_account_id)
            : null;
        if ($account === null) {
            $this->toastError(__('Select a connected source control account first.'));

            return;
        }

        $result = $provisioner->enable($this->site->fresh(), $account);
        if (! $result['ok']) {
            $this->toastError($result['message']);
        } else {
            $this->toastSuccess($result['message']);
        }
        $this->site->refresh();
        $this->syncFormFromSite();
    }

    public function disableQuickDeploy(RepositoryWebhookProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);
        if (request()->user()?->currentOrganization()?->userIsDeployer(request()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot change Quick deploy.'));

            return;
        }

        $provisioner->disable($this->site->fresh());
        $this->toastSuccess(__('Quick deploy disabled and provider hook removed when possible.'));
        $this->site->refresh();
        $this->syncFormFromSite();
    }

    public function queueRemoveRemoteRepository(): void
    {
        $this->authorize('update', $this->site);
        if (request()->user()?->currentOrganization()?->userIsDeployer(request()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot remove the repository checkout.'));

            return;
        }

        if ($this->site->usesFunctionsRuntime() || $this->site->usesDockerRuntime() || $this->site->usesKubernetesRuntime()) {
            $this->toastError(__('This runtime does not use a traditional VM repository path.'));

            return;
        }

        RemoveSiteRepositoryJob::dispatch($this->site->id);
        $this->toastSuccess(__('Repository removal has been queued. This may take a minute on large trees.'));
    }

    public function updatedFunctionsRepoSource(): void
    {
        if ($this->functions_repo_source === 'manual') {
            $this->functions_source_control_account_id = '';
            $this->functions_repository_selection = '';
            $this->availableFunctionsRepositories = [];

            $this->refreshFunctionsDetection();

            return;
        }

        if ($this->linkedSourceControlAccounts === []) {
            return;
        }

        $this->functions_source_control_account_id = $this->linkedSourceControlAccounts[0]['id'];
        $this->updatedFunctionsSourceControlAccountId($this->functions_source_control_account_id);
    }

    public function updatedFunctionsSourceControlAccountId(string $value): void
    {
        $this->functions_source_control_account_id = $value;
        $this->functions_repository_selection = '';
        $this->availableFunctionsRepositories = [];

        if ($value === '') {
            return;
        }

        $account = request()->user()?->socialAccounts()->findOrFail($value);
        if (! $account) {
            return;
        }

        $this->availableFunctionsRepositories = app(SourceControlRepositoryBrowser::class)
            ->repositoriesForAccount($account);
    }

    public function updatedFunctionsRepositorySelection(string $value): void
    {
        foreach ($this->availableFunctionsRepositories as $repository) {
            if (($repository['url'] ?? null) !== $value) {
                continue;
            }

            $this->git_repository_url = (string) $repository['url'];
            $this->git_branch = (string) ($repository['branch'] ?: 'main');
            $this->refreshFunctionsDetection();

            return;
        }
    }

    public function updatedGitRepositoryUrl(): void
    {
        $this->refreshFunctionsDetection();
    }

    public function updatedGitBranch(): void
    {
        $this->refreshFunctionsDetection();
    }

    public function updatedFunctionsRepositorySubdirectory(): void
    {
        $this->refreshFunctionsDetection();
    }

    public function updatedFunctionsRuntime(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFunctionsEntrypoint(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFunctionsBuildCommand(): void
    {
        $this->functionsOverridesTouched = true;
    }

    public function updatedFunctionsArtifactOutputPath(): void
    {
        $this->functionsOverridesTouched = true;
    }

    private function refreshFunctionsDetection(): void
    {
        if (! $this->server->hostCapabilities()->supportsFunctionDeploy()) {
            return;
        }

        $repositoryUrl = trim($this->git_repository_url);
        $branch = trim($this->git_branch);

        if ($repositoryUrl === '' || $branch === '') {
            $this->functionsDetection = [];

            return;
        }

        $checkout = null;

        try {
            $checkout = app(ServerlessRepositoryCheckout::class)->checkout(
                'preview-site-'.$this->site->id.'-'.md5($repositoryUrl.'|'.$branch.'|'.$this->functions_repository_subdirectory),
                $repositoryUrl,
                $branch,
                $this->functions_repository_subdirectory,
                $this->site->user_id,
                $this->functions_repo_source === 'provider' ? $this->functions_source_control_account_id : null,
            );

            $this->functionsDetection = app(ServerlessRuntimeDetector::class)->detect(
                $checkout['working_directory'],
                app(ServerlessTargetCapabilityResolver::class)->forServer($this->server),
            );

            if (! $this->functionsOverridesTouched) {
                $this->functions_runtime = (string) ($this->functionsDetection['runtime'] ?? $this->functions_runtime);
                $this->functions_entrypoint = (string) ($this->functionsDetection['entrypoint'] ?? $this->functions_entrypoint);
                $this->functions_build_command = (string) ($this->functionsDetection['build_command'] ?? $this->functions_build_command);
                $this->functions_artifact_output_path = (string) ($this->functionsDetection['artifact_output_path'] ?? $this->functions_artifact_output_path);
            }
        } catch (\Throwable $e) {
            $this->functionsDetection = [
                'framework' => 'unknown',
                'language' => 'unknown',
                'runtime' => '',
                'entrypoint' => '',
                'build_command' => '',
                'artifact_output_path' => '',
                'package' => 'default',
                'confidence' => 'low',
                'reasons' => [],
                'warnings' => [$e->getMessage()],
                'unsupported_for_target' => false,
            ];
        } finally {
            if (is_array($checkout) && isset($checkout['workspace_path']) && is_string($checkout['workspace_path'])) {
                app(ServerlessRepositoryCheckout::class)->cleanup($checkout['workspace_path']);
            }
        }
    }

    public function generateDeployKey(): void
    {
        $this->authorize('update', $this->site);
        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->toastError(__('Serverless-backed sites deploy from the configured artifact zip instead of a server-side git checkout.'));

            return;
        }

        try {
            [$private, $public] = SiteDeployKeyGenerator::generate();
            $this->site->git_deploy_key_private = $private;
            $this->site->git_deploy_key_public = $public;
            $this->site->save();
            $this->toastSuccess('New deploy key generated. Add the public key to your Git host.');
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function regenerateWebhookSecret(RepositoryWebhookProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);
        $plain = Str::random(48);
        $this->site->webhook_secret = $plain;
        $this->site->save();
        $this->revealed_webhook_secret = $plain;
        $provisioner->syncProviderHookSecret($this->site->fresh());

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.webhook.secret_rotated', $this->site, null, null);
        }

        $this->toastSuccess('Webhook secret rotated. Copy it below — it will not be shown again.');
    }

    /**
     * Dispatches the env-push job (via console banner). The actual SSH write
     * happens in the worker; the banner streams progress to the page top.
     * One-in-flight per site is enforced by PushSiteEnvJob's ShouldBeUnique
     * guard, so back-to-back clicks coalesce into a single push that uses
     * the latest cache state.
     */
    public function pushEnvToServer(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host runtime does not support pushing a .env file over SSH.'));

            return;
        }

        $this->seedQueuedConsoleAction('env_push');

        PushSiteEnvJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
        );

        $this->toastSuccess(__('Push queued — track progress in the banner at the top of this page.'));
    }

    /**
     * Lazy first-visit sync. Fired by wire:init on the Environment partial:
     * the page renders synchronously, then this runs in a follow-up request,
     * dispatching the env-sync job when (and only when) we've never touched
     * the cache before. Subsequent visits with a populated cache are no-ops.
     *
     * Conditions for firing:
     *   - Runtime supports a server .env file (VM hosts only)
     *   - Cache is empty AND has no origin (truly first visit — never synced
     *     and never edited)
     *   - No env_sync job already in flight (idempotent against navigation
     *     bouncing in/out of the section)
     *
     * Auth uses 'view' rather than 'update' because read is a lower-priv
     * action and the operator hasn't asked to mutate anything yet.
     */
    public function autoSyncIfFirstVisit(): void
    {
        $this->authorize('view', $this->site);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            return;
        }
        if (filled($this->site->env_file_content) || $this->site->env_cache_origin !== null) {
            return;
        }

        $inFlight = ConsoleAction::query()
            ->forSubject($this->site)
            ->ofKind('env_sync')
            ->notDismissed()
            ->inFlight()
            ->exists();
        if ($inFlight) {
            return;
        }

        $this->seedQueuedConsoleAction('env_sync');
        SyncEnvFromServerJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
        );
    }

    /**
     * Dispatches a backgrounded job that SSHes into the host, reads the live
     * `.env` file, and writes it into the encrypted env_file_content cache.
     * Mirrors {@see Settings::syncBasicAuthFromServer()}: progress streams to
     * a console_actions row whose banner is mounted on the settings page.
     */
    public function syncEnvFromServer(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host runtime does not expose a server .env file.'));

            return;
        }

        $this->seedQueuedConsoleAction('env_sync');

        SyncEnvFromServerJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
        );

        $this->toastSuccess(__('Env sync queued — track progress in the banner at the top of this page.'));
    }

    /**
     * One-click "move .env outside docroot" — sets env_file_path to the
     * default convention (/etc/dply/<slug>.env) and dispatches the push job
     * so the file lands at the new location immediately. The doctor finding
     * surfaces the issue; this action resolves it without making the
     * operator type the path.
     */
    public function relocateEnvOutsideDocroot(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastError(__('This host runtime does not have a server .env to relocate.'));

            return;
        }

        $newPath = '/etc/dply/'.$this->site->slug.'.env';
        $this->site->forceFill(['env_file_path' => $newPath])->save();
        $this->env_file_path_override = $newPath;

        $this->seedQueuedConsoleAction('env_push');
        PushSiteEnvJob::dispatch($this->site->id, (string) (auth()->id() ?? ''));

        $this->toastSuccess(__('Relocating .env to :path — see banner for progress.', ['path' => $newPath]));
    }

    /**
     * Saves a custom .env file path on the Site row. Empty input clears the
     * override (revert to default $effectiveEnvDirectory/.env). Used by
     * security-conscious operators to relocate the file outside the docroot —
     * e.g. /etc/dply/<slug>.env — so the webserver can never serve it.
     *
     * Path must be absolute. Validation is intentionally strict to avoid
     * accidental relative paths that resolve unpredictably on the host.
     */
    public function saveEnvFilePath(): void
    {
        $this->authorize('update', $this->site);
        $value = trim($this->env_file_path_override);

        if ($value === '') {
            $this->site->forceFill(['env_file_path' => null])->save();
            $this->autoPushAfterCacheMutation(__('Default .env path restored.'));

            return;
        }

        $this->validate([
            'env_file_path_override' => ['required', 'string', 'max:1024', 'regex:/^\/[^\\\\\\0]+$/'],
        ], [
            'env_file_path_override.regex' => __('Path must be absolute (start with /) and not contain backslashes or null bytes.'),
        ]);

        $this->site->forceFill(['env_file_path' => $value])->save();
        $this->autoPushAfterCacheMutation(__('Custom .env path saved.'));
    }

    public function saveZeroDowntimeDeployment(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'zero_downtime_enabled' => 'boolean',
        ]);

        $previousStrategy = (string) ($this->site->deploy_strategy ?? 'simple');
        $newStrategy = $this->zero_downtime_enabled ? 'atomic' : 'simple';

        $this->site->update(['deploy_strategy' => $newStrategy]);
        $this->site->refresh();
        $this->deploy_strategy = $newStrategy;

        $message = __('Zero downtime deployment settings saved.');

        if ($previousStrategy === $newStrategy) {
            $this->toastSuccess($message);

            return;
        }

        if ($this->shouldAutoReapplyManagedWebserverConfig()) {
            ApplySiteWebserverConfigJob::dispatch($this->site->id);
            $this->toastSuccess($message.' '.__('Webserver config queued.'));

            return;
        }

        $this->toastSuccess($message.' '.__('Use “Apply webserver config now” on the Routing tab if the document root should match this strategy.'));
    }

    public function shouldShowSystemUserPanel(): bool
    {
        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            return false;
        }

        return $this->site->shouldShowPhpOctaneRolloutSettings();
    }

    public function saveDeploymentSettings(): void
    {
        $this->authorize('update', $this->site);
        $this->site->refresh();
        $showPhpOctane = $this->site->shouldShowPhpOctaneRolloutSettings();
        $showOctane = $showPhpOctane && $this->site->shouldShowOctaneRuntimeUi();
        $showReverb = $showPhpOctane && $this->site->shouldShowLaravelReverbRuntimeUi();
        $showRails = $this->site->shouldShowRailsRuntimeSettings();

        $rules = [
            'releases_to_keep' => 'required|integer|min:1|max:50',
            'nginx_extra_raw' => 'nullable|string|max:16000',
            'laravel_scheduler' => 'boolean',
            'restart_supervisor_programs_after_deploy' => 'boolean',
            'deployment_environment' => 'required|string|max:32',
            'deploy_health_enabled' => 'boolean',
            'deploy_health_auto_rollback' => 'boolean',
            'deploy_health_path' => 'nullable|string|max:512',
            'deploy_health_expect_status' => 'required|integer|min:100|max:599',
            'deploy_health_attempts' => 'required|integer|min:1|max:30',
            'deploy_health_delay_ms' => 'required|integer|min:0|max:10000',
            'deploy_health_scheme' => ['required', Rule::in(['http', 'https'])],
            'deploy_health_host' => ['required', 'string', 'max:255'],
            'deploy_health_port' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (! is_numeric($value) || (int) $value < 1 || (int) $value > 65535) {
                        $fail(__('Enter a valid port between 1 and 65535, or leave empty for the default for your scheme.'));
                    }
                },
            ],
        ];

        if ($showOctane) {
            $rules['octane_port'] = 'nullable|integer|min:1|max:65535';
            $rules['octane_server'] = ['required', Rule::in(Site::OCTANE_SERVERS)];
        }

        if ($showReverb) {
            $rules['laravel_reverb_port'] = 'nullable|integer|min:1|max:65535';
            $rules['laravel_reverb_ws_path'] = ['nullable', 'string', 'max:128'];
        }

        if ($showRails) {
            $rules['rails_env'] = 'nullable|string|max:32';
        }

        if (! $this->shouldShowSystemUserPanel()) {
            $rules['php_fpm_user'] = 'nullable|string|max:64';
        }

        $this->validate($rules);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $path = trim($this->deploy_health_path);
        if ($path === '') {
            $path = '/health';
        }
        $meta['deploy_health_enabled'] = $this->deploy_health_enabled;
        $meta['deploy_health_auto_rollback'] = $this->deploy_health_auto_rollback;
        $meta['deploy_health_path'] = $path[0] === '/' ? $path : '/'.$path;
        $meta['deploy_health_expect_status'] = $this->deploy_health_expect_status;
        $meta['deploy_health_attempts'] = $this->deploy_health_attempts;
        $meta['deploy_health_delay_ms'] = $this->deploy_health_delay_ms;
        $meta['deploy_health_scheme'] = $this->deploy_health_scheme;
        $meta['deploy_health_host'] = trim($this->deploy_health_host) !== '' ? trim($this->deploy_health_host) : '127.0.0.1';
        $meta['deploy_health_port'] = $this->deploy_health_port !== '' ? (int) $this->deploy_health_port : null;

        if ($showOctane) {
            $lo = is_array($meta['laravel_octane'] ?? null) ? $meta['laravel_octane'] : [];
            $lo['server'] = $this->octane_server;
            $meta['laravel_octane'] = $lo;
        }

        if ($showReverb) {
            $rv = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
            $rv['port'] = $this->laravel_reverb_port !== '' ? (int) $this->laravel_reverb_port : 8080;
            $ws = trim($this->laravel_reverb_ws_path);
            $rv['ws_path'] = $ws !== '' ? $ws : '/app';
            $meta['laravel_reverb'] = $rv;
        }

        if ($showRails) {
            $railsRuntime = is_array($meta['rails_runtime'] ?? null) ? $meta['rails_runtime'] : [];
            $env = trim($this->rails_env);
            $railsRuntime['env'] = $env !== '' ? $env : 'production';
            $meta['rails_runtime'] = $railsRuntime;
        }

        $update = [
            'releases_to_keep' => $this->releases_to_keep,
            'nginx_extra_raw' => $this->nginx_extra_raw !== '' ? $this->nginx_extra_raw : null,
            'laravel_scheduler' => $this->laravel_scheduler,
            'restart_supervisor_programs_after_deploy' => $this->restart_supervisor_programs_after_deploy,
            'deployment_environment' => $this->deployment_environment,
            'meta' => $meta,
        ];

        if (! $this->shouldShowSystemUserPanel()) {
            $update['php_fpm_user'] = $this->php_fpm_user !== '' ? $this->php_fpm_user : null;
        }

        if ($showOctane) {
            $update['octane_port'] = $this->octane_port !== '' ? (int) $this->octane_port : null;
        }

        $this->site->update($update);
        $this->syncFormFromSite();
        $this->toastSuccess('Deployment / Nginx settings saved. Re-install Nginx if you changed redirects, Octane, or extra config. Re-sync server crontab for Laravel scheduler. When “Restart Supervisor after deploy” is on, Dply restarts programs for this site (and server-wide programs) after a successful deploy.');
    }

    public function saveEngineHttpCache(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->toastError(__('Engine HTTP cache is only available for managed VM web server sites on this host.'));

            return;
        }

        $this->validate([
            'engine_http_cache_enabled' => ['boolean'],
        ]);

        $this->site->engine_http_cache_enabled = $this->engine_http_cache_enabled;
        $this->site->save();
        $this->site->refresh();
        $this->syncFormFromSite();

        ApplySiteWebserverConfigJob::dispatch($this->site->id);
        $this->toastSuccess($this->engine_http_cache_enabled
            ? __('Engine HTTP cache enabled. Web server config queued.')
            : __('Engine HTTP cache disabled. Web server config queued.'));
    }

    /**
     * Single-row add: writes one key into the encrypted env cache, then
     * auto-pushes to the server's .env file. The push is synchronous so the
     * operator gets immediate feedback; on push failure the cache update is
     * preserved so they can manually retry via the Push button.
     */
    public function addEnvVar(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_env_key' => 'required|string|max:128|regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
            'new_env_value' => 'nullable|string|max:20000',
            'new_env_comment' => 'nullable|string|max:1000',
        ]);

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $map = $parsed['variables'];
        $comments = $parsed['comments'];
        $map[$this->new_env_key] = (string) $this->new_env_value;
        $trimmedComment = trim($this->new_env_comment);
        if ($trimmedComment !== '') {
            $comments[$this->new_env_key] = $trimmedComment;
        } else {
            unset($comments[$this->new_env_key]);
        }
        $this->site->forceFill([
            'env_file_content' => $writer->render($map, $comments),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.var_added', $this->site, null, [
                'key' => $this->new_env_key,
            ]);
        }

        $this->new_env_key = '';
        $this->new_env_value = '';
        $this->new_env_comment = '';
        $this->autoPushAfterCacheMutation(__('Variable saved.'));
    }

    /**
     * Bulk paste — accepts a multi-line .env block. Existing keys not in the
     * pasted block are preserved (additive merge); pasted keys overwrite
     * matching existing keys (last value wins, matches `.env` semantics).
     */
    public function bulkImportEnvVars(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['bulk_env_input' => 'required|string|max:65535']);

        $incoming = $parser->parse($this->bulk_env_input);
        if ($incoming['errors'] !== []) {
            foreach ($incoming['errors'] as $err) {
                $this->addError('bulk_env_input', $err);
            }

            return;
        }

        $existing = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $mergedVars = array_merge($existing['variables'], $incoming['variables']);
        // Comments merge with incoming taking precedence — pasting `# foo`
        // above an existing KEY in the bulk block REPLACES that KEY's
        // existing comment. Keys not in the paste keep their old comments.
        $mergedComments = array_merge($existing['comments'], $incoming['comments']);

        $this->site->forceFill([
            'env_file_content' => $writer->render($mergedVars, $mergedComments),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $count = count($incoming['variables']);

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.bulk_imported', $this->site, null, [
                'imported_count' => $count,
                'imported_keys' => array_keys($incoming['variables']),
            ]);
        }

        $this->bulk_env_input = '';
        $this->autoPushAfterCacheMutation(__(':count variable(s) imported.', ['count' => $count]));
    }

    /**
     * Open the inline editor for a single key. Pulls the current value out
     * of the encrypted cache and parks it in editing_env_value for the
     * blade form to bind.
     */
    public function editEnvVar(DotEnvFileParser $parser, string $key): void
    {
        $this->authorize('update', $this->site);
        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        if (! array_key_exists($key, $parsed['variables'])) {
            return;
        }
        $this->editing_env_key = $key;
        $this->editing_env_value = $parsed['variables'][$key];
        $this->editing_env_comment = (string) ($parsed['comments'][$key] ?? '');
    }

    public function cancelEditEnvVar(): void
    {
        $this->editing_env_key = null;
        $this->editing_env_value = '';
        $this->editing_env_comment = '';
    }

    public function saveEditedEnvVar(DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'editing_env_key' => 'required|string|max:128|regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
            'editing_env_value' => 'nullable|string|max:20000',
            'editing_env_comment' => 'nullable|string|max:1000',
        ]);

        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        $map = $parsed['variables'];
        $comments = $parsed['comments'];
        $key = (string) $this->editing_env_key;
        $map[$key] = (string) $this->editing_env_value;
        $trimmedComment = trim($this->editing_env_comment);
        if ($trimmedComment !== '') {
            $comments[$key] = $trimmedComment;
        } else {
            unset($comments[$key]);
        }
        $this->site->forceFill([
            'env_file_content' => $writer->render($map, $comments),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.var_updated', $this->site, null, [
                'key' => $key,
            ]);
        }

        $this->cancelEditEnvVar();
        $this->autoPushAfterCacheMutation(__('Variable updated.'));
    }

    /**
     * Trash button on a key row hits this first; it opens the shared
     * confirm-action modal pointing at {@see removeEnvVar()}. The modal's
     * confirm button dispatches the underlying method via the
     * ConfirmsActionWithModal trait, which container-resolves the
     * DotEnvFileParser / DotEnvFileWriter dependencies.
     */
    public function confirmRemoveEnvVar(string $key): void
    {
        $this->authorize('update', $this->site);
        $this->openConfirmActionModal(
            method: 'removeEnvVar',
            arguments: [$key],
            title: __('Remove :key?', ['key' => $key]),
            message: __('This deletes :key from the cache and auto-pushes the change to the server. The variable will be gone from the live .env immediately.', ['key' => $key]),
            confirmLabel: __('Remove'),
            destructive: true,
        );
    }

    public function removeEnvVar(string $key, DotEnvFileParser $parser, DotEnvFileWriter $writer): void
    {
        $this->authorize('update', $this->site);
        $parsed = $parser->parse((string) ($this->site->env_file_content ?? ''));
        if (! array_key_exists($key, $parsed['variables'])) {
            return;
        }
        unset($parsed['variables'][$key], $parsed['comments'][$key]);
        $this->site->forceFill([
            'env_file_content' => $writer->render($parsed['variables'], $parsed['comments']),
            'env_cache_origin' => 'local-edit',
        ])->save();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.env.var_removed', $this->site, ['key' => $key], null);
        }

        $this->revealed_env_keys = array_values(array_diff($this->revealed_env_keys, [$key]));
        $this->autoPushAfterCacheMutation(__('Variable removed.'));
    }

    /**
     * Dispatches the push job after a successful cache mutation. On hosts
     * without a server-side .env (Docker/K8s/Serverless), the push is a
     * no-op — those runtimes inject env at deploy time. PushSiteEnvJob's
     * ShouldBeUnique guard means rapid-fire mutations coalesce into a
     * single push with the latest cache state.
     *
     * Errors will surface in the banner; the cache write is always
     * preserved. There's no manual Push button anymore — Sync from server
     * re-reads if needed, and any subsequent mutation re-fires this method.
     */
    protected function autoPushAfterCacheMutation(string $savedMessage): void
    {
        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->toastSuccess($savedMessage.' '.__('Saved.'));

            return;
        }

        $this->seedQueuedConsoleAction('env_push');

        PushSiteEnvJob::dispatch(
            $this->site->id,
            (string) (auth()->id() ?? ''),
        );

        $this->toastSuccess($savedMessage.' '.__('Pushing to server — see banner.'));
    }

    public function toggleRevealEnvVar(string $key): void
    {
        $this->authorize('update', $this->site);
        if (in_array($key, $this->revealed_env_keys, true)) {
            $this->revealed_env_keys = array_values(array_diff($this->revealed_env_keys, [$key]));

            return;
        }
        $this->revealed_env_keys[] = $key;
    }

    public function addRedirectRule(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_redirect_kind' => ['required', Rule::in(array_column(SiteRedirectKind::cases(), 'value'))],
            'new_redirect_from' => 'required|string|max:512',
            'new_redirect_to' => [
                'required',
                'string',
                'max:1024',
                Rule::when(
                    $this->new_redirect_kind === SiteRedirectKind::InternalRewrite->value,
                    ['regex:/^\/$|^\/[a-zA-Z0-9\/_\-]+$/']
                ),
            ],
            'new_redirect_code' => [
                Rule::requiredIf(fn () => $this->new_redirect_kind === SiteRedirectKind::Http->value),
                'nullable',
                'integer',
                Rule::in(SiteRedirectConfigSupport::allowedHttpRedirectStatusCodes()),
            ],
            'new_redirect_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $responseHeaders = null;
        if ($this->new_redirect_kind === SiteRedirectKind::Http->value) {
            $responseHeaders = $this->validateAndNormalizeRedirectHeaders($this->new_redirect_header_rows, 'new_redirect_header_rows');
        }

        SiteRedirect::query()->create([
            'site_id' => $this->site->id,
            'kind' => SiteRedirectKind::from($this->new_redirect_kind),
            'from_path' => $this->new_redirect_from,
            'to_url' => $this->new_redirect_to,
            'status_code' => $this->new_redirect_kind === SiteRedirectKind::InternalRewrite->value
                ? 301
                : (int) $this->new_redirect_code,
            'response_headers' => $responseHeaders,
            'comment' => trim($this->new_redirect_comment) ?: null,
            'sort_order' => (int) ($this->site->redirects()->max('sort_order') ?? 0) + 1,
        ]);
        $this->new_redirect_from = '';
        $this->new_redirect_to = '';
        $this->new_redirect_kind = SiteRedirectKind::Http->value;
        $this->new_redirect_code = 301;
        $this->new_redirect_header_rows = [['name' => '', 'value' => '']];
        $this->new_redirect_comment = '';
        $this->finalizeRoutingMutation('Redirect added.');
    }

    /**
     * Shared validation + normalization for redirect response headers.
     * Used by both `addRedirectRule()` and `saveEditedRedirect()` so the
     * inline edit form gets the same per-field error UX as the add form.
     *
     * @param  array<int, array{name?: string|null, value?: string|null}>  $rows
     * @return array<string, string>|null Normalized headers, or null if all rows were blank.
     */
    protected function validateAndNormalizeRedirectHeaders(array $rows, string $errorKeyPrefix): ?array
    {
        foreach ($rows as $i => $row) {
            $n = trim((string) ($row['name'] ?? ''));
            $v = trim((string) ($row['value'] ?? ''));
            if ($n === '' && $v === '') {
                continue;
            }
            if ($n === '' || $v === '') {
                throw ValidationException::withMessages([
                    "{$errorKeyPrefix}.{$i}.name" => [__('Provide both a header name and value, or clear the row.')],
                ]);
            }
            if (! SiteRedirectConfigSupport::isValidHeaderName($n)) {
                throw ValidationException::withMessages([
                    "{$errorKeyPrefix}.{$i}.name" => [__('Use a valid header name (letters, digits, and !#$&-.^_`|~).')],
                ]);
            }
            if (! SiteRedirectConfigSupport::isValidHeaderValue($v)) {
                throw ValidationException::withMessages([
                    "{$errorKeyPrefix}.{$i}.value" => [__('Header value is too long or contains invalid characters.')],
                ]);
            }
            if (SiteRedirectConfigSupport::isForbiddenResponseHeaderName($n)) {
                throw ValidationException::withMessages([
                    "{$errorKeyPrefix}.{$i}.name" => [__('This header cannot be set from a redirect.')],
                ]);
            }
        }
        $normalized = SiteRedirectConfigSupport::normalizeResponseHeaders($rows);

        return $normalized === [] ? null : $normalized;
    }

    public function confirmRemoveRedirect(int|string $redirectId): void
    {
        $this->authorize('update', $this->site);
        // Cast to string so the trait round-trips the value through Livewire
        // serialization without surprises. deleteRedirectRule accepts
        // int|string anyway (whereKey coerces).
        $this->openConfirmActionModal(
            'deleteRedirectRule',
            [(string) $redirectId],
            __('Remove redirect'),
            __('Remove this redirect rule? Linked traffic will stop being redirected after the next webserver apply.'),
            __('Remove redirect'),
            true,
        );
    }

    public function editRedirect(int|string $redirectId): void
    {
        $this->authorize('update', $this->site);
        $redirect = SiteRedirect::query()->where('site_id', $this->site->id)->findOrFail($redirectId);
        $this->editing_redirect_id = (string) $redirect->id;
        $this->editing_redirect_kind = $redirect->kind instanceof SiteRedirectKind
            ? $redirect->kind->value
            : (string) $redirect->kind;
        $this->editing_redirect_from = (string) $redirect->from_path;
        $this->editing_redirect_to = (string) $redirect->to_url;
        $this->editing_redirect_code = (int) $redirect->status_code;
        $headers = is_array($redirect->response_headers) ? $redirect->response_headers : [];
        $rows = [];
        foreach ($headers as $name => $value) {
            $rows[] = ['name' => (string) $name, 'value' => (string) $value];
        }
        if ($rows === []) {
            $rows = [['name' => '', 'value' => '']];
        }
        $this->editing_redirect_header_rows = $rows;
        $this->editing_redirect_comment = (string) ($redirect->comment ?? '');
    }

    public function cancelEditRedirect(): void
    {
        $this->editing_redirect_id = null;
        $this->editing_redirect_kind = SiteRedirectKind::Http->value;
        $this->editing_redirect_from = '';
        $this->editing_redirect_to = '';
        $this->editing_redirect_code = 301;
        $this->editing_redirect_header_rows = [['name' => '', 'value' => '']];
        $this->editing_redirect_comment = '';
    }

    public function addEditingRedirectHeaderRow(): void
    {
        if (count($this->editing_redirect_header_rows) >= 8) {
            return;
        }
        $this->editing_redirect_header_rows[] = ['name' => '', 'value' => ''];
    }

    public function removeEditingRedirectHeaderRow(int $index): void
    {
        unset($this->editing_redirect_header_rows[$index]);
        $this->editing_redirect_header_rows = array_values($this->editing_redirect_header_rows);
        if ($this->editing_redirect_header_rows === []) {
            $this->editing_redirect_header_rows = [['name' => '', 'value' => '']];
        }
    }

    public function saveEditedRedirect(): void
    {
        $this->authorize('update', $this->site);
        if ($this->editing_redirect_id === null) {
            return;
        }
        $redirect = SiteRedirect::query()->where('site_id', $this->site->id)->findOrFail($this->editing_redirect_id);
        $this->validate([
            'editing_redirect_kind' => ['required', Rule::in(array_column(SiteRedirectKind::cases(), 'value'))],
            'editing_redirect_from' => 'required|string|max:512',
            'editing_redirect_to' => [
                'required',
                'string',
                'max:1024',
                Rule::when(
                    $this->editing_redirect_kind === SiteRedirectKind::InternalRewrite->value,
                    ['regex:/^\/$|^\/[a-zA-Z0-9\/_\-]+$/']
                ),
            ],
            'editing_redirect_code' => [
                Rule::requiredIf(fn () => $this->editing_redirect_kind === SiteRedirectKind::Http->value),
                'nullable',
                'integer',
                Rule::in(SiteRedirectConfigSupport::allowedHttpRedirectStatusCodes()),
            ],
            'editing_redirect_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $responseHeaders = null;
        if ($this->editing_redirect_kind === SiteRedirectKind::Http->value) {
            $responseHeaders = $this->validateAndNormalizeRedirectHeaders($this->editing_redirect_header_rows, 'editing_redirect_header_rows');
        }

        $redirect->forceFill([
            'kind' => SiteRedirectKind::from($this->editing_redirect_kind),
            'from_path' => $this->editing_redirect_from,
            'to_url' => $this->editing_redirect_to,
            'status_code' => $this->editing_redirect_kind === SiteRedirectKind::InternalRewrite->value
                ? 301
                : (int) $this->editing_redirect_code,
            'response_headers' => $responseHeaders,
            'comment' => trim($this->editing_redirect_comment) ?: null,
        ])->save();

        $this->cancelEditRedirect();
        $this->finalizeRoutingMutation('Redirect updated.');
    }

    /**
     * Bulk paste — `from,to[,code]` per line. Internal rewrites still go
     * through the single-add form (kind selector lives there). Code
     * defaults to 301; values must match allowed status codes.
     */
    public function bulkImportRedirects(): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['bulk_redirect_input' => 'required|string|max:65535']);

        $allowedCodes = SiteRedirectConfigSupport::allowedHttpRedirectStatusCodes();
        $lines = preg_split('/\r\n|\r|\n/', trim($this->bulk_redirect_input)) ?: [];
        $parsed = [];
        foreach ($lines as $i => $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', explode(',', $line));
            if (count($parts) < 2) {
                $this->addError('bulk_redirect_input', sprintf('Line %d: expected `from,to[,code]`.', $i + 1));

                return;
            }
            [$from, $to] = $parts;
            $code = isset($parts[2]) && $parts[2] !== '' ? (int) $parts[2] : 301;
            if ($from === '' || $to === '') {
                $this->addError('bulk_redirect_input', sprintf('Line %d: from/to may not be blank.', $i + 1));

                return;
            }
            if (! in_array($code, $allowedCodes, true)) {
                $this->addError('bulk_redirect_input', sprintf('Line %d: status code %d is not allowed.', $i + 1, $code));

                return;
            }
            $parsed[] = ['from' => $from, 'to' => $to, 'code' => $code];
        }

        $sortBase = (int) ($this->site->redirects()->max('sort_order') ?? 0);
        foreach ($parsed as $row) {
            SiteRedirect::query()->create([
                'site_id' => $this->site->id,
                'kind' => SiteRedirectKind::Http,
                'from_path' => $row['from'],
                'to_url' => $row['to'],
                'status_code' => $row['code'],
                'response_headers' => null,
                'sort_order' => ++$sortBase,
            ]);
        }

        $this->bulk_redirect_input = '';
        $this->finalizeRoutingMutation(__(':count redirect(s) imported.', ['count' => count($parsed)]));
    }

    public function addNewRedirectHeaderRow(): void
    {
        if (count($this->new_redirect_header_rows) >= 8) {
            return;
        }
        $this->new_redirect_header_rows[] = ['name' => '', 'value' => ''];
    }

    public function removeNewRedirectHeaderRow(int $index): void
    {
        unset($this->new_redirect_header_rows[$index]);
        $this->new_redirect_header_rows = array_values($this->new_redirect_header_rows);
        if ($this->new_redirect_header_rows === []) {
            $this->new_redirect_header_rows = [['name' => '', 'value' => '']];
        }
    }

    public function deleteRedirectRule(int|string $id): void
    {
        $this->authorize('update', $this->site);
        SiteRedirect::query()->where('site_id', $this->site->id)->whereKey($id)->delete();
        $this->finalizeRoutingMutation('Redirect removed.');
    }

    public function addDeployHook(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_hook_phase' => 'required|in:before_clone,after_clone,after_activate',
            'new_hook_script' => 'required|string|max:16000',
            'new_hook_order' => 'integer|min:0|max:999',
            'new_hook_timeout_seconds' => 'required|integer|min:30|max:3600',
        ]);
        SiteDeployHook::query()->create([
            'site_id' => $this->site->id,
            'phase' => $this->new_hook_phase,
            'script' => $this->new_hook_script,
            'sort_order' => $this->new_hook_order,
            'timeout_seconds' => $this->new_hook_timeout_seconds,
        ]);
        $this->new_hook_script = '';
        $this->new_hook_order = 0;
        $this->new_hook_timeout_seconds = 900;
        $this->toastSuccess('Deploy hook added.');
    }

    public function deleteDeployHook(int $id): void
    {
        $this->authorize('update', $this->site);
        SiteDeployHook::query()->where('site_id', $this->site->id)->whereKey($id)->delete();
        $this->toastSuccess('Hook removed.');
    }

    public function addDeployPipelineStep(): void
    {
        $this->authorize('update', $this->site);
        $types = array_keys(SiteDeployStep::typeLabels());
        $this->validate([
            'new_deploy_step_type' => 'required|string|in:'.implode(',', $types),
            'new_deploy_step_command' => 'nullable|string|max:4000',
            'new_deploy_step_timeout' => 'required|integer|min:30|max:3600',
        ]);
        $needsCustom = in_array($this->new_deploy_step_type, [
            SiteDeployStep::TYPE_NPM_RUN,
            SiteDeployStep::TYPE_CUSTOM,
        ], true);
        if ($needsCustom && trim($this->new_deploy_step_command) === '') {
            $this->addError('new_deploy_step_command', 'This step type needs a value in the command field.');

            return;
        }
        SiteDeployStep::query()->create([
            'site_id' => $this->site->id,
            'sort_order' => (int) ($this->site->deploySteps()->max('sort_order') ?? 0) + 1,
            'step_type' => $this->new_deploy_step_type,
            'custom_command' => trim($this->new_deploy_step_command) !== '' ? trim($this->new_deploy_step_command) : null,
            'timeout_seconds' => $this->new_deploy_step_timeout,
        ]);
        $this->new_deploy_step_command = '';
        $this->new_deploy_step_timeout = 900;
        $this->toastSuccess('Deploy pipeline step added. Runs after git, before the post-deploy command.');
    }

    public function deleteDeployPipelineStep(int $id): void
    {
        $this->authorize('update', $this->site);
        SiteDeployStep::query()->where('site_id', $this->site->id)->whereKey($id)->delete();
        $this->toastSuccess('Pipeline step removed.');
    }

    public function moveDeployStepUp(int $id): void
    {
        $this->authorize('update', $this->site);
        $ids = SiteDeployStep::query()->where('site_id', $this->site->id)->orderBy('sort_order', 'asc')->pluck('id')->all();
        $pos = array_search($id, $ids, true);
        if ($pos === false || $pos === 0) {
            return;
        }
        [$ids[$pos - 1], $ids[$pos]] = [$ids[$pos], $ids[$pos - 1]];
        foreach ($ids as $i => $stepId) {
            SiteDeployStep::query()->whereKey($stepId)->update(['sort_order' => $i + 1]);
        }
        $this->toastSuccess('Pipeline order updated.');
    }

    public function moveDeployStepDown(int $id): void
    {
        $this->authorize('update', $this->site);
        $ids = SiteDeployStep::query()->where('site_id', $this->site->id)->orderBy('sort_order', 'asc')->pluck('id')->all();
        $pos = array_search($id, $ids, true);
        if ($pos === false || $pos >= count($ids) - 1) {
            return;
        }
        [$ids[$pos + 1], $ids[$pos]] = [$ids[$pos], $ids[$pos + 1]];
        foreach ($ids as $i => $stepId) {
            SiteDeployStep::query()->whereKey($stepId)->update(['sort_order' => $i + 1]);
        }
        $this->toastSuccess('Pipeline order updated.');
    }

    public function confirmRollbackRelease(int|string $releaseId): void
    {
        $this->authorize('update', $this->site);

        $this->openConfirmActionModal(
            'rollbackRelease',
            [(string) $releaseId],
            __('Rollback release'),
            __('Point current symlink at this release?'),
            __('Rollback'),
            true,
        );
    }

    public function rollbackRelease(int|string $releaseId, SiteReleaseRollback $rollback): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsReleaseRollback()) {
            $this->toastError(__('This host runtime does not support release rollback via server symlinks.'));

            return;
        }

        try {
            $release = SiteRelease::query()->where('site_id', $this->site->id)->findOrFail($releaseId);
            $rollback->rollbackTo($this->site, $release);
            $this->site->refresh();
            $this->toastSuccess('Rolled back active release symlink. Re-install Nginx if document root changed.');
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function addDomain(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_domain_hostname' => [
                'required',
                'string',
                'max:255',
                'unique:site_domains,hostname',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid domain name like app.example.com.');
                    }
                },
            ],
            'new_domain_comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $newDomain = SiteDomain::query()->create([
            'site_id' => $this->site->id,
            'hostname' => strtolower(trim($this->new_domain_hostname)),
            'is_primary' => false,
            'www_redirect' => false,
            'comment' => trim($this->new_domain_comment) ?: null,
        ]);

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.domain.added', $this->site, null, [
                'domain_id' => (string) $newDomain->id,
                'hostname' => $newDomain->hostname,
            ]);
        }

        $this->new_domain_hostname = '';
        $this->new_domain_comment = '';
        $this->finalizeRoutingMutation('Domain added.');
    }

    public function editDomain(int|string $domainId): void
    {
        $this->authorize('update', $this->site);
        $domain = SiteDomain::query()->where('site_id', $this->site->id)->findOrFail($domainId);
        $this->editing_domain_id = (string) $domain->id;
        $this->editing_domain_hostname = (string) $domain->hostname;
        $this->editing_domain_comment = (string) ($domain->comment ?? '');
    }

    public function cancelEditDomain(): void
    {
        $this->editing_domain_id = null;
        $this->editing_domain_hostname = '';
        $this->editing_domain_comment = '';
    }

    public function saveEditedDomain(): void
    {
        $this->authorize('update', $this->site);
        if ($this->editing_domain_id === null) {
            return;
        }
        $domain = SiteDomain::query()->where('site_id', $this->site->id)->findOrFail($this->editing_domain_id);
        $this->validate([
            'editing_domain_hostname' => [
                'required',
                'string',
                'max:255',
                Rule::unique('site_domains', 'hostname')->ignore($domain->id),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! HostnameValidator::isValid($value)) {
                        $fail('Enter a valid domain name like app.example.com.');
                    }
                },
            ],
            'editing_domain_comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $oldHostname = strtolower(trim((string) $domain->hostname));
        $newHostname = strtolower(trim($this->editing_domain_hostname));
        $commentChanged = trim($this->editing_domain_comment) !== (string) ($domain->comment ?? '');
        $isPrimary = (bool) $domain->is_primary;

        // Comment-only edits or non-primary domain edits skip the cascade entirely
        // — same code path as before. The cascade only triggers when the row IS
        // the primary AND its hostname actually changed AND the rename is non-trivial.
        if (! $isPrimary || $oldHostname === $newHostname) {
            $domain->forceFill([
                'hostname' => $newHostname,
                'comment' => trim($this->editing_domain_comment) ?: null,
            ])->save();

            $org = $this->site->server?->organization;
            if ($org) {
                audit_log($org, auth()->user(), 'site.domain.updated', $this->site, [
                    'hostname' => $oldHostname,
                ], [
                    'domain_id' => (string) $domain->id,
                    'hostname' => $newHostname,
                ]);
            }

            $this->cancelEditDomain();
            $this->finalizeRoutingMutation('Domain updated.');

            return;
        }

        // Persist the comment edit immediately — it's independent of the hostname
        // cascade and the operator may have edited both fields together.
        if ($commentChanged) {
            $domain->forceFill(['comment' => trim($this->editing_domain_comment) ?: null])->save();
        }

        $planner = app(PrimaryHostnameRenamePlanner::class);
        $plan = $planner->plan($this->site, $newHostname);

        if ($planner->isTrivial($plan)) {
            $domain->forceFill(['hostname' => $newHostname])->save();
            $this->recordRenameAudit($oldHostname, $newHostname, [], false);
            $this->cancelEditDomain();
            $this->finalizeRoutingMutation('Primary hostname renamed.');

            return;
        }

        $this->rename_plan = $plan;
        $this->rename_reissue_cert = false;
        $this->rename_cycle_backend = false;
        $this->dispatch('open-modal', 'primary-hostname-rename-modal');
    }

    /**
     * Commit the primary-hostname rename selected in the confirmation modal,
     * applying the opt-in cascades the operator checked. Mutates only when
     * `$rename_plan` is set (defensive — the modal can't fire otherwise).
     */
    public function confirmPrimaryHostnameRename(): void
    {
        $this->authorize('update', $this->site);

        if ($this->rename_plan === null) {
            return;
        }

        $primaryDomain = $this->site->primaryDomain();
        if ($primaryDomain === null) {
            $this->rename_plan = null;

            return;
        }

        $old = strtolower(trim((string) $primaryDomain->hostname));
        $new = strtolower(trim((string) $this->rename_plan['new']));

        // Re-plan defensively in case the site changed under us (modal could
        // have been open while another tab issued a cert, etc.). Use the fresh
        // plan to decide which opt-ins are still applicable.
        $planner = app(PrimaryHostnameRenamePlanner::class);
        $freshPlan = $planner->plan($this->site, $new);
        $optInKeys = array_map(fn (array $row) => $row['key'], $freshPlan['optIn']);

        $reissueCert = $this->rename_reissue_cert && in_array('reissue_cert', $optInKeys, true);
        $cycleBackend = $this->rename_cycle_backend && in_array('cycle_backend', $optInKeys, true);
        $rewriteDnsZone = collect($freshPlan['auto'])->contains(fn (array $row) => $row['key'] === 'dns_zone');

        $primaryDomain->forceFill(['hostname' => $new])->save();

        if ($rewriteDnsZone) {
            $this->site->update([
                'dns_zone' => Site::apexGuessForHostname($new),
            ]);
        }

        $cascadeKeys = [];
        if ($reissueCert) {
            $cascadeKeys[] = 'reissue_cert';
            $this->dispatchCertReissue($freshPlan);
        }
        if ($cycleBackend) {
            $cascadeKeys[] = 'cycle_backend';
            DetachEdgeDomainJob::dispatch($this->site->id, $old);
            AttachEdgeDomainJob::dispatch($this->site->id, $new);
        }

        $this->recordRenameAudit($old, $new, $cascadeKeys, $rewriteDnsZone);

        $this->rename_plan = null;
        $this->rename_reissue_cert = false;
        $this->rename_cycle_backend = false;
        $this->dispatch('close-modal', 'primary-hostname-rename-modal');
        $this->cancelEditDomain();
        $this->finalizeRoutingMutation('Primary hostname renamed.');
    }

    /**
     * Discard the pending rename — leaves the row's edit form open with the
     * unsaved hostname so the operator can keep editing or revert manually.
     */
    public function cancelPrimaryHostnameRename(): void
    {
        $this->rename_plan = null;
        $this->rename_reissue_cert = false;
        $this->rename_cycle_backend = false;
        $this->dispatch('close-modal', 'primary-hostname-rename-modal');
    }

    /**
     * Clone the customer-scope certs that covered the old hostname and queue
     * issuance against the now-current `sslIssuanceHostnames()`. Mirrors the
     * quick-issue flow at {@see SiteSettings::saveQuickDomainSslModal()}.
     *
     * @param  array{optIn: list<array{key: string, label: string, detail?: array<string, mixed>}>}  $plan
     */
    private function dispatchCertReissue(array $plan): void
    {
        $row = collect($plan['optIn'] ?? [])->firstWhere('key', 'reissue_cert');
        $certIds = is_array($row) ? ($row['detail']['cert_ids'] ?? []) : [];
        if (! is_array($certIds) || $certIds === []) {
            return;
        }

        $certificateRequestService = app(CertificateRequestService::class);
        $sourceCerts = SiteCertificate::query()
            ->where('site_id', $this->site->id)
            ->whereIn('id', $certIds)
            ->get();
        $newHostnames = $this->site->sslIssuanceHostnames();

        foreach ($sourceCerts as $source) {
            $certificate = $certificateRequestService->create([
                'site_id' => $this->site->id,
                'scope_type' => $source->scope_type ?? SiteCertificate::SCOPE_CUSTOMER,
                'provider_type' => $source->provider_type ?? SiteCertificate::PROVIDER_LETSENCRYPT,
                'challenge_type' => $source->challenge_type ?? SiteCertificate::CHALLENGE_HTTP,
                'domains_json' => $newHostnames,
                'status' => SiteCertificate::STATUS_PENDING,
                'requested_settings' => [
                    'source' => 'primary_hostname_rename',
                    'replaced_certificate_id' => (string) $source->id,
                ],
            ]);

            ExecuteSiteCertificateJob::dispatch($certificate->id);
        }
    }

    /**
     * @param  list<string>  $cascades  Opt-in cascade keys the operator selected
     *                                  (used to make audit payload self-describing).
     */
    private function recordRenameAudit(string $old, string $new, array $cascades, bool $dnsZoneRewritten): void
    {
        app(SiteAuditWriter::class)->record(
            site: $this->site,
            user: auth()->user(),
            action: 'site_primary_hostname_renamed',
            risk: RiskLevel::MutatingRecoverable,
            transport: SiteAuditEvent::TRANSPORT_WEB,
            summary: __('Primary hostname changed from :old to :new', ['old' => $old !== '' ? $old : '(none)', 'new' => $new]),
            payload: [
                'old_hostname' => $old,
                'new_hostname' => $new,
                'cascades' => $cascades,
                'dns_zone_rewritten' => $dnsZoneRewritten,
            ],
        );
    }

    /**
     * Bulk paste — one hostname per line. Lines that are blank or already
     * present (across any routing table) are skipped silently. Parse errors
     * abort the whole import (consistent with the env bulk import behavior).
     */
    public function bulkImportDomains(): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['bulk_domain_input' => 'required|string|max:65535']);

        $lines = preg_split('/\r\n|\r|\n/', trim($this->bulk_domain_input)) ?: [];
        $hostnames = [];
        foreach ($lines as $i => $line) {
            $line = strtolower(trim($line));
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! HostnameValidator::isValid($line)) {
                $this->addError('bulk_domain_input', sprintf('Line %d: "%s" is not a valid hostname.', $i + 1, $line));

                return;
            }
            $hostnames[] = $line;
        }

        $existing = SiteDomain::query()->whereIn('hostname', $hostnames)->pluck('hostname')->all();
        $imported = 0;
        foreach ($hostnames as $hostname) {
            if (in_array($hostname, $existing, true)) {
                continue;
            }
            SiteDomain::query()->create([
                'site_id' => $this->site->id,
                'hostname' => $hostname,
                'is_primary' => false,
                'www_redirect' => false,
            ]);
            $imported++;
        }

        $this->bulk_domain_input = '';
        $this->finalizeRoutingMutation(__(':count domain(s) imported.', ['count' => $imported]));
    }

    public function confirmRemoveDomain(int|string $domainId): void
    {
        $this->authorize('update', $this->site);

        $this->openConfirmActionModal(
            'removeDomain',
            [(string) $domainId],
            __('Remove domain'),
            __('Remove this domain?'),
            __('Remove domain'),
            true,
        );
    }

    public function removeDomain(int|string $domainId): void
    {
        $this->authorize('update', $this->site);
        $domain = SiteDomain::query()->where('site_id', $this->site->id)->findOrFail($domainId);
        if ($domain->is_primary && $this->site->domains()->count() === 1) {
            $this->toastError('Cannot remove the only domain.');

            return;
        }
        if ($domain->hostname === $this->site->testingHostname()) {
            $this->toastError('The generated testing hostname is managed by Dply and cannot be removed here.');

            return;
        }
        if ($domain->is_primary) {
            $this->toastError('Set another domain as primary before removing the primary domain.');

            return;
        }
        $snapshot = [
            'domain_id' => (string) $domain->id,
            'hostname' => $domain->hostname,
            'is_primary' => (bool) $domain->is_primary,
        ];
        $domain->delete();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.domain.removed', $this->site, $snapshot, null);
        }

        $this->finalizeRoutingMutation('Domain removed.');
    }

    public function deleteSite(): void
    {
        $this->authorize('delete', $this->site);
        $organization = $this->site->server?->organization;
        $snapshot = [
            'name' => $this->site->name,
            'slug' => $this->site->slug,
            'server_id' => (string) $this->site->server_id,
            'type' => $this->site->type instanceof \BackedEnum ? $this->site->type->value : (string) $this->site->type,
            'runtime' => $this->site->runtime,
            'git_repository_url' => $this->site->git_repository_url,
        ];
        $this->site->delete();

        if ($organization) {
            audit_log(
                $organization,
                auth()->user(),
                'site.deleted',
                $this->site,
                $snapshot,
                null,
            );
        }

        $this->redirect(route('servers.show', $this->server), navigate: true);
    }

    public function confirmSuspendSite(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->toastError(__('Site suspension requires managed web server configuration on this host.'));

            return;
        }

        $this->openConfirmActionModal(
            'suspendSite',
            [],
            __('Suspend site'),
            __('Visitors will see a suspended page instead of your application until you resume. SSL and domains are unchanged.'),
            __('Suspend site'),
            true,
        );
    }

    /**
     * Suspension only swaps managed HTTP vhost config; deploy hooks and deployments are unchanged (MVP).
     */
    public function suspendSite(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->toastError(__('Site suspension is not available for this runtime.'));

            return;
        }

        $this->validate([
            'settings_suspended_message' => ['nullable', 'string', 'max:500'],
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $message = trim($this->settings_suspended_message);
        if ($message !== '') {
            $meta['suspended_message'] = $message;
        } else {
            unset($meta['suspended_message']);
        }

        $this->site->update([
            'suspended_at' => now(),
            'suspended_reason' => null,
            'meta' => $meta,
        ]);
        $this->site->refresh();
        $this->syncFormFromSite();

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.suspended', $this->site, null, [
                'message' => $message !== '' ? $message : null,
            ]);
        }

        ApplySiteWebserverConfigJob::dispatch($this->site->id);
        $this->toastSuccess(__('Site suspended. Webserver config queued.'));
    }

    public function resumeSite(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->toastError(__('Resuming requires managed web server configuration on this host.'));

            return;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        unset($meta['suspended_message']);

        $this->site->suspended_at = null;
        $this->site->suspended_reason = null;
        $this->site->meta = $meta;
        $this->site->save();
        $this->site->refresh();
        $this->settings_suspended_message = '';

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.resumed', $this->site, null, null);
        }

        ApplySiteWebserverConfigJob::dispatch($this->site->id);
        $this->toastSuccess(__('Site resumed. Webserver config queued.'));
    }

    public function render(): View
    {
        $this->site->load([
            'domains',
            'domainAliases',
            'basicAuthUsers',
            'tenantDomains',
            'previewDomains',
            'certificates',
            'deployments' => fn ($q) => $q->limit(25),
            'webhookDeliveryLogs' => fn ($q) => $q->limit(30),
            'redirects',
            'deployHooks',
            'deploySteps',
            'releases' => fn ($q) => $q->orderByDesc('id')->limit(30),
            'previewDomains',
            'certificates.previewDomain',
        ]);

        $openSiteInsightsCount = InsightFinding::query()
            ->where('site_id', $this->site->id)
            ->where('status', InsightFinding::STATUS_OPEN)
            ->count();

        return view('livewire.sites.show', [
            'deployHookUrl' => $this->site->deployHookUrl(),
            'openSiteInsightsCount' => $openSiteInsightsCount,
            'deploymentContract' => app(DeploymentContractBuilder::class)->build($this->site),
            'deploymentPreflight' => app(DeploymentPreflightValidator::class)->validate($this->site),
            'sitePhpData' => $this->server->hostCapabilities()->supportsMachinePhpManagement()
                ? app(ServerPhpManager::class)->sitePhpData($this->server, $this->site)
                : null,
        ]);
    }

    private function loadFunctionsSourceControlState(SourceControlRepositoryBrowser $repositoryBrowser): void
    {
        $this->linkedSourceControlAccounts = $repositoryBrowser->accountsForUser(request()->user());

        if (! $this->server->hostCapabilities()->supportsFunctionDeploy()) {
            return;
        }

        if ($this->linkedSourceControlAccounts === []) {
            $this->functions_repo_source = 'manual';

            return;
        }

        if ($this->functions_repo_source === 'provider' && $this->functions_source_control_account_id === '') {
            $this->functions_source_control_account_id = $this->linkedSourceControlAccounts[0]['id'];
        }

        if ($this->functions_repo_source !== 'provider') {
            return;
        }

        $account = request()->user()?->socialAccounts()->findOrFail($this->functions_source_control_account_id);
        $this->availableFunctionsRepositories = $account
            ? $repositoryBrowser->repositoriesForAccount($account)
            : [];
    }
}
