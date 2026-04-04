<?php

namespace App\Livewire\Sites;

use App\Enums\SiteRedirectKind;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Jobs\ExecuteSiteCertificateJob;
use App\Jobs\IssueSiteSslJob;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\RemoveSiteRepositoryJob;
use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Models\SiteDeployHook;
use App\Models\SiteDeployment;
use App\Models\SiteDeployStep;
use App\Models\SiteDomain;
use App\Models\SiteEnvironmentVariable;
use App\Models\SiteRedirect;
use App\Models\SiteRelease;
use App\Models\SocialAccount;
use App\Services\Deploy\DeploymentContractBuilder;
use App\Services\Deploy\DeploymentPreflightValidator;
use App\Services\Deploy\ServerlessRepositoryCheckout;
use App\Services\Deploy\ServerlessRuntimeDetector;
use App\Services\Deploy\ServerlessTargetCapabilityResolver;
use App\Services\Deploy\SiteRuntimeActionExecutor;
use App\Services\Servers\ServerPhpManager;
use App\Services\Sites\RepositoryWebhookProvisioner;
use App\Services\Sites\SiteDeploySyncCoordinator;
use App\Services\Sites\SiteEnvPusher;
use App\Services\Sites\SiteProvisioner;
use App\Services\Sites\SiteProvisioningCanceller;
use App\Services\Sites\SiteReleaseRollback;
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

    public Server $server;

    public Site $site;

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

    public string $env_file_content = '';

    public string $new_domain_hostname = '';

    public ?string $flash_success = null;

    public ?string $flash_error = null;

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

    public string $new_env_environment = 'production';

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
        if ($server->organization_id !== auth()->user()->currentOrganization()?->id) {
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
        $this->site->refresh();
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
        $this->functionsDetection = is_array($functionsConfig['detected_runtime'] ?? null)
            ? $functionsConfig['detected_runtime']
            : [];
        $this->post_deploy_command = (string) ($this->site->post_deploy_command ?? '');
        $this->env_file_content = (string) ($this->site->env_file_content ?? '');
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
        $this->php_version = (string) ($this->site->php_version ?? '');
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
            $this->flash_error = __('This host runtime does not expose machine PHP settings.');

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

        $this->site->update([
            'php_version' => $validated['php_version'],
            'meta' => $meta,
        ]);

        $this->flash_success = 'PHP settings saved.';
        $this->flash_error = null;
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
        $this->site->update([
            'webhook_allowed_ips' => $clean !== [] ? $clean : null,
        ]);
        $this->flash_success = 'Webhook IP allow list saved. Leave empty to allow any source (signature still required).';
        $this->flash_error = null;
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
        return $this->server->hostCapabilities()->supportsNginxProvisioning()
            && ! $this->site->usesFunctionsRuntime()
            && ! $this->site->usesDockerRuntime()
            && ! $this->site->usesKubernetesRuntime();
    }

    protected function finalizeRoutingMutation(string $successMessage): void
    {
        $this->flash_error = null;

        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->flash_success = $successMessage;

            return;
        }

        try {
            ApplySiteWebserverConfigJob::dispatchSync($this->site->id);
            $this->site->refresh();
            $this->flash_success = $successMessage.' Webserver config reloaded.';
        } catch (\Throwable $e) {
            $this->flash_success = $successMessage.' Saved, but the webserver config could not be re-applied automatically.';
            $this->flash_error = $e->getMessage();
        }
    }

    public function installNginx(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsNginxProvisioning()) {
            $this->flash_error = __('This host runtime does not use managed webserver config.');

            return;
        }

        $this->flash_error = null;
        $this->flash_success = null;
        try {
            ApplySiteWebserverConfigJob::dispatchSync($this->site->id);
            $this->site->refresh();
            $this->flash_success = 'Webserver config written and reloaded.';
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function issueSsl(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsNginxProvisioning()) {
            $this->flash_error = __('This host runtime does not issue SSL from the server workspace.');

            return;
        }

        $this->flash_error = null;
        $this->flash_success = null;
        try {
            IssueSiteSslJob::dispatchSync($this->site);
            $this->site->refresh();
            $this->flash_success = 'SSL certificate requested. Refresh if status still updating.';
        } catch (\Throwable $e) {
            $this->site->refresh();
            $this->flash_error = $e->getMessage();
        }
    }

    public function retryProvisioning(SiteProvisioner $siteProvisioner): void
    {
        $this->authorize('update', $this->site);

        $this->flash_error = null;
        $this->flash_success = null;

        $this->site->refresh();

        if ($this->site->isReadyForWorkspace()) {
            $this->flash_success = __('This site is already configured.');

            return;
        }

        $this->site->update([
            'status' => Site::STATUS_PENDING,
        ]);

        $siteProvisioner->markQueued($this->site->fresh());
        ProvisionSiteJob::dispatch($this->site->id);

        $this->site->refresh();
        $this->flash_success = __('Site provisioning has been queued again.');
    }

    public function cancelProvisioning(SiteProvisioningCanceller $canceller): mixed
    {
        $this->authorize('update', $this->site);

        $this->flash_error = null;
        $this->flash_success = null;

        $this->site->refresh();

        if ($this->site->isReadyForWorkspace()) {
            $this->flash_error = __('This site is already configured. Delete it from the site actions instead.');

            return null;
        }

        try {
            $canceller->cancel($this->site->fresh(['server', 'domains']));
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();

            return null;
        }

        return $this->redirect(route('sites.create', $this->server), navigate: true);
    }

    public function deployNow(): void
    {
        $this->authorize('update', $this->site);
        $this->flash_error = null;
        $this->flash_success = null;
        try {
            RunSiteDeploymentJob::dispatchSync($this->site, SiteDeployment::TRIGGER_MANUAL);
            $this->site->refresh();
            $this->flash_success = config('insights.queue_after_deploy', true)
                ? __('Deployment finished. Server and site insight runs have been queued.')
                : __('Deployment finished.');
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function queueDeploy(SiteDeploySyncCoordinator $coordinator): void
    {
        $this->authorize('update', $this->site);
        $coordinator->dispatchManualForGroup($this->site->fresh());
        $base = __('Deployment queued. If another run is in progress, the new one may be recorded as skipped. Refresh deployments below.');
        $this->flash_success = config('insights.queue_after_deploy', true)
            ? $base.' '.__('After a successful deploy, server and site insight runs are queued automatically.')
            : $base;
        $this->flash_error = null;
    }

    public function runRuntimeAction(string $action, SiteRuntimeActionExecutor $executor): void
    {
        $this->authorize('update', $this->site);

        $this->flash_error = null;
        $this->flash_success = null;

        try {
            $result = $executor->run($this->site->fresh(), $action);
            $this->storeRuntimeActionResult($action, $result);
            $this->site->refresh();
            $this->flash_success = match ($action) {
                'rebuild' => __('Runtime rebuilt.'),
                'start' => __('Runtime started.'),
                'stop' => __('Runtime stopped.'),
                'restart' => __('Runtime restarted.'),
                'inspect' => __('Docker details refreshed.'),
                'errors' => __('Runtime errors refreshed.'),
                'logs' => __('Runtime logs refreshed.'),
                'destroy' => __('Runtime destroyed.'),
                default => __('Runtime status refreshed.'),
            };
        } catch (\Throwable $e) {
            $this->storeRuntimeActionFailure($action, $e->getMessage());
            $this->site->refresh();
            $this->flash_error = $e->getMessage();
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
        $this->flash_success = 'Deploy lock cleared. If a worker is still running, stop it on the queue host; otherwise you can deploy again.';
        $this->flash_error = null;
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

        $this->flash_error = null;
        $this->flash_success = null;

        try {
            ExecuteSiteCertificateJob::dispatchSync($certificate->id);
            $this->site->refresh();
            $this->flash_success = __('Certificate retry finished.');
        } catch (\Throwable $e) {
            $this->site->refresh();
            $this->flash_error = $e->getMessage();
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
                $this->flash_error = (string) ($this->functionsDetection['warnings'][0] ?? __('This repository runtime is not supported by the selected target.'));
                $this->flash_success = null;

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

        $this->site->update($updates);
        $this->flash_success = 'Git settings saved.';
        $this->flash_error = null;
        $this->syncFormFromSite();
    }

    public function saveRepositoryWorkspace(): void
    {
        $this->authorize('update', $this->site);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
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
        $this->flash_success = __('Repository settings saved.');
        $this->flash_error = null;
        $this->syncFormFromSite();
    }

    public function enableQuickDeploy(RepositoryWebhookProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot enable Quick deploy.'));

            return;
        }

        $account = $this->git_source_control_account_id !== ''
            ? SocialAccount::query()->where('user_id', auth()->id())->find($this->git_source_control_account_id)
            : null;
        if ($account === null) {
            $this->flash_error = __('Select a connected source control account first.');
            $this->flash_success = null;

            return;
        }

        $result = $provisioner->enable($this->site->fresh(), $account);
        if (! $result['ok']) {
            $this->flash_error = $result['message'];
            $this->flash_success = null;
        } else {
            $this->flash_success = $result['message'];
            $this->flash_error = null;
        }
        $this->syncFormFromSite();
    }

    public function disableQuickDeploy(RepositoryWebhookProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot change Quick deploy.'));

            return;
        }

        $provisioner->disable($this->site->fresh());
        $this->flash_success = __('Quick deploy disabled and provider hook removed when possible.');
        $this->flash_error = null;
        $this->syncFormFromSite();
    }

    public function queueRemoveRemoteRepository(): void
    {
        $this->authorize('update', $this->site);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot remove the repository checkout.'));

            return;
        }

        if ($this->site->usesFunctionsRuntime() || $this->site->usesDockerRuntime() || $this->site->usesKubernetesRuntime()) {
            $this->flash_error = __('This runtime does not use a traditional VM repository path.');

            return;
        }

        RemoveSiteRepositoryJob::dispatch($this->site->id);
        $this->flash_success = __('Repository removal has been queued. This may take a minute on large trees.');
        $this->flash_error = null;
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

        $account = auth()->user()->socialAccounts()->find($value);
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
            $this->flash_error = __('Serverless-backed sites deploy from the configured artifact zip instead of a server-side git checkout.');

            return;
        }

        try {
            [$private, $public] = SiteDeployKeyGenerator::generate();
            $this->site->update([
                'git_deploy_key_private' => $private,
                'git_deploy_key_public' => $public,
            ]);
            $this->flash_success = 'New deploy key generated. Add the public key to your Git host.';
            $this->flash_error = null;
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function regenerateWebhookSecret(RepositoryWebhookProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);
        $plain = Str::random(48);
        $this->site->update(['webhook_secret' => $plain]);
        $this->revealed_webhook_secret = $plain;
        $provisioner->syncProviderHookSecret($this->site->fresh());
        $this->flash_success = 'Webhook secret rotated. Copy it below — it will not be shown again.';
        $this->flash_error = null;
    }

    public function saveEnvDraft(): void
    {
        $this->authorize('update', $this->site);
        $this->validate(['env_file_content' => 'nullable|string|max:65535']);
        $this->site->update(['env_file_content' => $this->env_file_content]);
        $this->flash_success = '.env saved in Dply (not yet on server). Use “Push .env to server” to write the file.';
        $this->flash_error = null;
    }

    public function pushEnvToServer(SiteEnvPusher $pusher): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsEnvPushToHost()) {
            $this->flash_error = __('This host runtime does not support pushing a .env file over SSH.');

            return;
        }

        $this->validate(['env_file_content' => 'nullable|string|max:65535']);
        $this->flash_error = null;
        try {
            $path = $pusher->push($this->site, $this->env_file_content);
            $this->flash_success = '.env written to '.$path;
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
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
            $this->flash_success = $message;
            $this->flash_error = null;

            return;
        }

        $this->flash_error = null;

        if ($this->shouldAutoReapplyManagedWebserverConfig()) {
            try {
                ApplySiteWebserverConfigJob::dispatchSync($this->site->id);
                $this->site->refresh();
                $this->flash_success = $message.' '.__('Webserver config reloaded.');
            } catch (\Throwable $e) {
                $this->flash_success = $message.' '.__('Saved, but the webserver config could not be re-applied automatically.');
                $this->flash_error = $e->getMessage();
            }

            return;
        }

        $this->flash_success = $message.' '.__('Use “Apply webserver config now” on the Routing tab if the document root should match this strategy.');
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
        $this->flash_success = 'Deployment / Nginx settings saved. Re-install Nginx if you changed redirects, Octane, or extra config. Re-sync server crontab for Laravel scheduler. When “Restart Supervisor after deploy” is on, Dply restarts programs for this site (and server-wide programs) after a successful deploy.';
        $this->flash_error = null;
    }

    public function saveEngineHttpCache(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->flash_error = __('Engine HTTP cache is only available for managed VM web server sites on this host.');
            $this->flash_success = null;

            return;
        }

        $this->validate([
            'engine_http_cache_enabled' => ['boolean'],
        ]);

        $this->site->update([
            'engine_http_cache_enabled' => $this->engine_http_cache_enabled,
        ]);
        $this->site->refresh();
        $this->syncFormFromSite();
        $this->flash_error = null;

        try {
            ApplySiteWebserverConfigJob::dispatchSync($this->site->id);
            $this->site->refresh();
            $this->syncFormFromSite();
            $this->flash_success = $this->engine_http_cache_enabled
                ? __('Engine HTTP cache enabled and web server config reloaded.')
                : __('Engine HTTP cache disabled and web server config reloaded.');
        } catch (\Throwable $e) {
            $this->flash_success = __('Setting saved, but the web server config could not be re-applied automatically.');
            $this->flash_error = $e->getMessage();
        }
    }

    public function addEnvironmentVariable(): void
    {
        $this->authorize('update', $this->site);
        $this->validate([
            'new_env_key' => 'required|string|max:128|regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
            'new_env_value' => 'nullable|string|max:20000',
            'new_env_environment' => 'required|string|max:32',
        ]);
        SiteEnvironmentVariable::query()->updateOrCreate(
            [
                'site_id' => $this->site->id,
                'env_key' => $this->new_env_key,
                'environment' => $this->new_env_environment,
            ],
            ['env_value' => $this->new_env_value]
        );
        $this->new_env_key = '';
        $this->new_env_value = '';
        $this->flash_success = 'Environment variable saved.';
        $this->flash_error = null;
    }

    public function deleteEnvironmentVariable(int $id): void
    {
        $this->authorize('update', $this->site);
        SiteEnvironmentVariable::query()->where('site_id', $this->site->id)->whereKey($id)->delete();
        $this->flash_success = 'Variable removed.';
        $this->flash_error = null;
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
        ]);

        $responseHeaders = null;
        if ($this->new_redirect_kind === SiteRedirectKind::Http->value) {
            foreach ($this->new_redirect_header_rows as $i => $row) {
                $n = trim((string) ($row['name'] ?? ''));
                $v = trim((string) ($row['value'] ?? ''));
                if ($n === '' && $v === '') {
                    continue;
                }
                if ($n === '' || $v === '') {
                    throw ValidationException::withMessages([
                        "new_redirect_header_rows.{$i}.name" => [__('Provide both a header name and value, or clear the row.')],
                    ]);
                }
                if (! SiteRedirectConfigSupport::isValidHeaderName($n)) {
                    throw ValidationException::withMessages([
                        "new_redirect_header_rows.{$i}.name" => [__('Use a valid header name (letters, digits, and !#$&-.^_`|~).')],
                    ]);
                }
                if (! SiteRedirectConfigSupport::isValidHeaderValue($v)) {
                    throw ValidationException::withMessages([
                        "new_redirect_header_rows.{$i}.value" => [__('Header value is too long or contains invalid characters.')],
                    ]);
                }
                if (SiteRedirectConfigSupport::isForbiddenResponseHeaderName($n)) {
                    throw ValidationException::withMessages([
                        "new_redirect_header_rows.{$i}.name" => [__('This header cannot be set from a redirect.')],
                    ]);
                }
            }
            $normalized = SiteRedirectConfigSupport::normalizeResponseHeaders($this->new_redirect_header_rows);
            $responseHeaders = $normalized === [] ? null : $normalized;
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
            'sort_order' => (int) ($this->site->redirects()->max('sort_order') ?? 0) + 1,
        ]);
        $this->new_redirect_from = '';
        $this->new_redirect_to = '';
        $this->new_redirect_kind = SiteRedirectKind::Http->value;
        $this->new_redirect_code = 301;
        $this->new_redirect_header_rows = [['name' => '', 'value' => '']];
        $this->finalizeRoutingMutation('Redirect added.');
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

    public function deleteRedirectRule(int $id): void
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
        $this->flash_success = 'Deploy hook added.';
        $this->flash_error = null;
    }

    public function deleteDeployHook(int $id): void
    {
        $this->authorize('update', $this->site);
        SiteDeployHook::query()->where('site_id', $this->site->id)->whereKey($id)->delete();
        $this->flash_success = 'Hook removed.';
        $this->flash_error = null;
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
        $this->flash_success = 'Deploy pipeline step added. Runs after git, before the post-deploy command.';
        $this->flash_error = null;
    }

    public function deleteDeployPipelineStep(int $id): void
    {
        $this->authorize('update', $this->site);
        SiteDeployStep::query()->where('site_id', $this->site->id)->whereKey($id)->delete();
        $this->flash_success = 'Pipeline step removed.';
        $this->flash_error = null;
    }

    public function moveDeployStepUp(int $id): void
    {
        $this->authorize('update', $this->site);
        $ids = SiteDeployStep::query()->where('site_id', $this->site->id)->orderBy('sort_order')->pluck('id')->all();
        $pos = array_search($id, $ids, true);
        if ($pos === false || $pos === 0) {
            return;
        }
        [$ids[$pos - 1], $ids[$pos]] = [$ids[$pos], $ids[$pos - 1]];
        foreach ($ids as $i => $stepId) {
            SiteDeployStep::query()->whereKey($stepId)->update(['sort_order' => $i + 1]);
        }
        $this->flash_success = 'Pipeline order updated.';
        $this->flash_error = null;
    }

    public function moveDeployStepDown(int $id): void
    {
        $this->authorize('update', $this->site);
        $ids = SiteDeployStep::query()->where('site_id', $this->site->id)->orderBy('sort_order')->pluck('id')->all();
        $pos = array_search($id, $ids, true);
        if ($pos === false || $pos >= count($ids) - 1) {
            return;
        }
        [$ids[$pos + 1], $ids[$pos]] = [$ids[$pos], $ids[$pos + 1]];
        foreach ($ids as $i => $stepId) {
            SiteDeployStep::query()->whereKey($stepId)->update(['sort_order' => $i + 1]);
        }
        $this->flash_success = 'Pipeline order updated.';
        $this->flash_error = null;
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
            $this->flash_error = __('This host runtime does not support release rollback via server symlinks.');

            return;
        }

        $this->flash_error = null;
        try {
            $release = SiteRelease::query()->where('site_id', $this->site->id)->findOrFail($releaseId);
            $rollback->rollbackTo($this->site, $release);
            $this->site->refresh();
            $this->flash_success = 'Rolled back active release symlink. Re-install Nginx if document root changed.';
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
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
        ]);
        SiteDomain::query()->create([
            'site_id' => $this->site->id,
            'hostname' => strtolower(trim($this->new_domain_hostname)),
            'is_primary' => false,
            'www_redirect' => false,
        ]);
        $this->new_domain_hostname = '';
        $this->finalizeRoutingMutation('Domain added.');
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
            $this->flash_error = 'Cannot remove the only domain.';

            return;
        }
        if ($domain->hostname === $this->site->testingHostname()) {
            $this->flash_error = 'The generated testing hostname is managed by Dply and cannot be removed here.';

            return;
        }
        if ($domain->is_primary) {
            $this->flash_error = 'Set another domain as primary before removing the primary domain.';

            return;
        }
        $domain->delete();
        $this->finalizeRoutingMutation('Domain removed.');
    }

    public function deleteSite(): mixed
    {
        $this->authorize('delete', $this->site);
        $this->site->delete();

        return $this->redirect(route('servers.show', $this->server), navigate: true);
    }

    public function confirmSuspendSite(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->flash_error = __('Site suspension requires managed web server configuration on this host.');
            $this->flash_success = null;

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
            $this->flash_error = __('Site suspension is not available for this runtime.');

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

        $this->flash_error = null;

        try {
            ApplySiteWebserverConfigJob::dispatchSync($this->site->id);
            $this->site->refresh();
            $this->flash_success = __('Site suspended. Webserver config reloaded.');
        } catch (\Throwable $e) {
            $this->flash_success = __('Suspension saved, but the webserver config could not be re-applied automatically.');
            $this->flash_error = $e->getMessage();
        }
    }

    public function resumeSite(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->shouldAutoReapplyManagedWebserverConfig()) {
            $this->flash_error = __('Resuming requires managed web server configuration on this host.');

            return;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        unset($meta['suspended_message']);

        $this->site->update([
            'suspended_at' => null,
            'suspended_reason' => null,
            'meta' => $meta,
        ]);
        $this->site->refresh();
        $this->settings_suspended_message = '';
        $this->flash_error = null;

        try {
            ApplySiteWebserverConfigJob::dispatchSync($this->site->id);
            $this->site->refresh();
            $this->flash_success = __('Site resumed. Webserver config reloaded.');
        } catch (\Throwable $e) {
            $this->flash_success = __('Resume saved, but the webserver config could not be re-applied automatically.');
            $this->flash_error = $e->getMessage();
        }
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
            'environmentVariables',
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
        $this->linkedSourceControlAccounts = $repositoryBrowser->accountsForUser(auth()->user());

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

        $account = auth()->user()->socialAccounts()->find($this->functions_source_control_account_id);
        $this->availableFunctionsRepositories = $account
            ? $repositoryBrowser->repositoriesForAccount($account)
            : [];
    }
}
