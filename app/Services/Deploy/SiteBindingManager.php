<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\ObjectStorageCredential;
use App\Models\PrivateNetwork;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Jobs\EnsureSitePhpDatabaseDriverJob;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\DigitalOceanService;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Sites\DotEnvFileParser;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Storage\ObjectStorageBucketProvisioner;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Performs attach / provision / detach for a site's managed resource bindings.
 *
 * Each binding owns the connection variables it contributes to the deploy
 * environment (stored encrypted on {@see SiteBinding::$injected_env}); those
 * are injected at deploy time only and never written into the editable
 * Variables list. The {@see SiteResourceBindingResolver} reads these rows back
 * so the UI reflects the managed state.
 */
class SiteBindingManager
{
    public function __construct(
        private readonly ServerDatabaseProvisioner $databaseProvisioner,
        private readonly ObjectStorageBucketProvisioner $bucketProvisioner,
    ) {}

    /**
     * Existing resources an operator can attach for a given binding type.
     * Shape per entry: ['id' => string, 'label' => string].
     *
     * @return list<array{id: string, label: string}>
     */
    public function attachableTargets(Site $site, string $type): array
    {
        return match ($type) {
            'database' => $this->attachableDatabases($site),
            'redis' => $this->attachableCacheServices($site),
            default => [],
        };
    }

    /**
     * Redis-family cache services the site can reach: those on its own server
     * (loopback) plus those on private-network peers (private IP). Mirrors
     * {@see attachableDatabases}.
     *
     * @return list<array{id: string, label: string}>
     */
    private function attachableCacheServices(Site $site): array
    {
        $server = $site->server;
        if ($server === null) {
            return [];
        }

        return ServerCacheService::query()
            ->whereIn('server_id', $this->reachableServerIds($server))
            ->whereIn('engine', ServerCacheService::FAMILY_REDIS_ENGINES)
            ->with('server:id,name,organization_id,private_ip_address,private_network_id')
            ->orderBy('engine')
            ->get()
            ->map(function (ServerCacheService $svc) use ($server): array {
                $sameBox = (string) $svc->server_id === (string) $server->id;
                $where = $sameBox ? __('this server') : ($svc->server?->name ?: __('network peer'));
                $state = $svc->status === ServerCacheService::STATUS_RUNNING ? '' : ' — '.$svc->status;

                return [
                    'id' => (string) $svc->id,
                    'label' => ucfirst((string) $svc->engine).' · '.$where.$state,
                ];
            })
            ->all();
    }

    /**
     * Databases the site can actually reach: those on its own server (over
     * loopback) plus those on peer servers sharing the same private network
     * (over the peer's private IP). Each label notes where the DB lives and
     * flags peers whose remote access isn't open yet.
     *
     * @return list<array{id: string, label: string}>
     */
    private function attachableDatabases(Site $site): array
    {
        $server = $site->server;
        if ($server === null) {
            return [];
        }

        return ServerDatabase::query()
            ->whereIn('server_id', $this->reachableServerIds($server))
            ->with('server:id,name,organization_id,private_ip_address,private_network_id')
            ->orderBy('name')
            ->get()
            ->map(function (ServerDatabase $db) use ($server): array {
                $sameBox = (string) $db->server_id === (string) $server->id;
                if ($sameBox) {
                    $where = __('this server');
                } else {
                    $where = ($db->server?->name ?: __('network peer'))
                        .($db->remote_access ? '' : ' — '.__('remote access off'));
                }

                return [
                    'id' => (string) $db->id,
                    'label' => $db->name.' ('.$db->engine.') · '.$where,
                ];
            })
            ->all();
    }

    /**
     * Attach an existing resource to the site.
     *
     * @param  array<string, mixed>  $params
     */
    public function attachExisting(Site $site, string $type, array $params): SiteBinding
    {
        $this->assertType($type);

        $binding = match ($type) {
            'database' => $this->attachDatabase($site, $params),
            'redis' => $this->attachRedis($site, $params),
            'queue' => $this->attachQueue($site, $params),
            'cache' => $this->attachCache($site, $params),
            'session' => $this->attachSession($site, $params),
            'storage' => $this->attachStorage($site, $params),
            'scheduler', 'workers' => $this->attachMarker($site, $type),
            default => throw new InvalidArgumentException(__('This binding type cannot be attached yet.')),
        };

        $this->adoptInjectedEnv($site, $binding);

        return $binding;
    }

    /**
     * Provision a brand-new resource, then attach it.
     *
     * @param  array<string, mixed>  $params
     */
    public function provisionNew(Site $site, string $type, array $params): SiteBinding
    {
        $this->assertType($type);

        $binding = match ($type) {
            'database' => $this->provisionDatabase($site, $params),
            'storage' => $this->provisionBucket($site, $params),
            // Redis/queue/cache/scheduler/workers have no separate resource to
            // spin up beyond what attach already wires, so provision falls back
            // to the attach path for v1 (which already adopts).
            default => $this->attachExisting($site, $type, $params),
        };

        $this->adoptInjectedEnv($site, $binding);

        return $binding;
    }

    /**
     * "Adopt" a freshly-connected resource's connection variables: drop any
     * matching keys from the site .env cache so the binding's values win
     * instead of a stale manual .env value overriding them. This is what makes
     * "choose a resource" actually take over DB_HOST/DB_PASSWORD/… rather than
     * sitting underneath whatever the operator typed before. The keys then
     * render as managed rows; the operator can still re-Override per key.
     *
     * @return list<string> the keys that were removed from the .env cache
     */
    private function adoptInjectedEnv(Site $site, SiteBinding $binding): array
    {
        if ($binding->status !== SiteBinding::STATUS_CONFIGURED) {
            return [];
        }

        $injected = $binding->connectionEnv();
        if ($injected === []) {
            return [];
        }

        $parser = app(DotEnvFileParser::class);
        $parsed = $parser->parse((string) ($site->env_file_content ?? ''));

        $removed = [];
        foreach (array_keys($injected) as $key) {
            $key = (string) $key;
            if (array_key_exists($key, $parsed['variables'])) {
                unset($parsed['variables'][$key], $parsed['comments'][$key]);
                $removed[] = $key;
            }
        }

        if ($removed !== []) {
            $site->forceFill([
                'env_file_content' => app(DotEnvFileWriter::class)->render($parsed['variables'], $parsed['comments']),
                'env_cache_origin' => 'local-edit',
            ])->save();
        }

        return $removed;
    }

    public function detach(SiteBinding $binding): void
    {
        $binding->delete();
    }

    // ---- database ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachDatabase(Site $site, array $params): SiteBinding
    {
        $databaseId = (string) ($params['target_id'] ?? '');
        if ($databaseId === '') {
            throw new InvalidArgumentException(__('Choose a database to attach.'));
        }

        $server = $site->server;
        if ($server === null) {
            throw new RuntimeException(__('This site has no server.'));
        }

        $db = ServerDatabase::query()
            ->whereIn('server_id', $this->reachableServerIds($server))
            ->whereKey($databaseId)
            ->first();

        if (! $db instanceof ServerDatabase) {
            throw new InvalidArgumentException(__('That database is not reachable from this site\'s server.'));
        }

        $crossServer = (string) $db->server_id !== (string) $server->id;

        $binding = $this->persist($site, 'database', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $db->name,
            'target_type' => 'server_database',
            'target_id' => (string) $db->id,
            'injected_env' => $this->databaseEnv($db, $site),
            'config' => array_filter([
                'engine' => $db->engine,
                // Cross-server attachments carry the source so the UI can show
                // a "network peer" badge and warn when remote access is off.
                'source_server_id' => $crossServer ? (string) $db->server_id : null,
                'needs_remote_access' => ($crossServer && ! $db->remote_access) ? true : null,
            ]),
        ]);

        // The app server may have been provisioned for a different DB engine
        // than the one just attached (e.g. MySQL server, Postgres attached).
        // Install the matching PHP client driver so the app doesn't deploy into
        // "could not find driver".
        EnsureSitePhpDatabaseDriverJob::dispatch((string) $site->id, (string) $db->engine);

        return $binding;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function provisionDatabase(Site $site, array $params): SiteBinding
    {
        $server = $site->server;
        if ($server === null) {
            throw new RuntimeException(__('This site has no server to provision a database on.'));
        }

        $engine = strtolower(trim((string) ($params['engine'] ?? 'mysql')));
        if (! in_array($engine, ['mysql', 'postgres', 'sqlite'], true)) {
            throw new InvalidArgumentException(__('Unsupported database engine.'));
        }

        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '' || preg_match('/^[a-zA-Z0-9_]+$/', $name) !== 1) {
            throw new InvalidArgumentException(__('Database name must be alphanumeric/underscore.'));
        }

        $isSqlite = $engine === 'sqlite';
        $username = '';
        $password = '';
        $host = trim((string) ($params['host'] ?? '127.0.0.1'));

        if ($isSqlite) {
            $root = rtrim((string) config('server_database.sqlite_root', '/var/lib/dply/sqlite'), '/');
            $host = $root.'/'.$server->id.'/'.$name.'.db';
        } else {
            $base = Str::slug($name, '_') ?: 'db';
            $username = Str::limit($base, 28, '').'_'.Str::lower(Str::random(4));
            $password = Str::password(24);
        }

        $db = ServerDatabase::query()->create([
            'server_id' => $server->id,
            'name' => $name,
            'engine' => $engine,
            'username' => $username,
            'password' => $password,
            'host' => $host,
            'description' => 'Provisioned from site binding ('.$site->slug.')',
        ]);

        try {
            $this->databaseProvisioner->createOnServer($db);
        } catch (\Throwable $e) {
            // Keep the row + binding so the operator can retry/inspect, but
            // surface the failure on the binding rather than silently 500ing.
            $binding = $this->persist($site, 'database', [
                'mode' => 'provision_new',
                'status' => SiteBinding::STATUS_ERROR,
                'name' => $db->name,
                'target_type' => 'server_database',
                'target_id' => (string) $db->id,
                'injected_env' => $this->databaseEnv($db, $site),
                'config' => ['engine' => $db->engine],
                'last_error' => Str::limit($e->getMessage(), 1000),
            ]);

            throw new RuntimeException(__('Database row created but server provisioning failed: :err', ['err' => $e->getMessage()]), 0, $e);
        }

        return $this->persist($site, 'database', [
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $db->name,
            'target_type' => 'server_database',
            'target_id' => (string) $db->id,
            'injected_env' => $this->databaseEnv($db, $site),
            'config' => ['engine' => $db->engine],
            'last_error' => null,
        ]);
    }

    /**
     * Connection variables for a database as seen by $site. The host is
     * resolved relative to where the site runs: loopback when the DB is on the
     * site's own box, the source server's private IP when it's a peer in the
     * same private network.
     *
     * @return array<string, string>
     */
    private function databaseEnv(ServerDatabase $db, Site $site): array
    {
        if ($db->engine === 'sqlite') {
            return [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => (string) ($db->host ?: ''),
                'DATABASE_URL' => $db->connectionUrl(),
            ];
        }

        $host = $this->effectiveDatabaseHost($db, $site);

        return [
            'DB_CONNECTION' => $db->engine === 'postgres' ? 'pgsql' : 'mysql',
            'DB_HOST' => $host,
            'DB_PORT' => (string) $db->defaultPort(),
            'DB_DATABASE' => (string) $db->name,
            'DB_USERNAME' => (string) $db->username,
            'DB_PASSWORD' => (string) $db->password,
            'DATABASE_URL' => $db->connectionUrl($host),
        ];
    }

    /**
     * The address $site should dial to reach $db:
     *  - same server  → loopback (127.0.0.1), or the stored host if customised
     *  - network peer → the peer server's private IP
     *  - otherwise    → the stored host (a public IP/hostname set deliberately)
     */
    private function effectiveDatabaseHost(ServerDatabase $db, Site $site): string
    {
        $siteServer = $site->server;
        $dbServer = $db->server;

        if ($siteServer !== null && $dbServer !== null && (string) $dbServer->id !== (string) $siteServer->id) {
            if ($this->sharePrivateNetwork($siteServer, $dbServer) && filled($dbServer->private_ip_address)) {
                return (string) $dbServer->private_ip_address;
            }
        }

        return (string) ($db->host ?: '127.0.0.1');
    }

    /**
     * Server IDs whose databases $server can reach: itself, plus every same-org
     * peer that shares a private network with it (see {@see sharePrivateNetwork}).
     * Membership is derived from the actual private IPs, not just the
     * private_network_id column — servers often have a private IP on the subnet
     * without that link being recorded.
     *
     * @return list<string>
     */
    private function reachableServerIds(Server $server): array
    {
        $ids = [(string) $server->id];

        if (blank($server->private_ip_address)) {
            return $ids; // No private interface → only its own (loopback) DBs.
        }

        $peers = Server::query()
            ->where('organization_id', $server->organization_id)
            ->whereKeyNot($server->id)
            ->whereNotNull('private_ip_address')
            ->get()
            ->filter(fn (Server $peer): bool => $this->sharePrivateNetwork($server, $peer))
            ->map(fn (Server $peer): string => (string) $peer->id)
            ->all();

        return array_values(array_unique([...$ids, ...$peers]));
    }

    /**
     * Whether two servers sit on the same private network and can reach each
     * other over their private IPs. True when they're linked to the same
     * PrivateNetwork row, OR a PrivateNetwork in the org has a CIDR covering
     * both private IPs, OR (no network row links them) the IPs share a /24.
     */
    private function sharePrivateNetwork(Server $a, Server $b): bool
    {
        if ((string) $a->organization_id !== (string) $b->organization_id) {
            return false;
        }

        $aIp = trim((string) $a->private_ip_address);
        $bIp = trim((string) $b->private_ip_address);
        if ($aIp === '' || $bIp === '') {
            return false;
        }

        if ($a->private_network_id !== null && (string) $a->private_network_id === (string) $b->private_network_id) {
            return true;
        }

        foreach (PrivateNetwork::query()->where('organization_id', $a->organization_id)->get() as $net) {
            $cidr = (string) $net->ip_range;
            if ($cidr !== '' && $this->ipInCidr($aIp, $cidr) && $this->ipInCidr($bIp, $cidr)) {
                return true;
            }
        }

        return $this->sameSubnet24($aIp, $bIp);
    }

    /** IPv4 CIDR-membership test. Non-IPv4 / unparseable inputs return false. */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return false;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : ((~0 << (32 - $bits)) & 0xFFFFFFFF);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /** Whether two IPv4 addresses share the same /24 subnet. */
    private function sameSubnet24(string $a, string $b): bool
    {
        $al = ip2long($a);
        $bl = ip2long($b);
        if ($al === false || $bl === false) {
            return false;
        }

        $mask = (~0 << 8) & 0xFFFFFFFF;

        return ($al & $mask) === ($bl & $mask);
    }

    // ---- redis ------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachRedis(Site $site, array $params): SiteBinding
    {
        $server = $site->server;
        if ($server === null) {
            throw new RuntimeException(__('This site has no server.'));
        }

        $reachable = $this->reachableServerIds($server);
        $query = ServerCacheService::query()
            ->whereIn('server_id', $reachable)
            ->whereIn('engine', ServerCacheService::FAMILY_REDIS_ENGINES)
            ->with('server:id,name,organization_id,private_ip_address,private_network_id');

        $targetId = (string) ($params['target_id'] ?? '');
        $svc = $targetId !== ''
            ? (clone $query)->whereKey($targetId)->first()
            // No explicit pick (e.g. legacy callers): prefer the local service.
            : (clone $query)->get()->sortBy(fn (ServerCacheService $s) => (string) $s->server_id === (string) $server->id ? 0 : 1)->first();

        if (! $svc instanceof ServerCacheService) {
            throw new RuntimeException(__('No Redis-compatible service is reachable. Install Redis/Valkey from the server Caches workspace, or add one to this private network.'));
        }

        $svcServer = $svc->server ?? $server;
        $crossServer = (string) $svc->server_id !== (string) $server->id;
        $host = $this->effectiveServiceHost($svcServer, $site);
        $port = (string) ($svc->port ?: ServerCacheService::defaultPortFor((string) $svc->engine));

        $env = array_filter([
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_HOST' => $host,
            'REDIS_PORT' => $port,
            'REDIS_PASSWORD' => filled($svc->auth_password) ? (string) $svc->auth_password : null,
            'REDIS_PREFIX' => filled($svc->cache_prefix) ? (string) $svc->cache_prefix : null,
        ], fn ($v) => $v !== null);

        return $this->persist($site, 'redis', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => (string) $svc->engine.($crossServer ? ' · '.($svcServer->name ?? '') : ''),
            'target_type' => 'server_cache_service',
            'target_id' => (string) $svc->id,
            'injected_env' => $env,
            'config' => array_filter([
                'engine' => (string) $svc->engine,
                'source_server_id' => $crossServer ? (string) $svc->server_id : null,
            ]),
        ]);
    }

    /**
     * Address $site should dial to reach a service on $serviceServer: loopback
     * when it's the site's own box, the server's private IP when it's a peer on
     * the same private network. Used for cache/redis hosts (databases have their
     * own variant that also honours a stored host).
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

        return '127.0.0.1';
    }

    // ---- queue ------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachQueue(Site $site, array $params): SiteBinding
    {
        $driver = strtolower(trim((string) ($params['driver'] ?? 'database')));
        if (! in_array($driver, ['database', 'redis'], true)) {
            throw new InvalidArgumentException(__('Queue driver must be database or redis.'));
        }

        return $this->persist($site, 'queue', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'queue-'.$driver,
            'target_type' => 'queue_driver',
            'target_id' => null,
            'injected_env' => ['QUEUE_CONNECTION' => $driver],
            'config' => ['driver' => $driver],
        ]);
    }

    // ---- cache ------------------------------------------------------------

    /**
     * Pick the cache store Laravel should use. Like the queue binding this is a
     * driver choice rather than an attached resource — it injects CACHE_STORE
     * (and the legacy CACHE_DRIVER alias so pre-11 apps pick it up too). Redis
     * needs the Redis binding attached to supply the connection variables.
     *
     * @param  array<string, mixed>  $params
     */
    private function attachCache(Site $site, array $params): SiteBinding
    {
        $driver = strtolower(trim((string) ($params['driver'] ?? 'database')));
        if (! in_array($driver, ['database', 'redis', 'file', 'array'], true)) {
            throw new InvalidArgumentException(__('Cache store must be database, redis, file, or array.'));
        }

        // Optional cache key prefix. Owned by the cache binding so it surfaces as
        // a managed CACHE_PREFIX row under the Cache resource rather than as a
        // loose editable variable. Left out when blank so the framework default
        // applies.
        $prefix = trim((string) ($params['prefix'] ?? ''));

        $injected = [
            'CACHE_STORE' => $driver,
            'CACHE_DRIVER' => $driver,
        ];
        if ($prefix !== '') {
            $injected['CACHE_PREFIX'] = $prefix;
        }

        return $this->persist($site, 'cache', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'cache-'.$driver,
            'target_type' => 'cache_driver',
            'target_id' => null,
            'injected_env' => $injected,
            // Persist the raw prefix so re-opening the modal prefills it.
            'config' => ['driver' => $driver, 'prefix' => $prefix],
        ]);
    }

    // ---- session ----------------------------------------------------------

    /**
     * Configure how sessions are stored + how the session cookie behaves. Like
     * queue/cache this is a config binding (no attached resource): it injects
     * the chosen SESSION_* keys. Every field is optional — a blank one is left
     * out so the framework default applies; redis needs the Redis binding too.
     *
     * @param  array<string, mixed>  $params
     */
    private function attachSession(Site $site, array $params): SiteBinding
    {
        $driver = strtolower(trim((string) ($params['driver'] ?? '')));
        if ($driver !== '' && ! in_array($driver, ['file', 'database', 'cookie', 'redis', 'memcached', 'array'], true)) {
            throw new InvalidArgumentException(__('Unsupported session driver.'));
        }

        // Form field => SESSION_* key. Only non-blank values are injected, so an
        // untouched field falls back to Laravel's config/session.php default.
        $candidates = [
            'SESSION_DRIVER' => $driver,
            'SESSION_LIFETIME' => trim((string) ($params['lifetime'] ?? '')),
            'SESSION_ENCRYPT' => trim((string) ($params['encrypt'] ?? '')),
            'SESSION_PATH' => trim((string) ($params['path'] ?? '')),
            'SESSION_DOMAIN' => trim((string) ($params['domain'] ?? '')),
            'SESSION_SECURE_COOKIE' => trim((string) ($params['secure_cookie'] ?? '')),
            'SESSION_HTTP_ONLY' => trim((string) ($params['http_only'] ?? '')),
            'SESSION_SAME_SITE' => trim((string) ($params['same_site'] ?? '')),
        ];
        $injected = array_filter($candidates, static fn (string $v): bool => $v !== '');

        $lifetime = $injected['SESSION_LIFETIME'] ?? null;
        if ($lifetime !== null && ! ctype_digit($lifetime)) {
            throw new InvalidArgumentException(__('Session lifetime must be a whole number of minutes.'));
        }
        if (($path = $injected['SESSION_PATH'] ?? null) !== null && ! str_starts_with($path, '/')) {
            throw new InvalidArgumentException(__('Session cookie path must start with "/".'));
        }

        return $this->persist($site, 'session', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'session-'.($driver !== '' ? $driver : 'custom'),
            'target_type' => 'session_driver',
            'target_id' => null,
            'injected_env' => $injected,
            // Persist the raw field values so re-opening the modal prefills them.
            'config' => [
                'driver' => $driver,
                'lifetime' => $candidates['SESSION_LIFETIME'],
                'encrypt' => $candidates['SESSION_ENCRYPT'],
                'path' => $candidates['SESSION_PATH'],
                'domain' => $candidates['SESSION_DOMAIN'],
                'secure_cookie' => $candidates['SESSION_SECURE_COOKIE'],
                'http_only' => $candidates['SESSION_HTTP_ONLY'],
                'same_site' => $candidates['SESSION_SAME_SITE'],
            ],
        ]);
    }

    // ---- object storage ---------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachStorage(Site $site, array $params): SiteBinding
    {
        $providers = (array) config('object_storage.providers', []);
        $provider = strtolower(trim((string) ($params['provider'] ?? 'aws_s3')));
        if (! array_key_exists($provider, $providers)) {
            throw new InvalidArgumentException(__('Unsupported object storage provider.'));
        }

        $bucket = trim((string) ($params['bucket'] ?? ''));

        $creds = $this->resolveStorageCredentials($site, $provider, $params);
        $key = $creds['key'];
        $secret = $creds['secret'];
        if ($bucket === '' || $key === '' || $secret === '') {
            throw new InvalidArgumentException(__('Bucket, access key, and secret are required.'));
        }

        $region = $creds['region'];

        // Endpoint resolution: an explicit endpoint always wins; otherwise derive
        // it from the provider's template (needs a region). AWS leaves the
        // template empty so AWS_ENDPOINT stays unset and the SDK picks the
        // regional endpoint itself. Custom providers must supply the endpoint.
        $endpoint = $creds['endpoint'];
        $template = (string) ($providers[$provider]['endpoint_template'] ?? '');
        if ($endpoint === '' && $template !== '' && $region !== '') {
            $endpoint = str_replace('{region}', $region, $template);
        }

        if ($provider === 'custom_s3' && $endpoint === '') {
            throw new InvalidArgumentException(__('Custom S3 storage needs an endpoint.'));
        }

        $binding = $this->persist($site, 'storage', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $bucket,
            'target_type' => 'object_storage',
            'target_id' => null,
            'injected_env' => $this->storageEnv($bucket, $key, $secret, $region, $endpoint, (string) ($params['url'] ?? '')),
            'config' => array_filter([
                'bucket' => $bucket,
                'provider' => $provider,
                'region' => $region ?: null,
            ]),
        ]);

        $this->maybeSaveStorageCredential($site, $provider, $params, $key, $secret, $region, $endpoint);

        return $binding;
    }

    /**
     * Provision a brand-new bucket on a provisionable S3 provider (DigitalOcean
     * Spaces / Hetzner) with the operator's storage keys, then wire it like an
     * attach. Mirrors {@see provisionDatabase}: a creation failure is recorded
     * on the binding (status=error, last_error) so it surfaces inline and can
     * be retried, then re-thrown for the toast.
     *
     * @param  array<string, mixed>  $params
     */
    private function provisionBucket(Site $site, array $params): SiteBinding
    {
        $providers = (array) config('object_storage.providers', []);
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        $meta = $providers[$provider] ?? null;
        if (! is_array($meta) || ! (bool) ($meta['provision'] ?? false)) {
            throw new InvalidArgumentException(__('This provider does not support provisioning a bucket yet.'));
        }

        $bucket = strtolower(trim((string) ($params['bucket'] ?? '')));

        // S3 bucket names: DNS-compliant, 3–63 chars, lowercase, no underscores
        // (so virtual-hosted URLs resolve). Validate before minting any keys.
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket) !== 1) {
            throw new InvalidArgumentException(__('Bucket name must be 3–63 characters: lowercase letters, numbers, dots, or hyphens.'));
        }

        // Two ways to get the S3 keys: have dply mint them via the provider's
        // cloud API token (DigitalOcean Spaces), or use saved/typed keys
        // (Hetzner, or DO when the operator prefers their own keys).
        $apiManaged = (bool) ($meta['api_managed'] ?? false);
        $keySource = (string) ($params['key_source'] ?? ($apiManaged ? 'api' : 'manual'));
        $autoMinted = $apiManaged && $keySource === 'api';

        if ($autoMinted) {
            $minted = $this->mintApiManagedKeys($site, $provider, $bucket, $params);
            $key = $minted['key'];
            $secret = $minted['secret'];
            $region = trim((string) ($params['region'] ?? ''));
        } else {
            $creds = $this->resolveStorageCredentials($site, $provider, $params);
            $key = $creds['key'];
            $secret = $creds['secret'];
            $region = $creds['region'];

            if ($key === '' || $secret === '') {
                throw new InvalidArgumentException(__('Storage access key and secret are required to provision a bucket.'));
            }
        }

        // Nothing exists on the provider until CreateBucket succeeds, so a
        // failure just bubbles up to the toast — unlike provisionDatabase, we
        // don't persist an error row, which would clobber an existing working
        // storage binding (one row per site+type).
        $result = $this->bucketProvisioner->create($provider, $region, $key, $secret, $bucket);
        $endpoint = (string) ($result['endpoint'] ?? '');

        $binding = $this->persist($site, 'storage', [
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $bucket,
            'target_type' => 'object_storage',
            'target_id' => null,
            'injected_env' => $this->storageEnv($bucket, $key, $secret, $region, $endpoint, (string) ($params['url'] ?? '')),
            'config' => array_filter([
                'bucket' => $bucket,
                'provider' => $provider,
                'region' => $region ?: null,
                'api_managed' => $autoMinted ?: null,
            ]),
            'last_error' => null,
        ]);

        // API-minted keys aren't from the form, so there's nothing to "save for
        // reuse" — the binding already carries them.
        if (! $autoMinted) {
            $this->maybeSaveStorageCredential($site, $provider, $params, $key, $secret, $region, $endpoint);
        }

        return $binding;
    }

    /**
     * Mint S3 keys for an api_managed provider (DigitalOcean Spaces) via the
     * org's cloud API token, so the operator never pastes keys.
     *
     * @param  array<string, mixed>  $params
     * @return array{key: string, secret: string}
     */
    private function mintApiManagedKeys(Site $site, string $provider, string $bucket, array $params): array
    {
        $meta = (array) config('object_storage.providers.'.$provider, []);
        $apiProvider = (string) ($meta['api_provider'] ?? '');
        if (! (bool) ($meta['api_managed'] ?? false) || $apiProvider === '') {
            throw new InvalidArgumentException(__('This provider cannot create keys automatically.'));
        }

        $credentialId = trim((string) ($params['provider_credential_id'] ?? ''));
        $query = ProviderCredential::query()
            ->where('organization_id', $site->organization_id)
            ->where('provider', $apiProvider);
        $credential = $credentialId !== ''
            ? (clone $query)->whereKey($credentialId)->first()
            : (clone $query)->orderBy('created_at')->first();

        if (! $credential instanceof ProviderCredential) {
            throw new InvalidArgumentException(__('Connect a :provider API token under Credentials to create keys automatically, or switch to entering keys manually.', ['provider' => (string) ($meta['label'] ?? $apiProvider)]));
        }

        return match ($apiProvider) {
            'digitalocean' => $this->mintDigitalOceanSpacesKey($credential, $bucket),
            default => throw new InvalidArgumentException(__('Automatic key creation is not supported for this provider yet.')),
        };
    }

    /**
     * @return array{key: string, secret: string}
     */
    private function mintDigitalOceanSpacesKey(ProviderCredential $credential, string $bucket): array
    {
        // Full-access key (empty grants) so it can create the bucket — the same
        // privilege level as a console-created Spaces key. DO returns the secret
        // only at creation time; it lives on the binding's encrypted env after.
        $minted = (new DigitalOceanService($credential))->createSpacesKey('dply-'.$bucket, []);

        return ['key' => $minted['access_key'], 'secret' => $minted['secret_key']];
    }

    /**
     * Resolve the S3 keys + region/endpoint for a storage binding: either a
     * saved {@see ObjectStorageCredential} (chosen via $params['credential_id'],
     * scoped to the site's org and provider) or the keys typed into the form.
     * Form region/endpoint always win over the saved credential's stored
     * defaults so the operator's picks aren't silently overridden.
     *
     * @param  array<string, mixed>  $params
     * @return array{key: string, secret: string, region: string, endpoint: string}
     */
    private function resolveStorageCredentials(Site $site, string $provider, array $params): array
    {
        $formRegion = trim((string) ($params['region'] ?? ''));
        $formEndpoint = trim((string) ($params['endpoint'] ?? ''));

        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = ObjectStorageCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof ObjectStorageCredential) {
                throw new InvalidArgumentException(__('That saved storage credential is no longer available.'));
            }

            return [
                'key' => (string) $cred->access_key_id,
                'secret' => (string) $cred->secret_access_key,
                'region' => $formRegion !== '' ? $formRegion : (string) ($cred->region ?? ''),
                'endpoint' => $formEndpoint !== '' ? $formEndpoint : (string) ($cred->endpoint ?? ''),
            ];
        }

        return [
            'key' => trim((string) ($params['access_key_id'] ?? '')),
            'secret' => trim((string) ($params['secret_access_key'] ?? '')),
            'region' => $formRegion,
            'endpoint' => $formEndpoint,
        ];
    }

    /**
     * Persist the entered keys as a reusable {@see ObjectStorageCredential} when
     * the operator ticked "save for reuse". No-op when reusing an existing
     * saved credential or when saving wasn't requested.
     *
     * @param  array<string, mixed>  $params
     */
    private function maybeSaveStorageCredential(Site $site, string $provider, array $params, string $key, string $secret, string $region, string $endpoint): void
    {
        if (! (bool) ($params['save_credential'] ?? false)) {
            return;
        }
        // Reusing a saved credential — nothing new to store.
        if (trim((string) ($params['credential_id'] ?? '')) !== '') {
            return;
        }
        if ($key === '' || $secret === '') {
            return;
        }

        $name = trim((string) ($params['credential_name'] ?? ''));
        if ($name === '') {
            $label = (string) (config('object_storage.providers.'.$provider.'.label') ?? $provider);
            $name = $label.' '.__('keys');
        }

        ObjectStorageCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'access_key_id' => $key,
            'secret_access_key' => $secret,
            'region' => $region !== '' ? $region : null,
            'endpoint' => $endpoint !== '' ? $endpoint : null,
        ]);
    }

    /**
     * Build the S3 connection variables a storage binding injects at deploy.
     * Shared by attach and provision so both wire identical AWS_* keys.
     *
     * @return array<string, string>
     */
    private function storageEnv(string $bucket, string $key, string $secret, string $region, string $endpoint, string $url): array
    {
        return array_filter([
            'FILESYSTEM_DISK' => 's3',
            'AWS_BUCKET' => $bucket,
            'AWS_ACCESS_KEY_ID' => $key,
            'AWS_SECRET_ACCESS_KEY' => $secret,
            'AWS_DEFAULT_REGION' => $region !== '' ? $region : null,
            'AWS_URL' => trim($url) !== '' ? trim($url) : null,
            'AWS_ENDPOINT' => $endpoint !== '' ? $endpoint : null,
        ], fn ($v) => $v !== null);
    }

    // ---- scheduler / workers (marker bindings) ----------------------------

    private function attachMarker(Site $site, string $type): SiteBinding
    {
        return $this->persist($site, $type, [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $type,
            'target_type' => null,
            'target_id' => null,
            'injected_env' => [],
            'config' => [],
        ]);
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(Site $site, string $type, array $attributes): SiteBinding
    {
        return SiteBinding::query()->updateOrCreate(
            ['site_id' => $site->id, 'type' => $type],
            $attributes,
        );
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, SiteBinding::TYPES, true)) {
            throw new InvalidArgumentException(__('Unknown binding type.'));
        }
    }
}
