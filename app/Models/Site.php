<?php

namespace App\Models;

use App\Enums\SiteType;
use App\Jobs\CleanupCustomSiteJob;
use App\Models\Concerns\Site\DerivesWorkerEnvironment;
use App\Models\Concerns\Site\GuardsSiteAccess;
use App\Models\Concerns\Site\HasSiteRelationships;
use App\Models\Concerns\Site\ManagesEdgeHosting;
use App\Models\Concerns\Site\ManagesServerless;
use App\Models\Concerns\Site\ResolvesSiteHostnames;
use App\Models\Concerns\Site\ResolvesSiteRuntime;
use App\Models\Concerns\Site\ResolvesSiteUrls;
use App\Models\Concerns\Site\ResolvesWebserverConfig;
use App\Models\Concerns\Site\TracksProvisioningStatus;
use App\Services\Scaffold\PlaceholderDnsManager;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class Site extends Model
{
    use DerivesWorkerEnvironment;
    use GuardsSiteAccess;
    use HasFactory, HasUlids;
    use HasSiteRelationships;
    use ManagesEdgeHosting;
    use ManagesServerless;
    use ResolvesSiteHostnames;
    use ResolvesSiteRuntime;
    use ResolvesSiteUrls;
    use ResolvesWebserverConfig;
    use TracksProvisioningStatus;

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

    /**
     * Bare VM site created by the choose-app flow (config/dply.php
     * `choose_app_enabled`). Domain + server are set, but type / runtime /
     * document_root are not yet chosen — the user picks an application on
     * sites.choose-app, which transitions the site into its real status.
     * See docs/CHOOSE_APP_FLOW.md.
     */
    public const STATUS_AWAITING_APP = 'awaiting_app';

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
        'logo_path',
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
        'scheduled_deletion_at',
        'git_repository_url',
        'git_branch',
        'git_deploy_key_private',
        'git_deploy_key_public',
        'webhook_secret',
        'webhook_allowed_ips',
        'post_deploy_command',
        'deploy_script_id',
        'deploy_strategy',
        'deploy_method',
        'parent_site_id',
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
        'serverless_backend',
        'serverless_provider_credential_id',
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
            'scheduled_deletion_at' => 'datetime',
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
            // Purge relations the DB FK cascade can't reach — denormalised
            // site_id columns (error_events / app_logs / …) and polymorphic
            // links (console_actions, notification_subscriptions, …) — so a
            // deleted site leaves no orphaned rows (esp. its Errors stream).
            try {
                app(\App\Support\Sites\SiteRelationPurger::class)->purge($site);
            } catch (\Throwable $e) {
                Log::warning('Site::deleting relation purge failed', [
                    'site_id' => $site->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }

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

    /** dply runs the function on its own managed FaaS account (dply pays the provider). */
    public const SERVERLESS_BACKEND_DPLY = 'dply_serverless';

    /** The customer's connected provider account runs (and is billed for) the function. */
    public const SERVERLESS_BACKEND_BYO = 'org_digitalocean';

    /** Memoized result of the lazy-load path in primaryDomain(). */
    private ?SiteDomain $primaryDomainCache = null;

    private bool $primaryDomainResolved = false;

    /**
     * URL the container deployment is reachable at, set by the
     * provisioner once the backend reports an "ingress" hostname
     * (DO App Platform default ondigitalocean.app, App Runner's
     * default *.awsapprunner.com).
     */

    /**
     * Laravel Octane application server choices (see `php artisan octane:start --help`).
     *
     * @var list<string>
     */
    public const OCTANE_SERVERS = ['swoole', 'roadrunner', 'frankenphp'];

    /**
     * Site-level caching configuration, materialised with sensible defaults.
     *
     * Lives under `meta['caching']`. Pre-migration sites (no `caching` key yet) get a synthetic
     * structure derived from the legacy `engine_http_cache_enabled` boolean so the rest of the
     * code can read one shape regardless of migration state.
     *
     * @return array<string, mixed>
     */
    public function resolveRouteBinding($value, $field = null): ?static
    {
        // Accepts slug (human-readable, used by CLI) or primary key (ULID,
        // used by web routes). Tries slug first so CLI commands like
        // `dply sites:show my-site` resolve correctly without changing how
        // web URL generation works (which still uses the primary key).
        return static::query()
            ->where('slug', $value)
            ->orWhere($this->getKeyName(), $value)
            ->first();
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
