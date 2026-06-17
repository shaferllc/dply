<?php

namespace App\Models;

use App\Enums\ServerProvider;
use App\Enums\ServerTier;
use App\Modules\TaskRunner\Connection as TaskRunnerConnection;
use App\Services\Billing\ServerTierClassifier;
use App\Services\Certificates\WildcardCertificateIssuer;
use App\Support\Hosts\HostCapabilities;
use App\Support\Servers\FakeCloudProvision;
use App\Support\Servers\ServerTags;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * @property string $id
 * @property ?Carbon $comped_until
 * @property string $health_status
 * @property ?string $hetzner_network_id
 * @property string $hosting_backend
 * @property string $ip_address
 * @property ?Carbon $last_health_check_at
 * @property string $logo_path
 * @property ?array<string, mixed> $meta
 * @property string $name
 * @property ?string $organization_id
 * @property ?string $pool_role
 * @property string $private_ip_address
 * @property ?string $private_network_id
 * @property ServerProvider $provider
 * @property ?string $provider_credential_id
 * @property ?string $provider_id
 * @property string $region
 * @property ?Carbon $scheduled_deletion_at
 * @property string $setup_script_key
 * @property string $setup_status
 * @property string $size
 * @property ?string $ssh_operational_private_key
 * @property string $ssh_port
 * @property ?string $ssh_private_key
 * @property ?string $ssh_recovery_private_key
 * @property string $ssh_user
 * @property string $status
 * @property string $supervisor_package_status
 * @property ?string $team_id
 * @property ?string $user_id
 * @property ?string $worker_pool_id
 * @property ?string $workspace_id
 * @property-read ?User $user
 * @property-read ?Organization $organization
 * @property-read ?Workspace $workspace
 * @property-read ?Team $team
 * @property-read ?ProviderCredential $providerCredential
 * @property-read Collection<int, Site> $sites
 * @property-read Collection<int, ServerDatabase> $serverDatabases
 * @property-read ?ServerDatabaseAdminCredential $databaseAdminCredential
 * @property-read Collection<int, ServerDatabaseAuditEvent> $databaseAuditEvents
 * @property-read Collection<int, ServerCronJob> $cronJobs
 * @property-read Collection<int, SupervisorProgram> $supervisorPrograms
 * @property-read Collection<int, ServerFirewallRule> $firewallRules
 * @property-read Collection<int, ServerFirewallSnapshot> $firewallSnapshots
 * @property-read Collection<int, ServerFirewallAuditEvent> $firewallAuditEvents
 * @property-read Collection<int, ServerFirewallApplyLog> $firewallApplyLogs
 * @property-read Collection<int, ServerMetricSnapshot> $metricSnapshots
 * @property-read Collection<int, ServerSystemdServiceState> $systemdServiceStates
 * @property-read Collection<int, ServerSystemdServiceAuditEvent> $systemdServiceAuditEvents
 * @property-read ?InsightSetting $insightSetting
 * @property-read Collection<int, InsightFinding> $insightFindings
 * @property-read Collection<int, ServerAuthorizedKey> $authorizedKeys
 * @property-read Collection<int, ServerSystemUser> $systemUsers
 * @property-read Collection<int, ServerSshKeyAuditEvent> $sshKeyAuditEvents
 * @property-read Collection<int, ServerRecipe> $recipes
 * @property-read Collection<int, ServerProvisionRun> $provisionRuns
 * @property-read Collection<int, NotificationSubscription> $notificationSubscriptions
 * @property-read ?PrivateNetwork $privateNetwork
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const HOST_KIND_VM = 'vm';

    public const HOST_KIND_DOCKER = 'docker';

    public const HOST_KIND_KUBERNETES = 'kubernetes';

    public const HOST_KIND_DIGITALOCEAN_FUNCTIONS = 'digitalocean_functions';

    public const HOST_KIND_DIGITALOCEAN_APP_PLATFORM = 'digitalocean_app_platform';

    public const HOST_KIND_AWS_LAMBDA = 'aws_lambda';

    public const HOST_KIND_AWS_APP_RUNNER = 'aws_app_runner';

    public const HOST_KIND_DPLY_CLOUD = 'dply_cloud';

    public const HOST_KIND_DPLY_EDGE = 'dply_edge_delivery';

    /** The customer's own provider account runs (and is billed for) this VM. */
    public const HOSTING_BACKEND_BYO = 'byo';

    /** dply runs this VM on its own provider account and bills it all-in cost-plus. */
    public const HOSTING_BACKEND_DPLY = 'dply_managed';

    /**
     * Tag that locks a server as dply's own infrastructure. A server carrying
     * this tag (or self-adopted into the control plane) can never be deleted
     * from the panel. {@see isDeletionProtected()}.
     */
    public const PROTECTED_TAG = 'dply';

    public const HEALTH_REACHABLE = 'reachable';

    public const HEALTH_UNREACHABLE = 'unreachable';

    public const SETUP_STATUS_PENDING = 'pending';

    public const SETUP_STATUS_RUNNING = 'running';

    public const SETUP_STATUS_DONE = 'done';

    public const SETUP_STATUS_FAILED = 'failed';

    public const SUPERVISOR_PACKAGE_INSTALLED = 'installed';

    public const SUPERVISOR_PACKAGE_MISSING = 'missing';

    protected $fillable = [
        'user_id',
        'organization_id',
        'workspace_id',
        'team_id',
        'worker_pool_id',
        'pool_role',
        'provider_credential_id',
        'name',
        'logo_path',
        'provider',
        'hosting_backend',
        'provider_id',
        'ip_address',
        'private_ip_address',
        'hetzner_network_id',
        'private_network_id',
        'ssh_port',
        'ssh_user',
        'ssh_private_key',
        'ssh_operational_private_key',
        'ssh_recovery_private_key',
        'status',
        'region',
        'size',
        'setup_script_key',
        'setup_status',
        'meta',
        'supervisor_package_status',
        'last_health_check_at',
        'health_status',
        'scheduled_deletion_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => ServerProvider::class,
            'ssh_private_key' => 'encrypted',
            'ssh_operational_private_key' => 'encrypted',
            'ssh_recovery_private_key' => 'encrypted',
            'meta' => 'array',
            'last_health_check_at' => 'datetime',
            'scheduled_deletion_at' => 'datetime',
            'comped_until' => 'datetime',
        ];
    }

    /**
     * Public URL of the server's custom logo, or null when none is set —
     * callers fall back to the generated gradient + initials avatar. Stored on
     * the `public` disk. Mirrors {@see Site::logoUrl()}.
     */
    public function logoUrl(): ?string
    {
        if (blank($this->logo_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->logo_path);
    }

    public function hasLogo(): bool
    {
        return filled($this->logo_path);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * A dedicated cache host provisioned via the "redis_server" profile — as
     * opposed to an app server that merely runs redis as a co-located cache.
     * Gates the redis-specific provisioning emails.
     */
    public function isRedisServer(): bool
    {
        $meta = $this->meta ?? [];

        return ($meta['server_role'] ?? null) === 'redis'
            && ($meta['install_profile'] ?? null) === 'redis_server';
    }

    /**
     * A worker host is provisioned for background/queue-style workloads and
     * always runs Caddy (it attaches testing URLs but isn't a public web
     * front). Caching + CDN/edge tabs don't apply to these sites.
     */
    public function isWorkerHost(): bool
    {
        $meta = $this->meta ?? [];

        return ($meta['server_role'] ?? null) === 'worker';
    }

    /**
     * dply's own control-plane infrastructure (the dogfood boxes — app, worker,
     * database, redis) must never be deletable from the panel: not the cloud
     * host, not the database row. A server is locked when it carries the `dply`
     * tag (set on the server's meta tags) or was self-adopted into the control
     * plane ({@see App\Console\Commands\SelfAdoptCommand} stamps
     * meta['self_managed']).
     *
     * Enforced in three layers: {@see App\Policies\ServerPolicy::delete()} hides
     * every UI affordance; {@see App\Actions\Servers\DeleteServerAction::execute()}
     * is the hard backstop that throws {@see App\Exceptions\ProtectedServerDeletionException}
     * for any direct caller (HTTP, scheduled-deletion command, worker teardown).
     */
    public function isDeletionProtected(): bool
    {
        if (ServerTags::hasTag($this, self::PROTECTED_TAG)) {
            return true;
        }

        $meta = $this->meta ?? [];

        return ($meta['self_managed'] ?? false) === true;
    }

    /**
     * True when THIS server is the very machine the current process is running
     * on — i.e. a deploy targeting it would SSH back into localhost. Matched by
     * comparing the server's public/private IPs against the box's own network
     * interfaces (exact match, so a customer's remote server can never trip it).
     *
     * Used to make a control-plane SELF-deploy safe: the deploy's
     * `horizon:terminate` runs on the target box, so when the target IS the box
     * running the deploy queue, an inline restart bounces the Horizon executing
     * this (and every concurrent) deploy job. {@see App\Services\Sites\SiteDeployPipelineRunner}
     */
    public function isLocalDeployHost(): bool
    {
        $targets = array_values(array_filter([
            $this->ip_address,
            $this->private_ip_address,
        ]));
        if ($targets === []) {
            return false;
        }

        return (bool) array_intersect($targets, self::localIpAddresses());
    }

    /**
     * IPv4/IPv6 addresses bound to this box's network interfaces. Cached for the
     * process lifetime (a host's addresses don't change under a running worker).
     *
     * @return list<string>
     */
    private static function localIpAddresses(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $ips = [];
        if (function_exists('net_get_interfaces')) {
            $interfaces = @net_get_interfaces();
            if (is_array($interfaces)) {
                foreach ($interfaces as $interface) {
                    foreach (($interface['unicast'] ?? []) as $unicast) {
                        if (! empty($unicast['address'])) {
                            $ips[] = (string) $unicast['address'];
                        }
                    }
                }
            }
        }

        $resolved = @gethostbynamel((string) gethostname());
        if (is_array($resolved)) {
            $ips = array_merge($ips, $resolved);
        }

        return $cached = array_values(array_unique(array_filter($ips)));
    }

    /**
     * The worker pool this server belongs to (clones + their source), if any.
     * See {@see WorkerPool}. *
     *
     * @return BelongsTo<WorkerPool, $this>
     */
    /** @return BelongsTo<WorkerPool, $this> */
    public function workerPool(): BelongsTo
    {
        return $this->belongsTo(WorkerPool::class);
    }

    /** True when this server is the pool's single primary (scheduler owner). */
    public function isPoolPrimary(): bool
    {
        return $this->pool_role === WorkerPool::ROLE_PRIMARY;
    }

    /**
     * True when this server is a worker — by role (server_role=worker), or by
     * pool membership (it's a primary/replica in a worker pool). Used to detect
     * + label worker servers in the resources list and the site Workers panel.
     */
    public function isWorkerServer(): bool
    {
        if ($this->isWorkerHost()) {
            return true;
        }

        return $this->worker_pool_id !== null || filled($this->pool_role);
    }

    /** Per-member reconciler sub-state (servers.meta['pool']['state']). */
    public function poolMemberState(): ?string
    {
        $meta = $this->meta ?? [];

        return $meta['pool']['state'] ?? null;
    }

    /** @return BelongsTo<ProviderCredential, $this> */
    public function providerCredential(): BelongsTo
    {
        return $this->belongsTo(ProviderCredential::class);
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /**
     * Per-zone wildcard TLS certificates (e.g. *.on-dply.com) installed on this
     * server, shared by every testing-hostname site on the matching zone. See
     * {@see WildcardCertificateIssuer}. *
     *
     * @return HasMany<ServerWildcardCertificate, $this>
     */
    /** @return HasMany<ServerWildcardCertificate, $this> */
    public function wildcardCertificates(): HasMany
    {
        return $this->hasMany(ServerWildcardCertificate::class);
    }

    /**
     * Multi-backend serving points hosted on this server (this server acting as a
     * backend for one or more sites' backend groups). See
     * docs/MULTI_BACKEND_SITES.md. *
     *
     * @return HasMany<SiteBackend, $this>
     */
    /** @return HasMany<SiteBackend, $this> */
    public function siteBackends(): HasMany
    {
        return $this->hasMany(SiteBackend::class);
    }

    /** Memoized request-lifetime cache for {@see cachedSitesCount()}. */
    private ?int $cachedSitesCount = null;

    /**
     * Request-level cache for sites().count() — both the sidebar nav helper
     * and the shared-host report widget call this on the same Server
     * instance during a page render, so eat the query once and reuse it.
     * Cleared when caller knows the count changed via {@see flushCachedSitesCount()}.
     */
    public function cachedSitesCount(): int
    {
        if ($this->cachedSitesCount !== null) {
            return $this->cachedSitesCount;
        }

        // Reuse a withCount-loaded value if a controller pre-warmed it
        // (sites_count is the Laravel convention).
        $preloaded = $this->getAttributeValue('sites_count');
        if (is_int($preloaded) || (is_string($preloaded) && ctype_digit($preloaded))) {
            return $this->cachedSitesCount = (int) $preloaded;
        }

        return $this->cachedSitesCount = $this->sites()->count();
    }

    public function flushCachedSitesCount(): void
    {
        $this->cachedSitesCount = null;
    }

    /** @return HasMany<ServerDatabase, $this> */
    public function serverDatabases(): HasMany
    {
        return $this->hasMany(ServerDatabase::class);
    }

    /**
     * Database engines installed on this server (multi-engine support).
     * Distinct from {@see serverDatabases} which lists user-created
     * named DBs on top of an engine. See ServerDatabaseEngine docblock. *
     *
     * @return HasMany<ServerDatabaseEngine, $this>
     */
    /** @return HasMany<ServerDatabaseEngine, $this> */
    public function databaseEngines(): HasMany
    {
        return $this->hasMany(ServerDatabaseEngine::class);
    }

    /**
     * Cache services (Redis/Valkey/Memcached) installed on this server.
     * Companion to {@see databaseEngines}; together they make up the
     * engine inventory surfaced in the fleet "Services" disclosure. *
     *
     * @return HasMany<ServerCacheService, $this>
     */
    /** @return HasMany<ServerCacheService, $this> */
    public function cacheServices(): HasMany
    {
        return $this->hasMany(ServerCacheService::class);
    }

    /**
     * The engine row marked is_default — the implicit choice for new sites
     * that don't pick an engine explicitly. Null when nothing is installed
     * (cache-only / load-balancer / static-only servers).
     */
    public function defaultDatabaseEngine(): ?ServerDatabaseEngine
    {
        return $this->databaseEngines()->where('is_default', true)->first();
    }

    /**
     * Runtime keys (node / python / ruby / go / etc.) the server has a
     * pinned global version for, derived from `meta.runtime_defaults`.
     *
     * Empty when the server hasn't been provisioned with any runtime
     * defaults yet — mise itself may still be installed (the strategy
     * memo's polyglot pitch lets any non-PHP runtime be installed on
     * demand at the site-create moment), but the wizard hasn't pre-set
     * a server-level default. The site-create form uses this list to
     * decide whether to surface an "install missing runtime" affordance.
     *
     * @return list<string>
     */
    /**
     * The L7 edge proxy (if any) sitting in front of this server's webserver.
     * Returns null when the webserver handles :80 directly; otherwise one
     * of {'traefik', 'haproxy', 'envoy', 'openresty'}.
     *
     * When this is non-null, dply runs Caddy as the per-site backend on
     * ephemeral high ports and the edge proxy on :80 — see
     * `App\Jobs\AddEdgeProxyJob` for the install flow.
     */
    public function edgeProxy(): ?string
    {
        $meta = $this->meta ?? [];
        $proxy = $meta['edge_proxy'] ?? null;

        return is_string($proxy) && in_array($proxy, ['traefik', 'haproxy', 'envoy', 'openresty'], true) ? $proxy : null;
    }

    public function hasEdgeProxy(): bool
    {
        return $this->edgeProxy() !== null;
    }

    /** @return list<string> */
    public function installedRuntimeKeys(): array
    {
        $meta = $this->meta ?? [];
        $defaults = $meta['runtime_defaults'] ?? null;
        if (! is_array($defaults)) {
            return [];
        }

        $keys = [];
        foreach (array_keys($defaults) as $key) {
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    public function hasRuntimeInstalled(string $runtime): bool
    {
        return in_array($runtime, $this->installedRuntimeKeys(), true);
    }

    /** @return HasOne<ServerDatabaseAdminCredential, $this> */
    public function databaseAdminCredential(): HasOne
    {
        return $this->hasOne(ServerDatabaseAdminCredential::class);
    }

    /**
     * The dply Logs add-on agent for this server (at most one — the add-on is a
     * per-server resource). See {@see ServerLogAgent}. *
     *
     * @return HasOne<ServerLogAgent, $this>
     */
    /** @return HasOne<ServerLogAgent, $this> */
    public function logAgent(): HasOne
    {
        return $this->hasOne(ServerLogAgent::class);
    }

    /** @return HasMany<ServerDatabaseAuditEvent, $this> */
    public function databaseAuditEvents(): HasMany
    {
        return $this->hasMany(ServerDatabaseAuditEvent::class)->orderByDesc('created_at');
    }

    /** @return HasMany<ServerCronJob, $this> */
    public function cronJobs(): HasMany
    {
        return $this->hasMany(ServerCronJob::class);
    }

    /** @return HasMany<SupervisorProgram, $this> */
    public function supervisorPrograms(): HasMany
    {
        return $this->hasMany(SupervisorProgram::class);
    }

    /** @return HasMany<ServerFirewallRule, $this> */
    public function firewallRules(): HasMany
    {
        return $this->hasMany(ServerFirewallRule::class)->orderBy('sort_order');
    }

    /** @return HasMany<ServerFirewallSnapshot, $this> */
    public function firewallSnapshots(): HasMany
    {
        return $this->hasMany(ServerFirewallSnapshot::class)->orderByDesc('created_at');
    }

    /** @return HasMany<ServerFirewallAuditEvent, $this> */
    public function firewallAuditEvents(): HasMany
    {
        return $this->hasMany(ServerFirewallAuditEvent::class)->orderByDesc('created_at');
    }

    /** @return HasMany<ServerFirewallApplyLog, $this> */
    public function firewallApplyLogs(): HasMany
    {
        return $this->hasMany(ServerFirewallApplyLog::class)->orderByDesc('created_at');
    }

    /** @return HasMany<ServerMetricSnapshot, $this> */
    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(ServerMetricSnapshot::class)->orderByDesc('captured_at');
    }

    /**
     * Most-recent metric snapshot as a relation so Eloquent memoizes the
     * single-row lookup on the instance. The overview render fans the same
     * server out to the cost card, health cockpit, and billing tier — each
     * of which used to run its own "latest snapshot" query. Routing them all
     * through this relation collapses those into one query per request. *
     *
     * @return HasOne<ServerMetricSnapshot, $this>
     */
    /** @return HasOne<ServerMetricSnapshot, $this> */
    public function latestMetricSnapshot(): HasOne
    {
        return $this->hasOne(ServerMetricSnapshot::class)->latestOfMany('captured_at');
    }

    /**
     * Billing tier derived from the most recent metric snapshot's cpu_count
     * and mem_total_kb. Returns ServerTier::XS while specs are unknown so a
     * freshly-connected server isn't accidentally billed at XL during the
     * gap between provision and first agent report.
     */
    public function billingTier(): ServerTier
    {
        $snapshot = $this->latestMetricSnapshot;
        $payload = is_array($snapshot?->payload) ? $snapshot->payload : [];

        $cpuCount = isset($payload['cpu_count']) && is_numeric($payload['cpu_count'])
            ? (int) $payload['cpu_count']
            : null;

        $memMb = isset($payload['mem_total_kb']) && is_numeric($payload['mem_total_kb'])
            ? (int) round((float) $payload['mem_total_kb'] / 1024)
            : null;

        return app(ServerTierClassifier::class)->classify($cpuCount, $memMb);
    }

    /** @return HasMany<ServerSystemdServiceState, $this> */
    public function systemdServiceStates(): HasMany
    {
        return $this->hasMany(ServerSystemdServiceState::class)->orderBy('label');
    }

    /** @return HasMany<ServerSystemdServiceAuditEvent, $this> */
    public function systemdServiceAuditEvents(): HasMany
    {
        return $this->hasMany(ServerSystemdServiceAuditEvent::class)->orderByDesc('occurred_at');
    }

    /** @return MorphOne<InsightSetting, $this> */
    public function insightSetting(): MorphOne
    {
        return $this->morphOne(InsightSetting::class, 'settingsable');
    }

    /** @return HasMany<InsightFinding, $this> */
    public function insightFindings(): HasMany
    {
        return $this->hasMany(InsightFinding::class)->orderByDesc('detected_at');
    }

    /** @return HasMany<ServerAuthorizedKey, $this> */
    public function authorizedKeys(): HasMany
    {
        return $this->hasMany(ServerAuthorizedKey::class);
    }

    /** @return HasMany<ServerSystemUser, $this> */
    public function systemUsers(): HasMany
    {
        return $this->hasMany(ServerSystemUser::class)->orderBy('username');
    }

    /**
     * Free-form operator notes (runbooks, customer IDs, context). Pinned first,
     * then most-recently-touched. Pinned notes surface on the server overview. *
     *
     * @return HasMany<ServerNote, $this>
     */
    /** @return HasMany<ServerNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(ServerNote::class)
            ->orderByDesc('pinned')
            ->orderByDesc('updated_at');
    }

    /** @return HasMany<ServerSshKeyAuditEvent, $this> */
    public function sshKeyAuditEvents(): HasMany
    {
        return $this->hasMany(ServerSshKeyAuditEvent::class)->orderByDesc('created_at');
    }

    /** @return HasMany<ServerRecipe, $this> */
    public function recipes(): HasMany
    {
        return $this->hasMany(ServerRecipe::class);
    }

    /** @return HasMany<ServerProvisionRun, $this> */
    public function provisionRuns(): HasMany
    {
        return $this->hasMany(ServerProvisionRun::class)->orderByDesc('created_at');
    }

    /** @return MorphMany<NotificationSubscription, $this> */
    public function notificationSubscriptions(): MorphMany
    {
        return $this->morphMany(NotificationSubscription::class, 'subscribable');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Fully provisioned: the provider instance is up (status ready) AND the
     * OS-level setup script finished (setup_status done). `status` flips to ready
     * the moment the IP is known — long before setup completes — so callers that
     * must not touch a half-built box (e.g. worker-pool replay/deploy) gate on
     * this instead of {@see isReady()}.
     */
    public function isProvisioningComplete(): bool
    {
        return $this->status === self::STATUS_READY
            && $this->setup_status === self::SETUP_STATUS_DONE;
    }

    /** @return BelongsTo<PrivateNetwork, $this> */
    public function privateNetwork(): BelongsTo
    {
        return $this->belongsTo(PrivateNetwork::class, 'private_network_id');
    }

    /** Whether this server has a known private/VPC IP it can be reached on by peers. */
    public function hasPrivateNetwork(): bool
    {
        return filled($this->private_ip_address);
    }

    public function hostKind(): string
    {
        $meta = $this->meta ?? [];
        $hostKind = $meta['host_kind'] ?? self::HOST_KIND_VM;

        return in_array($hostKind, [
            self::HOST_KIND_VM,
            self::HOST_KIND_DOCKER,
            self::HOST_KIND_KUBERNETES,
            self::HOST_KIND_DIGITALOCEAN_FUNCTIONS,
            self::HOST_KIND_DIGITALOCEAN_APP_PLATFORM,
            self::HOST_KIND_AWS_LAMBDA,
            self::HOST_KIND_AWS_APP_RUNNER,
            self::HOST_KIND_DPLY_CLOUD,
            self::HOST_KIND_DPLY_EDGE,
        ], true) ? $hostKind : self::HOST_KIND_VM;
    }

    public function hostCapabilities(): HostCapabilities
    {
        return new HostCapabilities($this);
    }

    public function isVmHost(): bool
    {
        return $this->hostKind() === self::HOST_KIND_VM;
    }

    public function isDigitalOceanFunctionsHost(): bool
    {
        return $this->hostKind() === self::HOST_KIND_DIGITALOCEAN_FUNCTIONS;
    }

    public function isAwsLambdaHost(): bool
    {
        return $this->hostKind() === self::HOST_KIND_AWS_LAMBDA;
    }

    /**
     * A FaaS host (DO Functions / AWS Lambda) — a namespace for functions,
     * not a machine. Billing treats these differently: the host itself is
     * not a spec-tiered server; its function-Sites bill per-function.
     */
    public function isServerlessHost(): bool
    {
        return $this->isDigitalOceanFunctionsHost() || $this->isAwsLambdaHost();
    }

    public function isDigitalOceanAppPlatformHost(): bool
    {
        return $this->hostKind() === self::HOST_KIND_DIGITALOCEAN_APP_PLATFORM;
    }

    public function isAwsAppRunnerHost(): bool
    {
        return $this->hostKind() === self::HOST_KIND_AWS_APP_RUNNER;
    }

    public function isDplyCloudHost(): bool
    {
        return $this->hostKind() === self::HOST_KIND_DPLY_CLOUD;
    }

    public function isDplyEdgeHost(): bool
    {
        return $this->hostKind() === self::HOST_KIND_DPLY_EDGE;
    }

    /**
     * Logical hosts for dply-managed products — never spec-tiered as BYO VMs.
     */
    public function isManagedProductHost(): bool
    {
        return $this->isServerlessHost() || $this->isDplyCloudHost() || $this->isDplyEdgeHost();
    }

    /**
     * True when this is a real SSH-managed VM that dply runs on its OWN provider
     * account (dply pays the provider) and bills all-in cost-plus, rather than
     * the customer's connected credential. Distinct from isManagedProductHost()
     * — a managed VM is still a full server with SSH, a workspace, sites, etc.
     */
    public function usesManagedHosting(): bool
    {
        return $this->hosting_backend === self::HOSTING_BACKEND_DPLY;
    }

    /**
     * A real dply-managed VM (the free-CX22 grant counter), as opposed to a
     * managed-product logical host (Cloud/Edge/serverless).
     */
    public function isManagedVm(): bool
    {
        return $this->usesManagedHosting() && $this->isVmHost();
    }

    /**
     * True when this server is currently comped (free) and must be excluded from
     * the managed-server bill. Two cases: an explicit future `comped_until`
     * stamp (the localized comp primitive — reusable for support credits), or a
     * managed box on a still-open beta org with no stamp yet (reads as
     * comped-until-cutover; a backfill stamps the real date once it's known).
     */
    public function isComped(): bool
    {
        if ($this->comped_until !== null) {
            return $this->comped_until->isFuture();
        }

        return $this->isManagedVm()
            && $this->organization !== null
            && $this->organization->isBeta();
    }

    public function hostingBackendLabel(): string
    {
        return match ($this->hosting_backend) {
            self::HOSTING_BACKEND_DPLY => __('Dply-hosted (managed)'),
            default => __('Your provider account'),
        };
    }

    public function isContainerHost(): bool
    {
        return in_array($this->hostKind(), [
            self::HOST_KIND_DIGITALOCEAN_APP_PLATFORM,
            self::HOST_KIND_AWS_APP_RUNNER,
            self::HOST_KIND_DPLY_CLOUD,
        ], true);
    }

    public function isDockerHost(): bool
    {
        return $this->hostKind() === self::HOST_KIND_DOCKER;
    }

    public function dockerEnginePresent(): bool
    {
        $meta = $this->meta ?? [];
        $manageDocker = is_array($meta['manage_docker'] ?? null) ? $meta['manage_docker'] : [];
        if (! empty($manageDocker['present'])) {
            return true;
        }

        $manageTools = is_array($meta['manage_tools'] ?? null) ? $meta['manage_tools'] : [];
        $dockerTool = is_array($manageTools['docker'] ?? null) ? $manageTools['docker'] : [];

        return ! empty($dockerTool['present']);
    }

    public function isKubernetesCluster(): bool
    {
        return $this->hostKind() === self::HOST_KIND_KUBERNETES;
    }

    public function providerDisplayLabel(): string
    {
        if ($this->isDigitalOceanFunctionsHost()) {
            return 'DigitalOcean Functions';
        }

        if ($this->isAwsLambdaHost()) {
            return 'AWS Lambda';
        }

        if ($this->isDockerHost()) {
            return 'Docker';
        }

        if ($this->isKubernetesCluster()) {
            return 'Kubernetes';
        }

        return $this->provider->label();
    }

    /**
     * OpenSSH one-line public key derived from the stored provisioned private key.
     */
    public function openSshPublicKeyFromPrivate(): ?string
    {
        return $this->openSshPublicKeyFromKey($this->operationalSshPrivateKey());
    }

    public function openSshPublicKeyFromOperationalPrivate(): ?string
    {
        return $this->openSshPublicKeyFromKey($this->operationalSshPrivateKey());
    }

    public function openSshPublicKeyFromRecoveryPrivate(): ?string
    {
        return $this->openSshPublicKeyFromKey($this->recoverySshPrivateKey());
    }

    public function operationalSshPrivateKey(): ?string
    {
        $fakeOverride = FakeCloudProvision::sshPrivateKeyOverrideForFakeServer($this);
        if ($fakeOverride !== null) {
            return $fakeOverride;
        }

        $key = $this->ssh_operational_private_key;

        if (trim((string) $key) !== '') {
            return $key;
        }

        $legacy = $this->ssh_private_key;

        return $legacy !== null && trim($legacy) !== '' ? $legacy : null;
    }

    public function recoverySshPrivateKey(): ?string
    {
        $fakeOverride = FakeCloudProvision::sshPrivateKeyOverrideForFakeServer($this);
        if ($fakeOverride !== null) {
            return $fakeOverride;
        }

        $key = $this->ssh_recovery_private_key;

        if (trim((string) $key) !== '') {
            return $key;
        }

        $legacy = $this->ssh_private_key;

        return $legacy !== null && trim($legacy) !== '' ? $legacy : null;
    }

    public function hasAnySshPrivateKey(): bool
    {
        return $this->operationalSshPrivateKey() !== null || $this->recoverySshPrivateKey() !== null;
    }

    public function hasPersonalUserSshKey(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $userKeys = $user->sshKeys()
            ->select(['id', 'public_key'])
            ->get();

        if ($userKeys->isEmpty()) {
            return false;
        }

        $managedKeyIds = $userKeys->pluck('id');
        $publicKeys = $userKeys
            ->pluck('public_key')
            ->filter(fn (string $key): bool => trim($key) !== '')
            ->map(fn (string $key): string => trim($key));

        return $this->authorizedKeys()
            ->where(function ($query) use ($managedKeyIds, $publicKeys): void {
                $query->where(function ($managed) use ($managedKeyIds): void {
                    $managed->where('managed_key_type', UserSshKey::class)
                        ->whereIn('managed_key_id', $managedKeyIds);
                });

                if ($publicKeys->isNotEmpty()) {
                    $query->orWhereIn('public_key', $publicKeys);
                }
            })
            ->exists();
    }

    public function hasDedicatedOperationalSshPrivateKey(): bool
    {
        $key = $this->ssh_operational_private_key;

        return trim((string) $key) !== '';
    }

    protected function openSshPublicKeyFromKey(?string $priv): ?string
    {
        if ($priv === null || trim($priv) === '') {
            return null;
        }

        try {
            $key = PublicKeyLoader::load($priv);
            if (! $key instanceof PrivateKey) {
                return null;
            }

            return trim($key->getPublicKey()->toString('OpenSSH'));
        } catch (\Throwable) {
            return null;
        }
    }

    public function getSshConnectionString(): string
    {
        return sprintf(
            '%s@%s',
            $this->ssh_user,
            $this->ip_address ?? '0.0.0.0'
        );
    }

    /**
     * SSH connection for TaskRunner remote execution as the server's deploy user.
     */
    public function connectionAsUser(): TaskRunnerConnection
    {
        return $this->connectionAsOperationalUser();
    }

    public function connectionAsOperationalUser(): TaskRunnerConnection
    {
        $user = trim((string) $this->ssh_user);
        if ($user === '') {
            throw new \RuntimeException('Server has no SSH user configured.');
        }

        return $this->taskRunnerConnectionAs($user, $this->operationalSshPrivateKey());
    }

    /**
     * SSH connection for TaskRunner remote execution as root.
     */
    public function connectionAsRoot(): TaskRunnerConnection
    {
        return $this->connectionAsRecoveryRoot();
    }

    public function connectionAsRecoveryRoot(): TaskRunnerConnection
    {
        return $this->taskRunnerConnectionAs('root', $this->recoverySshPrivateKey());
    }

    protected function taskRunnerConnectionAs(string $username, ?string $key): TaskRunnerConnection
    {
        if ($key === null || trim((string) $key) === '') {
            throw new \RuntimeException('Server has no SSH private key configured.');
        }

        $host = trim((string) $this->ip_address);
        if ($host === '') {
            throw new \RuntimeException('Server has no IP address.');
        }

        $connection = [
            'host' => $host,
            'port' => (string) ((int) ($this->ssh_port ?: 22)),
            'username' => $username,
            'private_key' => (string) $key,
        ];

        if (FakeCloudProvision::isFakeServer($this)) {
            $scriptPath = config('server_provision_fake.ssh_script_path');
            if (is_string($scriptPath) && trim($scriptPath) !== '') {
                $connection['script_path'] = trim($scriptPath);
            }
        }

        /** @var array<string, string> $connection */
        return TaskRunnerConnection::fromArray($connection);
    }
}
