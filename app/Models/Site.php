<?php

namespace App\Models;

use App\Enums\SiteType;
use App\Jobs\CleanupCustomSiteJob;
use App\Livewire\Sites\Settings;
use App\Services\Deploy\DeploymentSecretInventory;
use App\Services\Deploy\LaravelComposerPackageDetector;
use App\Services\Deploy\RuntimeDetection\PhpRuntimeDetector;
use App\Services\Deploy\ServerlessDeploymentConfigResolver;
use App\Services\Scaffold\PlaceholderDnsManager;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
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

    public const STATUS_CONTAINER_PROVISIONING = 'container_provisioning';

    public const STATUS_CONTAINER_ACTIVE = 'container_active';

    public const STATUS_CONTAINER_FAILED = 'container_failed';

    public const STATUS_EDGE_PROVISIONING = 'edge_provisioning';

    public const STATUS_EDGE_ACTIVE = 'edge_active';

    public const STATUS_EDGE_FAILED = 'edge_failed';

    /**
     * Serverless function resource limits. These map onto the OpenWhisk
     * action `limits` block DigitalOcean Functions is built on, and are
     * applied to the action on the next deploy.
     *
     * @var array<int, int>
     */
    public const SERVERLESS_MEMORY_OPTIONS_MB = [128, 256, 512, 1024];

    public const SERVERLESS_DEFAULT_MEMORY_MB = 512;

    public const SERVERLESS_DEFAULT_TIMEOUT_MS = 60000;

    public const SERVERLESS_MIN_TIMEOUT_MS = 1000;

    public const SERVERLESS_MAX_TIMEOUT_MS = 900000;

    public const SERVERLESS_DEFAULT_CONCURRENCY = 1;

    public const SERVERLESS_MAX_CONCURRENCY = 50;

    /**
     * Site row exists, scaffold pipeline (PR 5/6) is in flight.
     * Distinct from container_provisioning so Container vs Scaffold
     * journeys don't share states or audit shapes.
     */
    public const STATUS_SCAFFOLDING = 'scaffolding';

    public const STATUS_SCAFFOLD_FAILED = 'scaffold_failed';

    public const STATUS_CUSTOM_ACTIVE = 'custom_active';

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
        'runtime',
        'runtime_version',
        'database_engine',
        'app_port',
        'internal_port',
        'build_command',
        'start_command',
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
        'env_synced_at',
        'env_cache_origin',
        'env_file_path',
        'container_image',
        'container_registry',
        'container_port',
        'container_backend',
        'container_backend_id',
        'container_region',
        'edge_backend',
        'edge_backend_id',
        'edge_provider_credential_id',
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
            'env_synced_at' => 'datetime',
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
        // Keep the legacy `engine_http_cache_enabled` column in sync with
        // `meta['caching']` so existing direct-column readers (5 in
        // NginxSiteConfigBuilder / SiteNginxProvisioner) keep working until
        // the column is dropped in a follow-up release. The reverse mapping
        // lives in {@see Site::cachingConfig()}, which falls back to the
        // boolean when `meta['caching']` is absent.
        static::saving(function (Site $site): void {
            $meta = is_array($site->meta) ? $site->meta : [];
            if (! isset($meta['caching']) || ! is_array($meta['caching'])) {
                return;
            }
            $enabled = (bool) ($meta['caching']['enabled'] ?? false);
            $methods = $meta['caching']['methods'] ?? [];
            $hasNginxHttp = is_array($methods) && in_array('nginx_http', $methods, true);
            $site->engine_http_cache_enabled = $enabled && $hasNginxHttp;
        });

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
            if ($site->project_id === null && $site->organization_id && $site->user_id) {
                // Auto-create a BYO-site Project only when we can satisfy its NOT NULL
                // owners (organization_id + user_id). In-memory test fixtures and other
                // edge cases that lack those skip the auto-creation rather than
                // crashing the host save() — the operator can attach a project later.
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
            if ($site->project_id !== null) {
                // The `creating` hook only auto-attaches a Project when org_id + user_id
                // are present; without a project we skip the rename pass rather than firing
                // an UPDATE against zero rows. Sites without a project attach one later.
                $site->project()->update([
                    'slug' => $site->slug.'-'.$site->id,
                    'name' => $site->name,
                ]);
            }

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

        static::deleting(function (Site $site): void {
            // Dispatch on-server cleanup for Custom (headless) sites
            // before the row vanishes. We capture the resolved values
            // here because effectiveSystemUser() needs the server, and
            // we don't want the job racing on a stale lookup.
            if ($site->isCustom() && $site->server_id !== null) {
                try {
                    $server = $site->server;
                    if ($server) {
                        CleanupCustomSiteJob::dispatch(
                            (string) $server->id,
                            (string) ($site->repository_path ?? ''),
                            $site->effectiveSystemUser($server),
                            trim((string) $site->php_fpm_user) !== '',
                            $site->deploy_script_id ? (string) $site->deploy_script_id : null,
                        );
                    }
                } catch (\Throwable $e) {
                    Log::warning('Custom site cleanup dispatch failed', [
                        'site_id' => $site->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Release any placeholder DNS record + ondply.io zone entry
            // assigned to this site by the scaffold pipeline. release()
            // is idempotent + safe to call on non-scaffolded sites
            // (it short-circuits when meta.scaffold.placeholder_dns is
            // absent), so it runs unconditionally on every site delete.
            try {
                app(PlaceholderDnsManager::class)->release($site);
            } catch (\Throwable $e) {
                // Best-effort cleanup. We do NOT want a transient DNS
                // provider failure to block deletion of the site row;
                // any orphaned record is recoverable via the manager's
                // audit trail.
                Log::warning('Site::deleting placeholder release failed', [
                    'site_id' => $site->getKey(),
                    'error' => $e->getMessage(),
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

    public function edgeProviderCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class, 'edge_provider_credential_id');
    }

    public function usesOrgCloudflareEdge(): bool
    {
        return $this->edge_backend === 'org_cloudflare';
    }

    public function edgeBackendLabel(): string
    {
        return match ($this->edge_backend) {
            'org_cloudflare' => __('Your Cloudflare account'),
            'dply_edge' => __('Dply Edge (managed)'),
            default => (string) ($this->edge_backend ?: __('Unknown')),
        };
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

        return self::apexGuessForHostname($host);
    }

    /**
     * Apex extraction helper for an arbitrary hostname — same rule as
     * {@see guessDnsZoneFromPrimaryHostname()} but doesn't read from the
     * site's current domain. Used by the rename-cascade planner to decide
     * whether the saved `dns_zone` was the operator's choice or matched
     * what dply would have auto-suggested from the *old* hostname.
     */
    public static function apexGuessForHostname(string $hostname): ?string
    {
        $host = strtolower(trim($hostname));
        if ($host === '' || ! str_contains($host, '.')) {
            return null;
        }

        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return null;
        }

        return $parts[count($parts) - 2].'.'.$parts[count($parts) - 1];
    }

    /**
     * True when the saved `dns_zone` equals the apex dply would auto-guess
     * from the supplied hostname. Treats an empty saved zone as "no operator
     * value" — also auto-derived for cascade purposes.
     */
    public function dnsZoneMatchesAutoGuessForHostname(string $hostname): bool
    {
        $saved = strtolower(trim((string) ($this->dns_zone ?? '')));
        if ($saved === '') {
            return true;
        }

        $guess = self::apexGuessForHostname($hostname);

        return $guess !== null && strtolower($guess) === $saved;
    }

    public function domains(): HasMany
    {
        return $this->hasMany(SiteDomain::class);
    }

    /**
     * The OpenWhisk actions on this serverless function-Site. A Site is an
     * OpenWhisk package: one `kind=code` action for a plain function, more
     * once the package model lands. Code actions sort before sequences.
     */
    public function functionActions(): HasMany
    {
        return $this->hasMany(FunctionAction::class)
            ->orderByRaw("CASE WHEN kind = 'code' THEN 0 ELSE 1 END")
            ->orderBy('name');
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

    /**
     * Subset of {@see basicAuthUsers()} that the webserver should actually
     * enforce: managed (Dply wrote the htpasswd) AND not pending-removal
     * (the next apply will drop them). Both the nginx config builder and the
     * htpasswd-sync helper must use this same subset — otherwise the config
     * can reference an htpasswd file the sync just deleted, locking everyone
     * out with a 500 from nginx.
     *
     * @return Collection<int, SiteBasicAuthUser>
     */
    public function enforceableBasicAuthUsers(): Collection
    {
        $this->loadMissing('basicAuthUsers');

        return $this->basicAuthUsers->reject(
            fn (SiteBasicAuthUser $u): bool => $u->isPendingRemoval() || $u->isDiscoveredFromServer()
        )->values();
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

    /**
     * Convenience accessor for the most recent SiteDeployment by start
     * time. Used by dashboard headers and "latest deploy" badges so
     * callers don't have to repeatedly write the orderBy + limit.
     */
    public function latestDeployment(): ?SiteDeployment
    {
        // The deployments() relation pre-orders by id desc; reorder()
        // resets so started_at desc is the only sort and ULIDs created
        // out-of-order (test fixtures, backdated rows) sort correctly.
        return $this->deployments()->reorder('started_at', 'desc')->first();
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

    /** Memoized result of the lazy-load path in primaryDomain(). */
    private ?SiteDomain $primaryDomainCache = null;

    private bool $primaryDomainResolved = false;

    public function primaryDomain(): ?SiteDomain
    {
        // Avoid re-querying when callers have already eager-loaded `domains`
        // (Settings::render() does this) — the in-memory collection is the same
        // source of truth for is_primary/first.
        if ($this->relationLoaded('domains')) {
            return $this->domains->firstWhere('is_primary', true) ?? $this->domains->first();
        }

        // primaryDomain() is hit repeatedly per request (blade views, Site::url(),
        // service classes). Memoize so the lazy path queries at most once.
        if ($this->primaryDomainResolved) {
            return $this->primaryDomainCache;
        }

        $this->primaryDomainResolved = true;

        // Order is_primary descending so the primary domain wins, falling back
        // to any domain — one query instead of a where + a separate fallback.
        return $this->primaryDomainCache = $this->domains()
            ->orderByDesc('is_primary')
            ->first();
    }

    /**
     * Drop the memoized primaryDomain() result. Call this after creating or
     * re-prioritising a SiteDomain on an in-memory Site instance that may have
     * already resolved primaryDomain() — e.g. the scaffold pipelines, which
     * read primaryDomain() in a later step than the one that creates it.
     */
    public function flushPrimaryDomainCache(): void
    {
        $this->primaryDomainCache = null;
        $this->primaryDomainResolved = false;
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
                self::STATUS_CONTAINER_PROVISIONING,
                self::STATUS_CONTAINER_ACTIVE,
                self::STATUS_CONTAINER_FAILED,
                self::STATUS_CUSTOM_ACTIVE,
                self::STATUS_EDGE_ACTIVE,
                self::STATUS_EDGE_FAILED,
            ], true);
    }

    public function isCustom(): bool
    {
        return $this->type === SiteType::Custom;
    }

    public function isCustomGitMode(): bool
    {
        return $this->isCustom() && trim((string) $this->git_repository_url) !== '';
    }

    public function isCustomNoRepoMode(): bool
    {
        return $this->isCustom() && trim((string) $this->git_repository_url) === '';
    }

    public function supportsWebserver(): bool
    {
        return $this->type instanceof SiteType
            ? $this->type->managesWebserver()
            : true;
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
            self::STATUS_CUSTOM_ACTIVE => 'custom active',
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

        return null;
    }

    /**
     * Returns the canonical runtime key for this site (php/node/python/
     * ruby/go/static).
     *
     * Prefers the new `runtime` column; falls back to the existing `type`
     * enum for rows that predate the column. The fallback only covers the
     * three values the form historically supported (php/node/static) —
     * python/ruby/go sites can only exist via the new code path.
     */
    public function runtimeKey(): ?string
    {
        $runtime = $this->runtime;
        if (is_string($runtime) && $runtime !== '') {
            return $runtime;
        }

        return $this->type?->value;
    }

    /**
     * The PHP version this site runs on, when the runtime is PHP.
     *
     * Reads from the new `runtime_version` column (canonical source per
     * the strategy memo's "drop php_version column entirely" decision)
     * and falls back to the legacy `php_version` column for rows that
     * predate the column drop. Returns null for non-PHP runtimes so
     * call sites can distinguish "not a PHP site" from "PHP version
     * unknown".
     */
    public function phpVersion(): ?string
    {
        if ($this->runtimeKey() !== 'php') {
            return null;
        }

        $version = $this->runtime_version;

        return is_string($version) && $version !== '' ? $version : null;
    }

    /**
     * The database engine this site targets.
     *
     * Prefers the explicit `database_engine` column when set (the user
     * picked an engine on a multi-engine server), and falls back to the
     * server's default ServerDatabaseEngine row. Returns null on hosts
     * with no DB at all (cache-only / load-balancer / static-only servers).
     *
     * Per the strategy memo: "Site database_engine defaults to server's
     * default; can be overridden to any engine installed on the server."
     */
    public function databaseEngine(): ?string
    {
        if (is_string($this->database_engine) && $this->database_engine !== '') {
            return $this->database_engine;
        }

        $server = $this->server ?? Server::query()->find($this->server_id);
        if ($server === null) {
            return null;
        }

        $default = $server->defaultDatabaseEngine();

        return $default?->engine;
    }

    /**
     * Back-compat shim for the dropped `php_version` column.
     *
     * The strategy memo's "drop php_version column entirely" decision
     * removed the underlying column, but a lot of test code (and
     * possibly third-party callers) still passes `'php_version' => '8.3'`
     * to factory `->create([...])` calls or to `Site::query()->update()`.
     * Routing those through to `runtime_version` keeps the old call
     * shape working while the canonical column is the new one.
     *
     * Reads return runtime_version when the runtime is PHP, null otherwise
     * — matches phpVersion()'s semantics so consumers can call either.
     */
    public function setPhpVersionAttribute($value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($this->runtime) || $this->runtime === '') {
            $this->runtime = 'php';
        }
        $this->runtime_version = (string) $value;
    }

    public function getPhpVersionAttribute(): ?string
    {
        return $this->phpVersion();
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

    public function isRailsFrameworkDetected(): bool
    {
        return strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? '')) === 'rails';
    }

    /**
     * True when the site's detected runtime app is WordPress (per
     * {@see PhpRuntimeDetector}),
     * OR when the site was scaffolded with the WordPress framework
     * pipeline (Q14 — gates the WordPress Settings section).
     */
    public function isWordPressDetected(): bool
    {
        if (strtolower((string) ($this->resolvedRuntimeAppDetection()['framework'] ?? '')) === 'wordpress') {
            return true;
        }

        $scaffoldFramework = $this->meta['scaffold']['framework'] ?? null;

        return is_string($scaffoldFramework) && strtolower($scaffoldFramework) === 'wordpress';
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

    public function usesContainerRuntime(): bool
    {
        return $this->type === SiteType::Container
            || in_array($this->container_backend, [
                'digitalocean_app_platform',
                'aws_app_runner',
                'dply_cloud',
            ], true);
    }

    public function usesEdgeRuntime(): bool
    {
        return is_string($this->edge_backend) && $this->edge_backend !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function edgeMeta(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        return is_array($meta['edge'] ?? null) ? $meta['edge'] : [];
    }

    public function edgeLiveUrl(): ?string
    {
        $url = $this->edgeMeta()['live_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function edgeHostname(): string
    {
        $routing = is_array($this->edgeMeta()['routing'] ?? null) ? $this->edgeMeta()['routing'] : [];
        $hostname = trim((string) ($routing['hostname'] ?? ''));
        if ($hostname !== '') {
            return strtolower($hostname);
        }

        $liveUrl = $this->edgeLiveUrl();
        if ($liveUrl !== null) {
            $host = parse_url($liveUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return strtolower($host);
            }
        }

        $testingDomain = (string) (config('edge.testing_domains')[0] ?? 'dply.host');
        $slug = (string) ($this->slug ?: Str::slug((string) $this->name)) ?: 'site';
        $suffix = substr(strtolower((string) $this->id), -6);

        return strtolower($slug.'-'.$suffix.'.'.$testingDomain);
    }

    public function isEdgePreview(): bool
    {
        $parentId = $this->edgeMeta()['preview_parent_site_id'] ?? null;

        return is_string($parentId) && $parentId !== '';
    }

    public function isDplyCloudSite(): bool
    {
        return $this->container_backend === 'dply_cloud';
    }

    public function isCloudPreview(): bool
    {
        $container = is_array($this->meta['container'] ?? null) ? $this->meta['container'] : [];
        $parentId = $container['preview_parent_site_id'] ?? null;

        return is_string($parentId) && $parentId !== '';
    }

    public function edgeGithubHookUrl(): string
    {
        return route('hooks.edge.github', ['site' => $this->id]);
    }

    public function edgeDeployments(): HasMany
    {
        return $this->hasMany(EdgeDeployment::class)->orderByDesc('created_at');
    }

    /**
     * URL the container deployment is reachable at, set by the
     * provisioner once the backend reports an "ingress" hostname
     * (DO App Platform default ondigitalocean.app, App Runner's
     * default *.awsapprunner.com).
     */
    /**
     * Rough monthly cost estimate for the container site, in USD.
     * Based on backend × size_tier × instance_count, using public
     * list pricing as of 2026-05. Returns 0 for non-container sites.
     *
     * Not authoritative — used as a "ballpark" surface in the
     * dashboard / CLI so operators can compare fleets without
     * digging into the cloud billing console.
     */
    public function estimatedMonthlyCostUsd(): int
    {
        if ($this->container_backend === null) {
            return 0;
        }
        $meta = is_array($this->meta) ? $this->meta : [];
        $tier = (string) ($meta['container']['size_tier'] ?? 'small');
        $instances = is_int($meta['container']['instance_count'] ?? null)
            ? (int) $meta['container']['instance_count']
            : 1;

        // Per-instance pricing rough estimates. DO's instance_size_slug
        // pricing is monthly + flat; App Runner is per-vCPU-hour for
        // active time so this is more uncertain (we assume active 24/7).
        $perInstance = match ($this->container_backend) {
            'digitalocean_app_platform' => match ($tier) {
                'medium' => 10,
                'large' => 25,
                'xlarge' => 50,
                default => 5,
            },
            'aws_app_runner' => match ($tier) {
                'medium' => 50,
                'large' => 100,
                'xlarge' => 200,
                default => 25,
            },
            default => 0,
        };

        return $perInstance * max(1, $instances);
    }

    public function containerLiveUrl(): ?string
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $url = $meta['container']['live_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
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
     * Normalised serverless resource limits — memory (MB), timeout (ms), and
     * per-container concurrency — with platform defaults filled in. The
     * DigitalOcean Functions deployer reads these straight onto the
     * OpenWhisk action's `limits` block at deploy time.
     *
     * @return array{memory: int, timeout: int, concurrency: int}
     */
    public function serverlessLimits(): array
    {
        $limits = $this->serverlessConfig()['limits'] ?? [];
        $limits = is_array($limits) ? $limits : [];

        $memory = (int) ($limits['memory'] ?? self::SERVERLESS_DEFAULT_MEMORY_MB);
        if (! in_array($memory, self::SERVERLESS_MEMORY_OPTIONS_MB, true)) {
            $memory = self::SERVERLESS_DEFAULT_MEMORY_MB;
        }

        $timeout = (int) ($limits['timeout'] ?? self::SERVERLESS_DEFAULT_TIMEOUT_MS);
        $timeout = max(self::SERVERLESS_MIN_TIMEOUT_MS, min(self::SERVERLESS_MAX_TIMEOUT_MS, $timeout));

        $concurrency = (int) ($limits['concurrency'] ?? self::SERVERLESS_DEFAULT_CONCURRENCY);
        $concurrency = max(1, min(self::SERVERLESS_MAX_CONCURRENCY, $concurrency));

        return [
            'memory' => $memory,
            'timeout' => $timeout,
            'concurrency' => $concurrency,
        ];
    }

    /**
     * The function's globally-unique friendly slug — the one that gives it a
     * clean dply-hosted URL ({app}/fn/{slug}) instead of the raw DigitalOcean
     * Functions invocation URL. Generated and persisted on first access.
     */
    public function ensureServerlessProxySlug(): string
    {
        $existing = (string) ($this->serverlessConfig()['proxy_slug'] ?? '');
        if ($existing !== '') {
            return $existing;
        }

        $base = Str::slug((string) $this->name) ?: 'fn';
        $slug = $base;
        while (static::query()
            ->where('meta->serverless->proxy_slug', $slug)
            ->whereKeyNot($this->getKey())
            ->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        $meta = is_array($this->meta) ? $this->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['proxy_slug'] = $slug;
        $meta['serverless'] = $serverless;
        $this->forceFill(['meta' => $meta])->save();

        return $slug;
    }

    /**
     * The stable secret dply signs background ticks (scheduler / queue) with.
     *
     * Deliberately separate from {@see webhook_secret}: that one is operator-
     * rotatable, and rotating it must never silently break the function's
     * scheduler. This secret is minted once, persisted in `meta.serverless`,
     * and reused — the deploy bakes it into the function's env and every tick
     * signs with the same value, so the two can never drift apart.
     */
    public function ensureServerlessCommandSecret(): string
    {
        $existing = trim((string) ($this->serverlessConfig()['command_secret'] ?? ''));
        if ($existing !== '') {
            return $existing;
        }

        $secret = Str::random(48);

        $meta = is_array($this->meta) ? $this->meta : [];
        $serverless = is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
        $serverless['command_secret'] = $secret;
        $meta['serverless'] = $serverless;
        $this->forceFill(['meta' => $meta])->save();

        return $secret;
    }

    /**
     * The function's live hostname — its proxy slug under a deterministically
     * chosen DPLY_TESTING_DOMAINS entry (e.g. orders-api.dply.cc), matching
     * how VM sites get a testing hostname. Null when no testing domains are
     * configured, in which case the path URL (/fn/{slug}) is the address.
     */
    public function serverlessFunctionHost(): ?string
    {
        $domains = array_values(array_filter(
            (array) config('services.digitalocean.testing_domains', []),
            static fn ($domain): bool => is_string($domain) && trim($domain) !== '',
        ));

        if ($domains === []) {
            return null;
        }

        $domain = trim((string) $domains[abs(crc32((string) $this->getKey())) % count($domains)]);

        return $this->ensureServerlessProxySlug().'.'.$domain;
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
            ...$this->previewHostnames(),
        ])->unique()->values()->all();
    }

    /**
     * Hostnames issued by {@see TestingHostnameProvisioner}. Stored on
     * SitePreviewDomain (not SiteDomain) — without this in the webserver
     * server_name list, freshly-provisioned testing URLs fall through to
     * the default nginx server and serve a bare 404.
     *
     * @return list<string>
     */
    public function previewHostnames(): array
    {
        $previewDomains = $this->relationLoaded('previewDomains')
            ? $this->previewDomains
            : $this->previewDomains()->get();

        return $previewDomains->pluck('hostname')
            ->filter(fn (mixed $hostname): bool => is_string($hostname) && trim($hostname) !== '')
            ->map(fn (string $hostname): string => strtolower(trim($hostname)))
            ->unique()
            ->values()
            ->all();
    }

    public function effectiveRepositoryPath(): string
    {
        $path = $this->repository_path;
        if ($path !== null && $path !== '') {
            return $path;
        }

        // Container sites have neither a repo path nor a document
        // root — return a stable placeholder so callers that derive
        // sub-paths (basic-auth dir, etc.) can still build strings.
        return $this->document_root ?? '/var/www/'.($this->slug ?: 'site');
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
        if ($server === null || ! $server->hostCapabilities()->supportsSsh()) {
            return false;
        }

        return in_array($this->webserver(), [
            'nginx',
            'apache',
            'caddy',
            'traefik',
            'openlitespeed',
        ], true);
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

    /**
     * Absolute path on the host where Dply reads/writes the .env file.
     * Defaults to {@see effectiveEnvDirectory()}/.env, but the operator can
     * override via the env_file_path column to relocate the file outside the
     * docroot — e.g. /etc/dply/<slug>.env — so it cannot be served by the
     * webserver even if the deny rule is bypassed.
     *
     * Override paths are validated to be absolute at the service layer; this
     * helper trusts the stored value (validation lives at write time, not
     * read time).
     */
    public function effectiveEnvFilePath(): string
    {
        $override = trim((string) ($this->env_file_path ?? ''));
        if ($override !== '') {
            return $override;
        }

        return rtrim($this->effectiveEnvDirectory(), '/').'/.env';
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Managed VM vhosts may emit engine-level HTTP cache directives (e.g. nginx FastCGI / proxy_cache).
     *
     * Reads from {@see Site::cachingConfig()} (`meta['caching']`) and falls back to the legacy
     * boolean column for sites that haven't run through the `migrate_engine_http_cache_to_meta_caching`
     * migration yet. The boolean column is also kept in sync by a `saving` observer so existing
     * direct-column reads keep working until the column is dropped in a follow-up release.
     */
    public function wantsEngineHttpCache(): bool
    {
        if ($this->isSuspended()) {
            return false;
        }

        if ($this->usesFunctionsRuntime() || $this->usesDockerRuntime() || $this->usesKubernetesRuntime()) {
            return false;
        }

        return $this->hasCachingMethod('nginx_http');
    }

    /**
     * Site-level caching configuration, materialised with sensible defaults.
     *
     * Lives under `meta['caching']`. Pre-migration sites (no `caching` key yet) get a synthetic
     * structure derived from the legacy `engine_http_cache_enabled` boolean so the rest of the
     * code can read one shape regardless of migration state.
     *
     * @return array<string, mixed>
     */
    public function cachingConfig(): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $caching = $meta['caching'] ?? null;

        if (is_array($caching)) {
            return $caching;
        }

        $legacyEnabled = (bool) $this->engine_http_cache_enabled;

        return [
            'enabled' => $legacyEnabled,
            'methods' => $legacyEnabled ? ['nginx_http'] : [],
            'nginx_http' => [
                'fcgi' => ['ttl_200' => '60m', 'ttl_404' => '10m', 'min_uses' => 1],
                'proxy' => ['ttl_200' => '60m', 'ttl_404' => '10m'],
                'bypass_cookies' => [],
            ],
            'lscache' => ['enabled' => false, 'rules' => []],
            'varnish' => ['enabled' => false, 'ttl_default' => '120s'],
        ];
    }

    /**
     * Whether the master caching toggle is on AND the given method id appears in `methods`.
     * The single gate every consumer should funnel through — keeps the "enabled vs methods"
     * invariant in one place.
     */
    public function hasCachingMethod(string $method): bool
    {
        $cfg = $this->cachingConfig();
        if (empty($cfg['enabled'])) {
            return false;
        }
        $methods = $cfg['methods'] ?? [];

        return is_array($methods) && in_array($method, $methods, true);
    }

    /**
     * Methods this site is eligible to enable, given its type/runtime/webserver. Single source
     * of truth for the Livewire toggle list, validation, and the audit-event payload.
     *
     * Webserver-native cache modules surface only for the webserver the server currently runs;
     * Varnish + OPcache are webserver-agnostic and surface for any non-container PHP/static/node
     * site. v2 will add `apache_modcache` and `caddy_souin`.
     *
     * @return list<string>
     */
    public function availableCachingMethods(): array
    {
        if ($this->usesFunctionsRuntime() || $this->usesDockerRuntime() || $this->usesKubernetesRuntime()) {
            return [];
        }

        $serverMeta = is_array($this->server?->meta) ? $this->server->meta : [];
        $webserver = strtolower((string) ($serverMeta['webserver'] ?? 'nginx'));

        $methods = ['varnish'];

        if ($this->type === SiteType::Php) {
            $methods[] = 'opcache';
        }

        switch ($webserver) {
            case 'nginx':
                $methods[] = 'nginx_http';
                break;
            case 'openlitespeed':
                $methods[] = 'lscache';
                break;
                // apache mod_cache + caddy souin land in v2.
        }

        return array_values(array_unique($methods));
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
     * Signed URL CI can POST to for redeploying a cloud container
     * site. The signature uses Laravel's signed-route mechanism
     * keyed on APP_KEY — no expiry (CI scripts shouldn't have to
     * refresh the URL on a schedule). Operators can rotate by
     * regenerating webhook_secret on the site, which invalidates
     * the URL via that field's inclusion in the signature.
     */
    public function cloudRedeployHookUrl(): string
    {
        return URL::signedRoute(
            'hooks.cloud.redeploy',
            ['site' => $this->id, 's' => substr((string) $this->webhook_secret, 0, 8)],
        );
    }

    /**
     * Inbound GitHub webhook URL — paste this into the repository's
     * webhook settings on GitHub. The site's webhook_secret is the
     * shared HMAC-SHA256 signing secret operators paste alongside.
     * No URL signing here: GitHub signs the body, not the URL.
     */
    public function cloudGithubHookUrl(): string
    {
        return route('hooks.cloud.github', ['site' => $this->id]);
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

    public function mergeEdgeMeta(array $patch): void
    {
        $meta = is_array($this->meta) ? $this->meta : [];
        $current = is_array($meta['edge'] ?? null) ? $meta['edge'] : [];
        $meta['edge'] = array_merge($current, $patch);
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
