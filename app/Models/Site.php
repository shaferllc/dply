<?php

namespace App\Models;

use App\Enums\SiteType;
use App\Livewire\Sites\Settings;
use App\Services\Deploy\DeploymentSecretInventory;
use App\Services\Deploy\LaravelComposerPackageDetector;
use App\Services\Deploy\ServerlessDeploymentConfigResolver;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Site extends Model
{
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_NGINX_ACTIVE = 'nginx_active';

    public const STATUS_APACHE_ACTIVE = 'apache_active';

    public const STATUS_CADDY_ACTIVE = 'caddy_active';

    public const STATUS_OPENLITESPEED_ACTIVE = 'openlitespeed_active';

    public const STATUS_TRAEFIK_ACTIVE = 'traefik_active';

    public const STATUS_DOCKER_ACTIVE = 'docker_active';

    public const STATUS_DOCKER_CONFIGURED = 'docker_configured';

    public const STATUS_KUBERNETES_ACTIVE = 'kubernetes_active';

    public const STATUS_KUBERNETES_CONFIGURED = 'kubernetes_configured';

    public const STATUS_FUNCTIONS_CONFIGURED = 'functions_configured';

    public const STATUS_FUNCTIONS_ACTIVE = 'functions_active';

    public const STATUS_ERROR = 'error';

    public const SSL_NONE = 'none';

    public const SSL_PENDING = 'pending';

    public const SSL_ACTIVE = 'active';

    public const SSL_FAILED = 'failed';

    protected $fillable = [
        'server_id',
        'user_id',
        'organization_id',
        'workspace_id',
        'dns_provider_credential_id',
        'dns_zone',
        'name',
        'slug',
        'type',
        'document_root',
        'repository_path',
        'php_version',
        'runtime_version',
        'app_port',
        'build_command',
        'status',
        'ssl_status',
        'nginx_installed_at',
        'ssl_installed_at',
        'last_deploy_at',
        'suspended_at',
        'suspended_reason',
        'git_repository_url',
        'git_branch',
        'git_deploy_key_private',
        'git_deploy_key_public',
        'webhook_secret',
        'webhook_allowed_ips',
        'post_deploy_command',
        'deploy_script_id',
        'deploy_strategy',
        'releases_to_keep',
        'nginx_extra_raw',
        'engine_http_cache_enabled',
        'octane_port',
        'laravel_scheduler',
        'restart_supervisor_programs_after_deploy',
        'deployment_environment',
        'php_fpm_user',
        'env_file_content',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'type' => SiteType::class,
            'git_deploy_key_private' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'webhook_allowed_ips' => 'array',
            'env_file_content' => 'encrypted',
            'meta' => 'array',
            'laravel_scheduler' => 'boolean',
            'restart_supervisor_programs_after_deploy' => 'boolean',
            'engine_http_cache_enabled' => 'boolean',
            'nginx_installed_at' => 'datetime',
            'ssl_installed_at' => 'datetime',
            'last_deploy_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Site $site): void {
            if (empty($site->slug)) {
                $site->slug = Str::slug($site->name) ?: 'site';
            }
            if ($site->server_id && ! $site->organization_id) {
                $server = Server::query()->find($site->server_id);
                if ($server) {
                    $site->organization_id = $server->organization_id;
                }
            }
            if ($site->workspace_id === null && $site->server_id) {
                $server = Server::query()->find($site->server_id);
                if ($server?->workspace_id) {
                    $site->workspace_id = $server->workspace_id;
                }
            }
            if ($site->project_id === null) {
                $project = Project::query()->create([
                    'organization_id' => $site->organization_id,
                    'user_id' => $site->user_id,
                    'name' => $site->name ?: 'Site',
                    'slug' => 'tmp-'.Str::lower(Str::random(20)),
                    'kind' => Project::KIND_BYO_SITE,
                ]);
                $site->project_id = $project->id;
            }
        });

        static::created(function (Site $site): void {
            $site->project()->update([
                'slug' => $site->slug.'-'.$site->id,
                'name' => $site->name,
            ]);

            // Every site that runs *something* (i.e. not a pure static host) gets a
            // canonical "web" process row. The row's command is null at create time:
            // PHP-FPM is implicit (the FPM master + per-site pool serve the site, no
            // dedicated process here), and for other runtimes the command is filled in
            // later by runtime detection / dply.yaml / the user.
            if ($site->type !== SiteType::Static) {
                $site->processes()->create([
                    'type' => SiteProcess::TYPE_WEB,
                    'name' => SiteProcess::TYPE_WEB,
                    'command' => null,
                    'scale' => 1,
                    'is_active' => true,
                ]);
            }
        });

        static::deleted(function (Site $site): void {
            if ($site->project_id) {
                Project::query()->whereKey($site->project_id)->delete();
            }
        });
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function webserverConfigProfile(): HasOne
    {
        return $this->hasOne(SiteWebserverConfigProfile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function deployScript(): BelongsTo
    {
        return $this->belongsTo(Script::class, 'deploy_script_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function dnsProviderCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class, 'dns_provider_credential_id');
    }

    /**
     * Provider credential used for DNS automation on this site (preview hostnames, DNS-01 defaults, etc.).
     * Uses the site override when set and DNS-capable; otherwise the latest DNS-capable credential for the organization (any provider).
     */
    public function dnsAutomationCredential(): ?ProviderCredential
    {
        $this->loadMissing('dnsProviderCredential');

        if ($this->dns_provider_credential_id) {
            $explicit = $this->dnsProviderCredential;
            if ($explicit !== null
                && $explicit->organization_id === $this->organization_id
                && $explicit->supportsDnsAutomation()) {
                return $explicit;
            }
        }

        if ($this->organization_id === null) {
            return null;
        }

        return ProviderCredential::query()
            ->where('organization_id', $this->organization_id)
            ->whereIn('provider', ProviderCredential::dnsAutomationProviderKeys())
            ->latest('updated_at')
            ->first();
    }

    /**
     * Naive apex guess from the primary site hostname (last two labels), e.g. app.example.com → example.com.
     */
    public function guessDnsZoneFromPrimaryHostname(): ?string
    {
        $this->loadMissing('domains');
        $host = strtolower(trim((string) optional($this->primaryDomain())->hostname));
        if ($host === '' || ! str_contains($host, '.')) {
            return null;
        }

        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return null;
        }

        return $parts[count($parts) - 2].'.'.$parts[count($parts) - 1];
    }

    public function domains(): HasMany
    {
        return $this->hasMany(SiteDomain::class);
    }

    public function previewDomains(): HasMany
    {
        return $this->hasMany(SitePreviewDomain::class)->orderByDesc('is_primary')->orderBy('hostname');
    }

    public function domainAliases(): HasMany
    {
        return $this->hasMany(SiteDomainAlias::class)->orderBy('sort_order')->orderBy('hostname');
    }

    public function basicAuthUsers(): HasMany
    {
        return $this->hasMany(SiteBasicAuthUser::class)->orderBy('sort_order')->orderBy('username');
    }

    public function uptimeMonitors(): HasMany
    {
        return $this->hasMany(SiteUptimeMonitor::class)->orderBy('sort_order')->orderBy('id');
    }

    public function tenantDomains(): HasMany
    {
        return $this->hasMany(SiteTenantDomain::class)->orderBy('sort_order')->orderBy('hostname');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(SiteCertificate::class)->latest('created_at');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(SiteDeployment::class)->orderByDesc('id');
    }

    public function webhookDeliveryLogs(): HasMany
    {
        return $this->hasMany(WebhookDeliveryLog::class)->orderByDesc('id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(SiteRelease::class)->orderByDesc('id');
    }

    public function processes(): HasMany
    {
        return $this->hasMany(SiteProcess::class)->orderBy('name');
    }

    public function environmentVariables(): HasMany
    {
        return $this->hasMany(SiteEnvironmentVariable::class)->orderBy('env_key');
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(SiteRedirect::class)->orderBy('sort_order');
    }

    public function deployHooks(): HasMany
    {
        return $this->hasMany(SiteDeployHook::class)->orderBy('sort_order');
    }

    public function deploySteps(): HasMany
    {
        return $this->hasMany(SiteDeployStep::class)->orderBy('sort_order');
    }

    public function fileBackups(): HasMany
    {
        return $this->hasMany(SiteFileBackup::class)->orderByDesc('created_at');
    }

    /**
     * Whether this site can export a full filesystem archive over SSH (BYO VM-style hosts only).
     */
    public function supportsSshFileArchive(): bool
    {
        if ($this->usesFunctionsRuntime()
            || $this->usesDockerRuntime()
            || $this->usesKubernetesRuntime()) {
            return false;
        }

        $server = $this->server;

        return $server !== null
            && $server->isReady()
            && $server->hasAnySshPrivateKey();
    }

    public function primaryDomain(): ?SiteDomain
    {
        return $this->domains()->where('is_primary', true)->first()
            ?? $this->domains()->first();
    }

    public function testingHostname(): string
    {
        $previewDomain = $this->primaryPreviewDomain();
        if ($previewDomain) {
            return (string) $previewDomain->hostname;
        }

        $meta = is_array($this->meta) ? $this->meta : [];
        $hostname = $meta['testing_hostname']['hostname'] ?? '';

        return is_string($hostname) ? $hostname : '';
    }

    public function testingHostnameStatus(): ?string
    {
        $previewDomain = $this->primaryPreviewDomain();
        if ($previewDomain) {
            return $previewDomain->dns_status;
        }

        $meta = is_array($this->meta) ? $this->meta : [];
        $status = $meta['testing_hostname']['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    public function primaryPreviewDomain(): ?SitePreviewDomain
    {
        $previewDomains = $this->relationLoaded('previewDomains')
            ? $this->previewDomains
            : $this->previewDomains()->get();

        return $previewDomains->firstWhere('is_primary', true)
            ?? $previewDomains->first();
    }

    public function currentSslSummary(): string
    {
        $certificates = $this->relationLoaded('certificates')
            ? $this->certificates
            : $this->certificates()->get();

        if ($certificates->contains('status', SiteCertificate::STATUS_ACTIVE)) {
            return self::SSL_ACTIVE;
        }

        if ($certificates->contains('status', SiteCertificate::STATUS_PENDING)
            || $certificates->contains('status', SiteCertificate::STATUS_ISSUED)
            || $certificates->contains('status', SiteCertificate::STATUS_INSTALLING)) {
            return self::SSL_PENDING;
        }

        if ($certificates->contains('status', SiteCertificate::STATUS_FAILED)) {
            return self::SSL_FAILED;
        }

        return $this->ssl_status;
    }

    public function webserver(): string
    {
        if ($this->usesFunctionsRuntime()) {
            return 'digitalocean_functions';
        }

        if ($this->usesDockerRuntime()) {
            return 'docker';
        }

        if ($this->usesKubernetesRuntime()) {
            return 'kubernetes';
        }

        $serverMeta = is_array($this->server?->meta) ? $this->server->meta : [];
        $webserver = $serverMeta['webserver'] ?? 'nginx';

        return is_string($webserver) && $webserver !== '' ? $webserver : 'nginx';
    }

    public function provisioningMeta(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $provisioning = $meta['provisioning'] ?? [];

        return is_array($provisioning) ? $provisioning : [];
    }

    /**
     * @return list<array{
     *     at?: string,
     *     level?: string,
     *     step?: string,
     *     message?: string,
     *     context?: array<string, mixed>
     * }>
     */
    public function provisioningLog(): array
    {
        $log = $this->provisioningMeta()['log'] ?? [];

        return collect(is_array($log) ? $log : [])
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->values()
            ->all();
    }

    public function provisioningState(): ?string
    {
        $state = $this->provisioningMeta()['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    public function provisioningError(): ?string
    {
        $error = $this->provisioningMeta()['error'] ?? null;

        return is_string($error) ? $error : null;
    }

    public function provisionedHostname(): ?string
    {
        $hostname = $this->provisioningMeta()['ready_hostname'] ?? null;

        return is_string($hostname) && $hostname !== '' ? $hostname : null;
    }

    public function provisionedUrl(): ?string
    {
        $readyUrl = $this->provisioningMeta()['ready_url'] ?? null;
        if (is_string($readyUrl) && $readyUrl !== '') {
            return $readyUrl;
        }

        $hostname = $this->provisionedHostname();

        return $hostname ? 'http://'.$hostname : null;
    }

    public function visitUrl(): ?string
    {
        if ($this->provisionedUrl() !== null) {
            return $this->provisionedUrl();
        }

        if (! $this->isReadyForTraffic()) {
            return null;
        }

        $hostname = $this->testingHostname();
        if ($hostname !== '') {
            return 'http://'.$hostname;
        }

        return ($this->primaryDomain()?->hostname)
            ? 'http://'.$this->primaryDomain()->hostname
            : null;
    }

    public function isProvisioning(): bool
    {
        return $this->status === self::STATUS_PENDING
            && ! in_array($this->provisioningState(), ['ready', 'failed'], true);
    }

    public function isReadyForTraffic(): bool
    {
        return in_array($this->status, [
            self::STATUS_NGINX_ACTIVE,
            self::STATUS_APACHE_ACTIVE,
            self::STATUS_CADDY_ACTIVE,
            self::STATUS_OPENLITESPEED_ACTIVE,
            self::STATUS_TRAEFIK_ACTIVE,
            self::STATUS_DOCKER_ACTIVE,
            self::STATUS_KUBERNETES_ACTIVE,
            self::STATUS_FUNCTIONS_ACTIVE,
        ], true);
    }

    public function isReadyForWorkspace(): bool
    {
        return $this->isReadyForTraffic()
            || in_array($this->status, [
                self::STATUS_DOCKER_CONFIGURED,
                self::STATUS_KUBERNETES_CONFIGURED,
                self::STATUS_FUNCTIONS_CONFIGURED,
            ], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_NGINX_ACTIVE => 'nginx active',
            self::STATUS_APACHE_ACTIVE => 'apache active',
            self::STATUS_CADDY_ACTIVE => 'caddy active',
            self::STATUS_OPENLITESPEED_ACTIVE => 'openlitespeed active',
            self::STATUS_TRAEFIK_ACTIVE => 'traefik active',
            self::STATUS_DOCKER_CONFIGURED => 'docker configured',
            self::STATUS_DOCKER_ACTIVE => 'docker active',
            self::STATUS_KUBERNETES_CONFIGURED => 'kubernetes configured',
            self::STATUS_KUBERNETES_ACTIVE => 'kubernetes active',
            self::STATUS_FUNCTIONS_CONFIGURED => 'functions configured',
            self::STATUS_FUNCTIONS_ACTIVE => 'functions active',
            default => str_replace('_', ' ', $this->status),
        };
    }

    public static function activeStatusForWebserver(string $webserver): string
    {
        return match ($webserver) {
            'apache' => self::STATUS_APACHE_ACTIVE,
            'caddy' => self::STATUS_CADDY_ACTIVE,
            'openlitespeed' => self::STATUS_OPENLITESPEED_ACTIVE,
            'traefik' => self::STATUS_TRAEFIK_ACTIVE,
            'docker' => self::STATUS_DOCKER_ACTIVE,
            'kubernetes' => self::STATUS_KUBERNETES_ACTIVE,
            'digitalocean_functions' => self::STATUS_FUNCTIONS_ACTIVE,
            default => self::STATUS_NGINX_ACTIVE,
        };
    }

    /**
     * Returns the version of whatever runtime this site uses.
     *
     * Prefers the new `runtime_version` column; falls back to `php_version`
     * for legacy/PHP rows that haven't been re-saved since the column was
     * introduced.
     */
    public function runtimeVersion(): ?string
    {
        $version = $this->runtime_version;
        if (is_string($version) && $version !== '') {
            return $version;
        }

        return $this->php_version;
    }

    public function runtimeProfile(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $profile = $meta['runtime_profile'] ?? null;

        if (is_string($profile) && $profile !== '') {
            return $profile;
        }

        if ($this->server?->isDigitalOceanFunctionsHost()) {
            return 'digitalocean_functions_web';
        }

        if ($this->server?->isAwsLambdaHost()) {
            return 'aws_lambda_bref_web';
        }

        if ($this->server?->isDockerHost()) {
            return 'docker_web';
        }

        if ($this->server?->isKubernetesCluster()) {
            return 'kubernetes_web';
        }

        return 'vm_web';
    }

    public function runtimeProfileLabel(): string
    {
        return match ($this->runtimeProfile()) {
            'docker_web' => __('Docker'),
            'kubernetes_web' => __('Kubernetes'),
            'digitalocean_functions_web' => __('DigitalOcean Functions'),
            'aws_lambda_bref_web' => __('AWS Lambda'),
            'vm_web' => __('BYO VM'),
            default => (string) str($this->runtimeProfile())->replace('_', ' ')->title(),
        };
    }

    public function runtimeExecutionModeLabel(): string
    {
        return match ($this->runtimeTargetMode()) {
            'docker' => __('Container'),
            'kubernetes' => __('Kubernetes'),
            'serverless' => __('Serverless'),
            default => __('VM'),
        };
    }

    /**
     * App/stack detection from persisted meta. Priority matches
     * {@see DeploymentSecretInventory::detectedFramework}:
     * Docker → Kubernetes → serverless → VM (composer.json on deploy).
     *
     * @return array{
     *     source: 'docker'|'kubernetes'|'serverless'|'vm',
     *     framework: string,
     *     language: string,
     *     confidence?: string,
     *     warnings?: list<string>,
     *     detected_files?: list<string>,
     *     laravel_octane?: bool,
     *     laravel_horizon?: bool,
     *     laravel_pulse?: bool,
     *     laravel_reverb?: bool
     * }|null
     */
    public function resolvedRuntimeAppDetection(): ?array
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        $candidates = [
            ['source' => 'docker', 'blob' => data_get($meta, 'docker_runtime.detected')],
            ['source' => 'kubernetes', 'blob' => data_get($meta, 'kubernetes_runtime.detected')],
            ['source' => 'serverless', 'blob' => data_get($meta, 'serverless.detected_runtime') ?: data_get($meta, 'serverless.detected')],
            ['source' => 'vm', 'blob' => data_get($meta, 'vm_runtime.detected')],
        ];

        foreach ($candidates as $candidate) {
            $blob = $candidate['blob'];
            if (! is_array($blob) || $blob === []) {
                continue;
            }

            if (! $this->runtimeAppDetectionIsMeaningful($blob)) {
                continue;
            }

            $out = [
                'source' => $candidate['source'],
                'framework' => (string) ($blob['framework'] ?? 'unknown'),
                'language' => (string) ($blob['language'] ?? 'unknown'),
            ];

            if (isset($blob['confidence']) && is_string($blob['confidence']) && $blob['confidence'] !== '') {
                $out['confidence'] = $blob['confidence'];
            }

            if (isset($blob['warnings']) && is_array($blob['warnings'])) {
                $warnings = array_values(array_filter($blob['warnings'], static fn ($w) => is_string($w) && $w !== ''));
                if ($warnings !== []) {
                    $out['warnings'] = $warnings;
                }
            }

            if (isset($blob['detected_files']) && is_array($blob['detected_files'])) {
                $files = array_values(array_filter($blob['detected_files'], static fn ($f) => is_string($f) && $f !== ''));
                if ($files !== []) {
                    $out['detected_files'] = $files;
                }
            }

            foreach (['laravel_octane', 'laravel_horizon', 'laravel_pulse', 'laravel_reverb'] as $laravelPkgKey) {
                if (! empty($blob[$laravelPkgKey])) {
                    $out[$laravelPkgKey] = true;
                }
            }

            return $out;
        }

        return null;
    }

    public function isLaravelFrameworkDetected(): bool
    {
        return strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? '')) === 'laravel';
    }

    /**
     * @param  array<string, mixed>  $blob
     */
    private function runtimeAppDetectionIsMeaningful(array $blob): bool
    {
        $fw = strtolower(trim((string) ($blob['framework'] ?? '')));
        $lang = strtolower(trim((string) ($blob['language'] ?? '')));

        if ($fw !== '' && $fw !== 'unknown') {
            return true;
        }

        return $lang !== '' && $lang !== 'unknown';
    }

    public function usesFunctionsRuntime(): bool
    {
        return in_array($this->runtimeProfile(), [
            'digitalocean_functions_web',
            'aws_lambda_bref_web',
        ], true);
    }

    public function usesAwsLambdaRuntime(): bool
    {
        return $this->runtimeProfile() === 'aws_lambda_bref_web';
    }

    public function usesDockerRuntime(): bool
    {
        return $this->runtimeProfile() === 'docker_web';
    }

    public function usesKubernetesRuntime(): bool
    {
        return $this->runtimeProfile() === 'kubernetes_web';
    }

    /**
     * PHP / Laravel / Symfony rollout fields (Octane, PHP-FPM, scheduler, etc.).
     * Matches {@see Settings::shouldShowRuntimePhpRolloutFields()}.
     */
    public function shouldShowPhpOctaneRolloutSettings(): bool
    {
        $this->loadMissing('server');
        if ($this->server?->hostCapabilities()->supportsFunctionDeploy()) {
            return false;
        }

        $resolved = $this->resolvedRuntimeAppDetection();
        $fw = strtolower((string) ($resolved['framework'] ?? ''));

        return $this->type === SiteType::Php
            || in_array($fw, ['laravel', 'php_generic', 'symfony'], true);
    }

    /**
     * Heading for the Runtime "PHP process" block — only includes "Laravel" when Laravel is the detected framework.
     */
    public function runtimePhpProcessSectionTitle(): string
    {
        $fw = strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? ''));

        return match ($fw) {
            'laravel' => __('PHP process & Laravel'),
            'symfony' => __('PHP process & Symfony'),
            'wordpress' => __('PHP process'),
            'php_generic' => __('PHP process'),
            '' => __('PHP process'),
            default => __('PHP process (:stack)', ['stack' => str($fw)->replace('_', ' ')->title()]),
        };
    }

    /**
     * Label for the per-minute cron / scheduler checkbox (word "Laravel" only when detection says Laravel).
     */
    public function runtimeSchedulerCheckboxLabel(): string
    {
        $fw = strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? ''));

        return $fw === 'laravel'
            ? __('Laravel scheduler (cron)')
            : __('Per-minute cron task');
    }

    /**
     * Helper text when Laravel is not the detected framework but the cron option is still shown (PHP site).
     */
    public function runtimeSchedulerCheckboxHelp(): ?string
    {
        $fw = strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? ''));

        return $fw === 'laravel'
            ? null
            : __('Adds `php artisan schedule:run` each minute. Enable only for Laravel apps that use the scheduler; leave off for Symfony, WordPress, and other stacks.');
    }

    /**
     * Full single-line label for the scheduler checkbox on Deploy → Rollout (includes schedule:run hint for Laravel).
     */
    public function runtimeSchedulerRolloutFormLabel(): string
    {
        $fw = strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? ''));

        return $fw === 'laravel'
            ? __('Laravel scheduler (schedule:run every minute via server crontab)')
            : __('Per-minute cron task (via server crontab)');
    }

    /**
     * Whether one-shot Laravel SSH setup from Site settings is allowed (BYO VM, SSH ready, Laravel detected).
     */
    public function canRunLaravelSshSetupActions(): bool
    {
        $this->loadMissing('server');
        $server = $this->server;
        if ($server === null || ! $server->isVmHost() || ! $server->isReady()) {
            return false;
        }

        if (trim((string) $server->ssh_private_key) === '') {
            return false;
        }

        if ($server->hostCapabilities()->supportsFunctionDeploy()) {
            return false;
        }

        $resolved = $this->resolvedRuntimeAppDetection();
        if ($resolved === null || strtolower((string) ($resolved['framework'] ?? '')) !== 'laravel') {
            return false;
        }

        if ($this->type !== SiteType::Php) {
            return false;
        }

        if (trim($this->effectiveEnvDirectory()) === '') {
            return false;
        }

        return true;
    }

    /**
     * Rails-specific fields (e.g. RAILS_ENV in meta).
     * Matches {@see Settings::shouldShowRailsRuntimeFields()}.
     */
    public function shouldShowRailsRuntimeSettings(): bool
    {
        $this->loadMissing('server');
        if ($this->server?->hostCapabilities()->supportsFunctionDeploy()) {
            return false;
        }

        $resolved = $this->resolvedRuntimeAppDetection();
        $fw = strtolower((string) ($resolved['framework'] ?? ''));

        return $fw === 'rails';
    }

    /**
     * Laravel Octane application server choices (see `php artisan octane:start --help`).
     *
     * @var list<string>
     */
    public const OCTANE_SERVERS = ['swoole', 'roadrunner', 'frankenphp'];

    public function octaneServer(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $lo = is_array($meta['laravel_octane'] ?? null) ? $meta['laravel_octane'] : [];
        $s = strtolower((string) ($lo['server'] ?? 'swoole'));

        return in_array($s, self::OCTANE_SERVERS, true) ? $s : 'swoole';
    }

    /**
     * @return list<string>
     */
    public function detectedLaravelPackageKeys(): array
    {
        $resolved = $this->resolvedRuntimeAppDetection();
        if ($resolved === null || strtolower((string) ($resolved['framework'] ?? '')) !== 'laravel') {
            return [];
        }

        $keys = [];
        foreach (LaravelComposerPackageDetector::PACKAGE_KEYS as $short => $_) {
            $blobKey = 'laravel_'.$short;
            if (($resolved[$blobKey] ?? false) === true) {
                $keys[] = $short;
            }
        }

        return $keys;
    }

    public function resolvedLaravelPackageFlag(string $short): bool
    {
        $resolved = $this->resolvedRuntimeAppDetection();
        if ($resolved === null || strtolower((string) ($resolved['framework'] ?? '')) !== 'laravel') {
            return false;
        }

        if (! array_key_exists($short, LaravelComposerPackageDetector::PACKAGE_KEYS)) {
            return false;
        }

        $blobKey = 'laravel_'.$short;

        return ($resolved[$blobKey] ?? false) === true;
    }

    /**
     * Octane settings UI when repository inspection found Laravel and a laravel/octane Composer dependency.
     */
    public function shouldShowOctaneRuntimeUi(): bool
    {
        return $this->resolvedLaravelPackageFlag('octane');
    }

    /**
     * Local port for Reverb WebSocket server (Supervisor / reverse proxy); stored in meta.laravel_reverb.port.
     */
    public function reverbLocalPort(): int
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $r = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
        $p = $r['port'] ?? 8080;

        return is_numeric($p) ? max(1, min(65535, (int) $p)) : 8080;
    }

    public function shouldShowLaravelReverbRuntimeUi(): bool
    {
        return $this->resolvedLaravelPackageFlag('reverb');
    }

    /**
     * Include Nginx/Caddy/Apache Reverb WebSocket proxy when Reverb is detected or port was saved in meta.
     */
    public function shouldProxyReverbInWebserver(): bool
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $hasSavedPort = is_array($meta['laravel_reverb'] ?? null)
            && array_key_exists('port', $meta['laravel_reverb']);

        return $this->resolvedLaravelPackageFlag('reverb') || $hasSavedPort;
    }

    /**
     * URL path prefix for Laravel Echo + Reverb (default /app).
     */
    public function reverbWebSocketPath(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $r = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
        $p = trim((string) ($r['ws_path'] ?? '/app'));
        if ($p === '' || $p[0] !== '/') {
            return '/app';
        }
        if (! preg_match('#^/[a-zA-Z0-9/_\-]+$#', $p)) {
            return '/app';
        }

        return rtrim($p, '/') === '' ? '/app' : rtrim($p, '/');
    }

    public function horizonDashboardPath(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $h = is_array($meta['laravel_horizon'] ?? null) ? $meta['laravel_horizon'] : [];
        $p = trim((string) ($h['path'] ?? '/horizon'));
        if ($p === '' || $p[0] !== '/') {
            return '/horizon';
        }

        return preg_match('#^/[a-zA-Z0-9/_\-]+$#', $p) ? rtrim($p, '/') ?: '/horizon' : '/horizon';
    }

    public function pulseDashboardPath(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $h = is_array($meta['laravel_pulse'] ?? null) ? $meta['laravel_pulse'] : [];
        $p = trim((string) ($h['path'] ?? '/pulse'));
        if ($p === '' || $p[0] !== '/') {
            return '/pulse';
        }

        return preg_match('#^/[a-zA-Z0-9/_\-]+$#', $p) ? rtrim($p, '/') ?: '/pulse' : '/pulse';
    }

    /**
     * Suggested Supervisor command for Reverb; optional port override for form preview before save.
     */
    public function reverbSupervisorCommandLine(?int $portOverride = null): string
    {
        $p = $portOverride ?? $this->reverbLocalPort();

        return sprintf('php artisan reverb:start --host=0.0.0.0 --port=%d', max(1, min(65535, $p)));
    }

    /**
     * Supervisor command line for Octane (run with `directory` = deploy root, e.g. current release).
     */
    public function octaneSupervisorCommand(): string
    {
        $port = $this->octane_port;
        if ($port === null || $port < 1) {
            $port = 8000;
        }

        return sprintf(
            'php artisan octane:start --server=%s --host=127.0.0.1 --port=%d',
            $this->octaneServer(),
            (int) $port
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeTarget(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $target = $meta['runtime_target'] ?? null;

        if (is_array($target) && ($target['family'] ?? null)) {
            return $target;
        }

        return [
            'family' => $this->runtimeTargetFamily(),
            'platform' => $this->runtimeTargetPlatform(),
            'mode' => $this->runtimeTargetMode(),
            'provider' => $this->runtimeTargetProvider(),
            'status' => null,
            'logs' => [],
        ];
    }

    public function runtimeTargetFamily(): string
    {
        $target = is_array($this->meta['runtime_target'] ?? null) ? $this->meta['runtime_target'] : [];
        $family = $target['family'] ?? null;
        if (is_string($family) && $family !== '') {
            return $family;
        }

        if ($this->usesDockerRuntime()) {
            return match (true) {
                data_get($this->server?->meta, 'local_runtime.provider') === 'orbstack' => 'local_orbstack_docker',
                $this->server?->provider?->value === 'digitalocean' => 'digitalocean_docker',
                $this->server?->provider?->value === 'aws' => 'aws_docker',
                default => 'docker',
            };
        }

        if ($this->usesKubernetesRuntime()) {
            return match (true) {
                data_get($this->server?->meta, 'local_runtime.provider') === 'orbstack' => 'local_orbstack_kubernetes',
                $this->server?->provider?->value === 'digitalocean' => 'digitalocean_kubernetes',
                $this->server?->provider?->value === 'aws' => 'aws_kubernetes',
                default => 'kubernetes',
            };
        }

        if ($this->usesAwsLambdaRuntime()) {
            return 'aws_lambda';
        }

        if ($this->usesFunctionsRuntime()) {
            return 'digitalocean_functions';
        }

        return 'byo_vm';
    }

    public function runtimeTargetPlatform(): string
    {
        return match ($this->runtimeTargetFamily()) {
            'local_orbstack_docker', 'local_orbstack_kubernetes' => 'local',
            'digitalocean_docker', 'digitalocean_kubernetes', 'digitalocean_functions' => 'digitalocean',
            'aws_docker', 'aws_kubernetes', 'aws_lambda' => 'aws',
            default => 'byo',
        };
    }

    public function runtimeTargetProvider(): string
    {
        return match ($this->runtimeTargetPlatform()) {
            'local' => 'orbstack',
            'digitalocean' => 'digitalocean',
            'aws' => 'aws',
            default => 'byo',
        };
    }

    public function runtimeTargetMode(): string
    {
        return match ($this->runtimeTargetFamily()) {
            'local_orbstack_kubernetes', 'digitalocean_kubernetes', 'aws_kubernetes', 'kubernetes' => 'kubernetes',
            'local_orbstack_docker', 'digitalocean_docker', 'aws_docker', 'docker' => 'docker',
            'digitalocean_functions', 'aws_lambda' => 'serverless',
            default => 'vm',
        };
    }

    public function usesLocalDockerHostRuntime(): bool
    {
        return in_array($this->runtimeTargetFamily(), [
            'local_orbstack_docker',
            'local_orbstack_kubernetes',
        ], true);
    }

    public function runtimeTargetLabel(): string
    {
        return match ($this->runtimeTargetFamily()) {
            'local_orbstack_docker' => 'Local Docker',
            'local_orbstack_kubernetes' => 'Local Kubernetes',
            'digitalocean_docker' => 'DigitalOcean Docker',
            'digitalocean_kubernetes' => 'DigitalOcean Kubernetes',
            'aws_docker' => 'AWS Docker',
            'aws_kubernetes' => 'AWS Kubernetes',
            'digitalocean_functions' => 'DigitalOcean Functions',
            'aws_lambda' => 'AWS Lambda',
            default => 'BYO runtime',
        };
    }

    public function runtimeRepositorySubdirectory(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $subdirectory = data_get($meta, 'runtime_target.repository_subdirectory');

        if (! is_string($subdirectory) || trim($subdirectory) === '') {
            $subdirectory = $this->usesKubernetesRuntime()
                ? data_get($meta, 'kubernetes_runtime.repository_subdirectory')
                : data_get($meta, 'docker_runtime.repository_subdirectory');
        }

        return is_string($subdirectory) ? trim($subdirectory, '/') : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function functionsConfig(): array
    {
        return $this->serverlessConfig();
    }

    /**
     * @return array<string, mixed>
     */
    public function serverlessConfig(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $config = $meta['serverless'] ?? $meta['digitalocean_functions'] ?? [];

        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function serverlessResolvedConfig(): array
    {
        return app(ServerlessDeploymentConfigResolver::class)
            ->resolve($this);
    }

    public function sslDomainHostnames(): Collection
    {
        $previewDomains = $this->relationLoaded('previewDomains')
            ? $this->previewDomains
            : $this->previewDomains()->get();
        $primaryPreviewHostname = $previewDomains->firstWhere('is_primary', true)?->hostname
            ?? $previewDomains->first()?->hostname;
        if (is_string($primaryPreviewHostname) && $primaryPreviewHostname !== '') {
            return collect([$primaryPreviewHostname]);
        }

        $domains = $this->domains instanceof Collection
            ? $this->domains
            : $this->domains()->get();

        $testingHostname = $this->testingHostname();
        if ($testingHostname !== '' && $domains->contains('hostname', $testingHostname)) {
            return collect([$testingHostname]);
        }

        return $domains->pluck('hostname')->filter()->unique()->values();
    }

    /**
     * @return list<string>
     */
    public function customerDomainHostnames(): array
    {
        $domains = $this->domains instanceof Collection
            ? $this->domains
            : $this->domains()->get();

        return $domains->pluck('hostname')
            ->filter(fn (mixed $hostname): bool => is_string($hostname) && trim($hostname) !== '')
            ->map(fn (string $hostname): string => strtolower(trim($hostname)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function aliasHostnames(): array
    {
        $aliases = $this->relationLoaded('domainAliases')
            ? $this->domainAliases
            : $this->domainAliases()->get();

        return $aliases->pluck('hostname')
            ->filter(fn (mixed $hostname): bool => is_string($hostname) && trim($hostname) !== '')
            ->map(fn (string $hostname): string => strtolower(trim($hostname)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Customer domains plus domain aliases for automatic customer-scope certificate issuance (e.g. bulk “issue SSL”).
     *
     * @return list<string>
     */
    public function sslIssuanceHostnames(): array
    {
        return collect($this->customerDomainHostnames())
            ->merge($this->aliasHostnames())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function tenantHostnames(): array
    {
        $tenantDomains = $this->relationLoaded('tenantDomains')
            ? $this->tenantDomains
            : $this->tenantDomains()->get();

        return $tenantDomains->pluck('hostname')
            ->filter(fn (mixed $hostname): bool => is_string($hostname) && trim($hostname) !== '')
            ->map(fn (string $hostname): string => strtolower(trim($hostname)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function webserverHostnames(): array
    {
        return collect([
            ...$this->customerDomainHostnames(),
            ...$this->aliasHostnames(),
            ...$this->tenantHostnames(),
        ])->unique()->values()->all();
    }

    public function effectiveRepositoryPath(): string
    {
        $path = $this->repository_path;

        return $path !== null && $path !== '' ? $path : $this->document_root;
    }

    /**
     * On-host directory for Dply-managed htpasswd files (under the site repo root, not web-served).
     */
    public function basicAuthStorageDirectoryOnHost(): string
    {
        return rtrim($this->effectiveRepositoryPath(), '/').'/.dply/basic-auth';
    }

    /**
     * Absolute path for {@see auth_basic_user_file} / Apache {@see AuthUserFile} for a normalized path group.
     */
    public function basicAuthHtpasswdPathForNormalizedPath(string $normalizedPath): string
    {
        $key = SiteBasicAuthUser::normalizePath($normalizedPath);
        $hash = substr(hash('sha256', $key), 0, 16);

        return $this->basicAuthStorageDirectoryOnHost().'/group-'.$hash.'.htpasswd';
    }

    public function supportsBasicAuthProvisioning(): bool
    {
        if ($this->usesFunctionsRuntime() || $this->usesDockerRuntime() || $this->usesKubernetesRuntime()) {
            return false;
        }

        $server = $this->server;

        return $server !== null && $server->hostCapabilities()->supportsNginxProvisioning();
    }

    /**
     * Path-prefix basic auth (e.g. /wp-admin) is emitted for static and non-Octane PHP nginx configs.
     */
    public function basicAuthSupportsPathPrefixes(): bool
    {
        if ($this->type === SiteType::Static) {
            return true;
        }

        return $this->type === SiteType::Php && ! $this->octane_port;
    }

    /**
     * Linux account for this site's files / PHP-FPM: explicit {@see $php_fpm_user} or the server's deploy SSH user.
     */
    public function effectiveSystemUser(Server $server): string
    {
        $explicit = trim((string) ($this->php_fpm_user ?? ''));

        if ($explicit !== '') {
            return $explicit;
        }

        return trim((string) ($server->ssh_user ?? ''));
    }

    public function isAtomicDeploys(): bool
    {
        return $this->deploy_strategy === 'atomic';
    }

    /**
     * Web root for Nginx (atomic → …/current/public).
     */
    public function effectiveDocumentRootForNginx(): string
    {
        if ($this->isAtomicDeploys()) {
            return rtrim($this->effectiveRepositoryPath(), '/').'/current/public';
        }

        return rtrim($this->document_root, '/');
    }

    public function effectiveDocumentRoot(): string
    {
        return $this->effectiveDocumentRootForNginx();
    }

    /**
     * Directory that receives .env (project root).
     */
    public function effectiveEnvDirectory(): string
    {
        if ($this->isAtomicDeploys()) {
            return rtrim($this->effectiveRepositoryPath(), '/').'/current';
        }

        return rtrim($this->effectiveRepositoryPath(), '/');
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Managed VM vhosts may emit engine-level HTTP cache directives (e.g. nginx FastCGI / proxy_cache).
     */
    public function wantsEngineHttpCache(): bool
    {
        if (! $this->engine_http_cache_enabled) {
            return false;
        }

        if ($this->isSuspended()) {
            return false;
        }

        return ! $this->usesFunctionsRuntime()
            && ! $this->usesDockerRuntime()
            && ! $this->usesKubernetesRuntime();
    }

    /**
     * Static web root for the suspended HTML page (outside public/).
     */
    public function suspendedStaticRoot(): string
    {
        return rtrim($this->effectiveEnvDirectory(), '/').'/.dply/suspended';
    }

    /**
     * Optional text shown on the public suspended HTML page (escaped when rendered).
     * Prefers {@see Site::$meta} `suspended_message`, then legacy {@see Site::$suspended_reason}.
     */
    public function suspendedPublicMessage(): string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $fromMeta = trim((string) ($meta['suspended_message'] ?? ''));

        if ($fromMeta !== '') {
            return $fromMeta;
        }

        return trim((string) ($this->suspended_reason ?? ''));
    }

    public function nginxConfigBasename(): string
    {
        return 'dply-'.$this->id.'-'.$this->slug;
    }

    public function webserverConfigBasename(): string
    {
        return $this->nginxConfigBasename();
    }

    public function webserverLogDirectory(): string
    {
        return match ($this->webserver()) {
            'apache' => '/var/log/apache2',
            'caddy' => '/var/log/caddy',
            'openlitespeed' => '/var/log/lshttpd',
            'traefik' => '/var/log/caddy',
            default => '/var/log/nginx',
        };
    }

    public function deployHookUrl(): string
    {
        return route('hooks.site.deploy', ['site' => $this->id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function repositoryMeta(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        return is_array($meta['repository'] ?? null) ? $meta['repository'] : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function mergeRepositoryMeta(array $patch): void
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $current = is_array($meta['repository'] ?? null) ? $meta['repository'] : [];
        $meta['repository'] = array_merge($current, $patch);
        $this->meta = $meta;
    }

    public function deploySyncGroups(): BelongsToMany
    {
        return $this->belongsToMany(SiteDeploySyncGroup::class, 'site_deploy_sync_group_sites', 'site_id', 'site_deploy_sync_group_id')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function notificationSubscriptions(): MorphMany
    {
        return $this->morphMany(NotificationSubscription::class, 'subscribable');
    }

    public function insightSetting(): MorphOne
    {
        return $this->morphOne(InsightSetting::class, 'settingsable');
    }

    public function insightFindings(): HasMany
    {
        return $this->hasMany(InsightFinding::class)->orderByDesc('detected_at');
    }

    public function ensureUniqueSlug(): void
    {
        $base = $this->slug;
        $i = 1;
        while ($this->slugTaken()) {
            $this->slug = $base.'-'.$i;
            $i++;
        }
    }

    protected function slugTaken(): bool
    {
        $q = static::query()
            ->where('server_id', $this->server_id)
            ->where('slug', $this->slug);
        if ($this->exists) {
            $q->whereKeyNot($this->getKey());
        }

        return $q->exists();
    }
}
