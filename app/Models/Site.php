<?php

namespace App\Models;

use App\Enums\SiteType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;

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
        'name',
        'slug',
        'type',
        'document_root',
        'repository_path',
        'php_version',
        'app_port',
        'status',
        'ssl_status',
        'nginx_installed_at',
        'ssl_installed_at',
        'last_deploy_at',
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
            'nginx_installed_at' => 'datetime',
            'ssl_installed_at' => 'datetime',
            'last_deploy_at' => 'datetime',
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
        return app(\App\Services\Deploy\ServerlessDeploymentConfigResolver::class)
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
