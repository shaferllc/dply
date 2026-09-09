<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Models\CloudDatabase;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Modules\Database\Backends\DatabaseRouter;
use App\Modules\Database\Jobs\ProvisionManagedDatabaseJob;
use App\Modules\Database\Support\ServerlessDatabaseVendors;
use App\Support\Servers\ManagedDatabaseRegionCatalog;
use App\Support\Servers\ManagedDatabaseSizeCatalog;
use App\Support\Servers\ProviderManagedDatabaseRegion;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Attach the `redis` binding type (Redis-family cache services reachable from
 * the site) and resolve the effective service host.
 */
trait ManagesRedisBindings
{
    /**
     * Redis-family cache services the site can attach: those on its own server
     * (loopback), private-network peers (private IP), same-org dedicated cache
     * hosts, and org managed Redis clusters (DigitalOcean / Vultr / Upstash).
     *
     * @return list<array{id: string, label: string}>
     */
    private function attachableCacheServices(Site $site): array
    {
        $server = $site->server;
        if ($server === null) {
            return [];
        }

        $services = ServerCacheService::query()
            ->whereIn('server_id', $this->attachableCacheServerIds($server))
            ->whereIn('engine', ServerCacheService::FAMILY_REDIS_ENGINES)
            ->with('server:id,name,organization_id,ip_address,private_ip_address,private_network_id,meta')
            ->orderBy('engine')
            ->get();

        $consumers = $this->bindingConsumerCounts(
            'server_cache_service',
            $services->map(fn (ServerCacheService $s): string => (string) $s->id)->all(),
            (string) $site->id,
        );

        $vm = $services
            ->map(function (ServerCacheService $svc) use ($server, $consumers): array {
                [$where, $group] = $this->cacheServiceLocation($svc, $server);
                $state = $svc->status === ServerCacheService::STATUS_RUNNING ? '' : ' — '.$svc->status;
                $used = $consumers[(string) $svc->id] ?? 0;

                return [
                    'id' => (string) $svc->id,
                    'label' => ucfirst((string) $svc->engine).' · '.$where.$state.$this->usageSuffix($used),
                    'engine' => (string) $svc->engine,
                    'group' => $group,
                    'consumers' => $used,
                ];
            })
            ->all();

        return [...$vm, ...$this->attachableManagedRedisClusters($site)];
    }

    /**
     * Org-level managed Redis clusters (DigitalOcean / Vultr / Upstash).
     * Prefixed ids so they don't collide with server_cache_services ULIDs.
     *
     * @return list<array{id: string, label: string, engine: string, group: string, consumers: int}>
     */
    private function attachableManagedRedisClusters(Site $site): array
    {
        if ($site->organization_id === null) {
            return [];
        }

        $clusters = CloudDatabase::query()
            ->where('organization_id', $site->organization_id)
            ->where('engine', CloudDatabase::ENGINE_REDIS)
            ->whereIn('status', [CloudDatabase::STATUS_ACTIVE, CloudDatabase::STATUS_PROVISIONING])
            ->orderBy('name')
            ->get();

        $consumers = $this->bindingConsumerCounts(
            'cloud_database',
            $clusters->map(fn (CloudDatabase $d): string => (string) $d->id)->all(),
            (string) $site->id,
        );

        return $clusters
            ->map(function (CloudDatabase $cluster) use ($consumers): array {
                $state = $cluster->status === CloudDatabase::STATUS_ACTIVE ? '' : ' — '.$cluster->status;
                $used = $consumers[(string) $cluster->id] ?? 0;

                return [
                    'id' => 'cloud:'.$cluster->id,
                    'label' => $cluster->name.$state.$this->usageSuffix($used),
                    'engine' => CloudDatabase::ENGINE_REDIS,
                    'group' => 'managed',
                    'consumers' => $used,
                ];
            })
            ->all();
    }

    /**
     * @return array{0: string, 1: 'local'|'peer'|'dedicated'}
     */
    private function cacheServiceLocation(ServerCacheService $svc, Server $siteServer): array
    {
        if ((string) $svc->server_id === (string) $siteServer->id) {
            return [__('this server'), 'local'];
        }

        $host = $svc->server;
        if ($host instanceof Server && $this->sharePrivateNetwork($siteServer, $host)) {
            return [$host->name ?: __('network peer'), 'peer'];
        }

        return [($host?->name ?: __('dedicated cache')).' — '.__('public IP'), 'dedicated'];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachRedis(Site $site, array $params): SiteBinding
    {
        $server = $site->server;
        if ($server === null) {
            throw new RuntimeException(__('This site has no server.'));
        }

        $reachable = $this->attachableCacheServerIds($server);
        $query = ServerCacheService::query()
            ->whereIn('server_id', $reachable)
            ->whereIn('engine', ServerCacheService::FAMILY_REDIS_ENGINES)
            ->with('server:id,name,organization_id,ip_address,private_ip_address,private_network_id,meta');

        $targetId = (string) ($params['target_id'] ?? '');
        if (str_starts_with($targetId, 'cloud:')) {
            return $this->attachManagedRedis($site, substr($targetId, 6), $params);
        }

        $svc = $targetId !== ''
            ? (clone $query)->whereKey($targetId)->first()
            // No explicit pick (e.g. legacy callers): prefer the local service.
            : (clone $query)->get()->sortBy(fn (ServerCacheService $s) => (string) $s->server_id === (string) $server->id ? 0 : 1)->first();

        if (! $svc instanceof ServerCacheService) {
            throw new RuntimeException(__('No Redis-compatible service is reachable. Install Redis/Valkey from the server Caches workspace, add one to this private network, or attach a dedicated cache server.'));
        }

        $svcServer = $svc->server ?? $server;
        $crossServer = (string) $svc->server_id !== (string) $server->id;
        $host = $this->effectiveServiceHost($svcServer, $site);
        $port = (string) ($svc->port ?: ServerCacheService::defaultPortFor((string) $svc->engine));

        // Instance / connection name. Blank = the PRIMARY redis the cache/queue/
        // session drivers point at (bare REDIS_* keys); a slug names a SECONDARY
        // connection (REDIS_<SLUG>_*) used via Redis::connection('<slug>').
        $connection = $this->resolveInstanceConnectionName($site, 'redis', $params);
        $primary = $this->connectionIsPrimary($connection);
        $editingId = trim((string) ($params['binding_id'] ?? ''));
        $p = $primary ? 'REDIS_' : 'REDIS_'.strtoupper($connection).'_';

        $env = [];
        if ($primary) {
            // REDIS_CLIENT is process-global, so only the primary owns it.
            $env['REDIS_CLIENT'] = 'phpredis';
        }
        $env[$p.'HOST'] = $host;
        $env[$p.'PORT'] = $port;
        if (filled($svc->auth_password)) {
            $env[$p.'PASSWORD'] = (string) $svc->auth_password;
        }
        if (filled($svc->cache_prefix)) {
            $env[$p.'PREFIX'] = (string) $svc->cache_prefix;
        }

        $binding = $this->persistInstanceBinding($site, 'redis', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $primary ? 'primary' : $connection,
            'target_type' => 'server_cache_service',
            'target_id' => (string) $svc->id,
            'injected_env' => $env,
            'config' => array_filter([
                'engine' => (string) $svc->engine,
                'connection' => $primary ? '' : $connection,
                'service' => (string) $svc->engine.($crossServer ? ' · '.($svcServer->name ?? '') : ''),
                'connection_snippet' => $primary ? null : $this->redisConnectionSnippet($connection),
                'source_server_id' => $crossServer ? (string) $svc->server_id : null,
            ], fn ($v) => $v !== null),
        ], $primary, $editingId);

        // One-click "use Redis for cache, sessions, and queue": only the PRIMARY
        // redis backs the framework drivers (they read REDIS_HOST), so a named
        // secondary connection skips this wiring. Now that the binding exists
        // (so the driver dependency check passes), wire the three driver
        // bindings to redis. Opt-in via the modal checkbox.
        if ($primary && filter_var($params['use_for_drivers'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->applyRedisToDriverBindings($site);
        }

        return $binding;
    }

    /**
     * Attach an org CloudDatabase Redis cluster as the site's redis binding.
     *
     * @param  array<string, mixed>  $params
     */
    private function attachManagedRedis(Site $site, string $clusterId, array $params): SiteBinding
    {
        $cluster = CloudDatabase::query()
            ->where('organization_id', $site->organization_id)
            ->where('engine', CloudDatabase::ENGINE_REDIS)
            ->whereKey($clusterId)
            ->first();

        if (! $cluster instanceof CloudDatabase) {
            throw new InvalidArgumentException(__('That managed Redis cluster is not in this organization.'));
        }

        $connection = $this->resolveInstanceConnectionName($site, 'redis', $params);
        $primary = $this->connectionIsPrimary($connection);
        $editingId = trim((string) ($params['binding_id'] ?? ''));
        $prefix = $primary ? 'REDIS' : 'REDIS_'.strtoupper($connection);
        $env = $cluster->connectionEnvVars($prefix);
        if ($primary) {
            $env['REDIS_CLIENT'] = 'phpredis';
        }

        $ready = $cluster->status === CloudDatabase::STATUS_ACTIVE && $env !== [];

        $binding = $this->persistInstanceBinding($site, 'redis', [
            'mode' => 'attach_existing',
            'status' => $ready ? SiteBinding::STATUS_CONFIGURED : SiteBinding::STATUS_PROVISIONING,
            'name' => $primary ? 'primary' : $connection,
            'target_type' => 'cloud_database',
            'target_id' => (string) $cluster->id,
            'injected_env' => $env,
            'config' => array_filter([
                'engine' => CloudDatabase::ENGINE_REDIS,
                'connection' => $primary ? '' : $connection,
                'service' => $cluster->name.' · '.__('managed'),
                'managed' => true,
                'connection_snippet' => $primary ? null : $this->redisConnectionSnippet($connection),
            ], fn ($v) => $v !== null),
        ], $primary, $editingId);

        if ($primary && $ready && filter_var($params['use_for_drivers'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->applyRedisToDriverBindings($site);
        }

        return $binding;
    }

    /**
     * Wire a `redis` binding to a now-ready ServerCacheService (used by the
     * dedicated Redis VM flow once its server finished provisioning).
     */
    public function wireServerCacheBinding(SiteBinding $binding, ServerCacheService $svc, Site $site): void
    {
        $svc->loadMissing('server');
        $svcServer = $svc->server;
        if ($svcServer === null) {
            throw new RuntimeException(__('That Redis service has no server.'));
        }

        $config = is_array($binding->config) ? $binding->config : [];
        $connection = (string) ($config['connection'] ?? '');
        $primary = $this->connectionIsPrimary($connection);
        $p = $primary ? 'REDIS_' : 'REDIS_'.strtoupper($connection).'_';
        $host = $this->effectiveServiceHost($svcServer, $site);
        $port = (string) ($svc->port ?: ServerCacheService::defaultPortFor((string) $svc->engine));

        $env = [];
        if ($primary) {
            $env['REDIS_CLIENT'] = 'phpredis';
        }
        $env[$p.'HOST'] = $host;
        $env[$p.'PORT'] = $port;
        if (filled($svc->auth_password)) {
            $env[$p.'PASSWORD'] = (string) $svc->auth_password;
        }
        if (filled($svc->cache_prefix)) {
            $env[$p.'PREFIX'] = (string) $svc->cache_prefix;
        }

        $config['connection_ready_at'] = now()->toIso8601String();
        $config['service'] = (string) $svc->engine.' · '.($svcServer->name ?? __('dedicated'));
        if (! $primary) {
            $config['connection_snippet'] = $this->redisConnectionSnippet($connection);
        }

        $binding->forceFill([
            'status' => SiteBinding::STATUS_CONFIGURED,
            'injected_env' => $env,
            'config' => $config,
            'last_error' => null,
        ])->save();

        if ($primary && filter_var($config['use_for_drivers'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $this->applyRedisToDriverBindings($site);
        }
    }

    /**
     * Provision a managed Redis cluster (DigitalOcean / Vultr / Upstash) and
     * attach it as this site's redis binding. Mirrors
     * {@see ManagesDatabaseBindings::provisionManagedDatabase} but persists a
     * `redis` binding so REDIS_* keys land on the Redis resource, not Database.
     *
     * @param  array<string, mixed>  $params
     */
    private function provisionRedis(Site $site, array $params): SiteBinding
    {
        $server = $site->server;
        if ($server === null) {
            throw new RuntimeException(__('This site has no server.'));
        }

        $placement = strtolower(trim((string) ($params['placement'] ?? '')));
        if ($placement === '' || $placement === 'on_box') {
            throw new InvalidArgumentException(__('Pick where Redis should live.'));
        }

        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            $name = (Str::slug((string) $site->name, '_') ?: 'redis').'_redis';
        }
        if (preg_match('/^[a-zA-Z0-9_]+$/', $name) !== 1) {
            throw new InvalidArgumentException(__('Cluster name must be alphanumeric/underscore.'));
        }

        $params['engine'] = CloudDatabase::ENGINE_REDIS;
        $params['name'] = $name;

        return $this->provisionManagedRedisCluster($site, $server, $placement, $params);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function provisionManagedRedisCluster(Site $site, Server $server, string $placement, array $params): SiteBinding
    {
        $name = (string) $params['name'];
        $connection = $this->resolveInstanceConnectionName($site, 'redis', $params);
        $primary = $this->connectionIsPrimary($connection);
        $editingId = trim((string) ($params['binding_id'] ?? ''));
        $previousTargetId = $this->previousCloudDatabaseTargetId($site, $editingId);

        if (ServerlessDatabaseVendors::isServerless($placement)) {
            if (! ServerlessDatabaseVendors::isEnabled($placement)) {
                throw new InvalidArgumentException(__('That serverless Redis vendor is not available yet.'));
            }
            $database = $this->createServerlessRedisCluster($site, $placement, $params);
        } else {
            $router = app(DatabaseRouter::class);
            $backend = $router->colocatedBackendFor($server);
            if ($backend === null) {
                throw new RuntimeException(__('This server\'s provider has no managed Redis option.'));
            }
            if (! in_array(CloudDatabase::ENGINE_REDIS, $backend->supportedEngines(), true)) {
                throw new InvalidArgumentException(__('Managed Redis is not available on this provider.'));
            }

            $credential = $this->resolveManagedDatabaseCredential($site, $server);
            if ($credential === null) {
                throw new RuntimeException(__('No :provider credential is connected for this server.', [
                    'provider' => $server->provider->label(),
                ]));
            }

            $rejected = is_array($params['rejected_regions'] ?? null) ? $params['rejected_regions'] : [];
            $available = ManagedDatabaseRegionCatalog::slugs($server, CloudDatabase::ENGINE_REDIS, $credential, $rejected);
            $region = ProviderManagedDatabaseRegion::resolve(
                $server->provider->value,
                isset($params['region']) ? (string) $params['region'] : null,
                $backend->regionForServer($server),
                $available,
            );
            if ($region === null || ($available !== [] && ! in_array($region, $available, true))) {
                throw new RuntimeException(__('Pick a region DigitalOcean offers for managed Redis.'));
            }

            $size = ManagedDatabaseSizeCatalog::resolve($server, CloudDatabase::ENGINE_REDIS, isset($params['size']) ? (string) $params['size'] : null, $credential);
            if ($size === null) {
                throw new RuntimeException(__('Could not load managed-database plans from the provider.'));
            }

            $version = trim((string) ($params['version'] ?? ''));
            if ($version === '') {
                try {
                    $version = (new DigitalOceanService($credential))->getDatabaseEngineDefaultVersion(CloudDatabase::ENGINE_REDIS) ?? '';
                } catch (\Throwable) {
                    $version = '';
                }
            }

            $database = CloudDatabase::query()->create([
                'organization_id' => $site->organization_id,
                'name' => $name,
                'engine' => CloudDatabase::ENGINE_REDIS,
                'version' => $version,
                'size' => $size,
                'region' => $region,
                'backend' => $backend->key(),
                'provider_credential_id' => $credential->id,
                'status' => CloudDatabase::STATUS_PROVISIONING,
                'meta' => ['provisioned_for_site_id' => (string) $site->id],
            ]);
        }

        $binding = $this->persistInstanceBinding($site, 'redis', [
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_PROVISIONING,
            'name' => $primary ? 'primary' : $connection,
            'target_type' => 'cloud_database',
            'target_id' => (string) $database->id,
            'injected_env' => [],
            'last_error' => null,
            'config' => array_filter([
                'engine' => CloudDatabase::ENGINE_REDIS,
                'connection' => $primary ? '' : $connection,
                'service' => $name.' · '.__('managed'),
                'cluster_name' => $name,
                'managed' => true,
                'placement' => $placement,
                'region' => $database->region,
                'size' => $database->size,
                'connection_snippet' => $primary ? null : $this->redisConnectionSnippet($connection),
            ], fn ($v) => $v !== null),
        ], $primary, $editingId);

        ProvisionManagedDatabaseJob::dispatch(
            (string) $database->id,
            (string) $binding->id,
            (string) $server->id,
        );

        $this->forgetReplacedCloudDatabase($previousTargetId, (string) $database->id);

        return $binding;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function createServerlessRedisCluster(Site $site, string $placement, array $params): CloudDatabase
    {
        $vendor = ServerlessDatabaseVendors::find($placement);
        if ($vendor === null) {
            throw new RuntimeException(__('Unknown serverless Redis vendor.'));
        }

        $backend = app(DatabaseRouter::class)->backend($placement);
        if (! in_array(CloudDatabase::ENGINE_REDIS, $backend->supportedEngines(), true)) {
            throw new InvalidArgumentException(__('That vendor does not offer Redis.'));
        }

        $orgId = $site->organization_id;
        if ($orgId === null) {
            throw new RuntimeException(__('No organization for this site.'));
        }

        $region = trim((string) ($params['vendor_region'] ?? ''));
        if ($region === '') {
            $region = (string) ($vendor['regions'][0]['value'] ?? '');
        }

        if ($vendor['account_required'] && trim((string) ($params['vendor_account'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Enter the :label for :vendor.', [
                'label' => $vendor['account_label'] ?? __('account'),
                'vendor' => $vendor['label'],
            ]));
        }

        $credential = $this->resolveServerlessCredential(
            $orgId,
            $vendor['provider'],
            trim((string) ($params['vendor_api_key'] ?? '')),
            trim((string) ($params['vendor_account'] ?? '')),
            $vendor['label'],
        );

        return CloudDatabase::query()->create([
            'organization_id' => $orgId,
            'name' => (string) $params['name'],
            'engine' => CloudDatabase::ENGINE_REDIS,
            'version' => trim((string) ($params['version'] ?? '7')),
            'size' => 'small',
            'region' => $region,
            'backend' => $backend->key(),
            'provider_credential_id' => $credential->id,
            'status' => CloudDatabase::STATUS_PROVISIONING,
            'meta' => ['provisioned_for_site_id' => (string) $site->id, 'serverless' => true],
        ]);
    }

    /**
     * A ready-to-paste config/database.php → redis connection block for a NAMED
     * redis instance (empty for the primary, which uses Laravel's defaults). The
     * env() refs match the namespaced keys this trait injects.
     */
    private function redisConnectionSnippet(string $connection): string
    {
        $slug = $this->connectionSlug($connection);
        if ($this->connectionIsPrimary($slug)) {
            return '';
        }

        $p = 'REDIS_'.strtoupper($slug).'_';

        return "// config/database.php → 'redis' connections:\n"
            ."'{$slug}' => [\n"
            ."    'url' => env('{$p}URL'),\n"
            ."    'host' => env('{$p}HOST', '127.0.0.1'),\n"
            ."    'password' => env('{$p}PASSWORD'),\n"
            ."    'port' => env('{$p}PORT', '6379'),\n"
            ."    'database' => env('{$p}DB', '0'),\n"
            .'],';
    }

    /**
     * Point cache, sessions, and the queue at the just-attached Redis by
     * creating their driver bindings (redis). Each is created only when the site
     * doesn't already have one of that type, so a cache/session/queue config the
     * operator set by hand is never clobbered. Each binding's injected env
     * (CACHE_STORE / SESSION_DRIVER / QUEUE_CONNECTION) is adopted out of the
     * loose .env so it stays managed rather than duplicated.
     */
    private function applyRedisToDriverBindings(Site $site): void
    {
        if (! $site->bindings()->where('type', 'cache')->exists()) {
            $this->adoptInjectedEnv($site, $this->attachCache($site, ['driver' => 'redis']));
        }
        if (! $site->bindings()->where('type', 'queue')->exists()) {
            $this->adoptInjectedEnv($site, $this->attachQueue($site, ['driver' => 'redis']));
        }
        if (! $site->bindings()->where('type', 'session')->exists()) {
            $this->adoptInjectedEnv($site, $this->attachSession($site, ['driver' => 'redis']));
        }
    }

    /**
     * Address $site should dial to reach a service on $serviceServer: loopback
     * when it's the site's own box, the server's private IP when it's a peer on
     * the same private network, or the public IP for a dedicated cache host
     * that isn't on a recorded VPC.
     */
    private function effectiveServiceHost(Server $serviceServer, Site $site): string
    {
        $siteServer = $site->server;

        if ($siteServer !== null
            && (string) $serviceServer->id !== (string) $siteServer->id
            && $this->sharePrivateNetwork($siteServer, $serviceServer)
            && filled($serviceServer->private_ip_address)) {
            return (string) $serviceServer->private_ip_address;
        }

        if ($siteServer !== null
            && (string) $serviceServer->id !== (string) $siteServer->id
            && $serviceServer->isDedicatedCacheHost()
            && filled($serviceServer->ip_address)) {
            return (string) $serviceServer->ip_address;
        }

        return '127.0.0.1';
    }
}
