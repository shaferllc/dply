<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Actions\Realtime\CreateRealtimeApp;
use App\Jobs\EnsureSitePhpDatabaseDriverJob;
use App\Models\LogDrainCredential;
use App\Models\MailCredential;
use App\Models\ObjectStorageCredential;
use App\Models\Organization;
use App\Models\PrivateNetwork;
use App\Models\ProviderCredential;
use App\Models\RealtimeApp;
use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use App\Services\DigitalOceanService;
use App\Services\Logging\LoggingSpec;
use App\Services\Logging\LoggingSpecValidator;
use App\Services\Realtime\RealtimeBackendFactory;
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
            'broadcasting' => $this->attachableRealtimeApps($site),
            default => [],
        };
    }

    /**
     * Managed broadcasting apps in the site's org an operator can attach (share
     * one app across sites). Active + still-provisioning apps are listed so a
     * freshly-created app shows up immediately.
     *
     * @return list<array{id: string, label: string}>
     */
    private function attachableRealtimeApps(Site $site): array
    {
        return RealtimeApp::query()
            ->where('organization_id', $site->organization_id)
            ->whereIn('status', [RealtimeApp::STATUS_ACTIVE, RealtimeApp::STATUS_PROVISIONING])
            ->orderBy('name')
            ->get()
            ->map(fn (RealtimeApp $app): array => [
                'id' => (string) $app->id,
                'label' => $app->name.' · '.$app->tierConfig()['label'],
            ])
            ->all();
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
                    'engine' => (string) $db->engine,
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
            'logging' => $this->attachLogging($site, $params),
            'mail' => $this->attachMail($site, $params),
            'broadcasting' => $this->attachBroadcasting($site, $params),
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

        // Keys to pull out of the loose .env: the ones this binding injects,
        // plus any extra keys the binding type fully OWNS. For mail that's the
        // whole MAIL_* namespace + provider keys — so attaching a Mailgun
        // binding also clears a previous SMTP scaffold's MAIL_HOST/PORT/etc.
        // rather than leaving stale, now-ignored rows behind.
        $toRemove = array_unique([...array_keys($injected), ...$this->ownedEnvKeys($binding)]);

        $removed = [];
        foreach ($toRemove as $key) {
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

    /**
     * Re-adopt every attached binding's keys out of the site's .env cache. A
     * sync-from-server rewrites the cache with the raw server .env, which
     * re-introduces keys an attached binding owns (REDIS_*, MAIL_*, DB_*, …) as
     * loose editable rows — even though the binding injects them at deploy. This
     * strips them back out so they stay managed under their resource instead of
     * bouncing into the variables list after each sync. Call it right after the
     * synced content is written.
     *
     * @return list<string> every key removed across all bindings
     */
    public function reAdoptAll(Site $site): array
    {
        $removed = [];
        // adoptInjectedEnv mutates + saves $site in place, so each iteration sees
        // the trimmed content from the previous one.
        foreach ($site->loadMissing('bindings')->bindings as $binding) {
            $removed = [...$removed, ...$this->adoptInjectedEnv($site, $binding)];
        }

        return array_values(array_unique($removed));
    }

    /**
     * Extra .env keys a binding type fully OWNS beyond the ones it injects, so
     * attaching it cleans stale loose vars out of the editable list (the binding
     * becomes the single source of truth). Only mail claims a namespace today.
     *
     * AWS_* is deliberately excluded for `ses` — those keys are shared with the
     * object-storage binding, so the mail binding must not strip them.
     *
     * @return list<string>
     */
    private function ownedEnvKeys(SiteBinding $binding): array
    {
        return match ($binding->type) {
            'mail' => [
                'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
                'MAIL_SCHEME', 'MAIL_ENCRYPTION', 'MAIL_URL', 'MAIL_EHLO_DOMAIN',
                'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
                'MAILGUN_DOMAIN', 'MAILGUN_SECRET', 'MAILGUN_ENDPOINT',
                'POSTMARK_TOKEN', 'POSTMARK_MESSAGE_STREAM_ID',
                'RESEND_KEY',
            ],
            // Storage fully owns FILESYSTEM_DISK — attaching it sets the disk to
            // s3, so the loose default (local) should be cleared.
            'storage' => ['FILESYSTEM_DISK'],
            // Broadcasting fully owns BROADCAST_CONNECTION — the binding is the
            // single source of truth for the driver, so a loose copy is stale.
            'broadcasting' => ['BROADCAST_CONNECTION'],
            default => [],
        };
    }

    public function detach(SiteBinding $binding): void
    {
        // Broadcasting tears down its external infra on detach (KV record +
        // billing), but only when no other site still binds the same app.
        if ($binding->type === 'broadcasting') {
            $this->teardownBroadcasting($binding);
        }

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

        // --- Read replica ---
        $replicaHost = null;
        $replicaPort = null;
        $replicaUsername = null;
        $replicaPassword = null;
        $replicaType = strtolower(trim((string) ($params['read_replica_type'] ?? '')));

        if ($replicaType === 'managed') {
            $replicaId = (string) ($params['read_replica_id'] ?? '');
            if ($replicaId !== '') {
                $replicaDb = ServerDatabase::query()
                    ->whereIn('server_id', $this->reachableServerIds($server))
                    ->whereKey($replicaId)
                    ->first();
                if ($replicaDb instanceof ServerDatabase) {
                    $replicaHost = $this->effectiveDatabaseHost($replicaDb, $site);
                    $rPort = (string) $replicaDb->defaultPort();
                    $replicaPort = $rPort !== (string) $db->defaultPort() ? $rPort : null;
                    $rUser = (string) $replicaDb->username;
                    $replicaUsername = $rUser !== (string) $db->username ? $rUser : null;
                    $rPass = (string) $replicaDb->password;
                    $replicaPassword = $rPass !== (string) $db->password ? $rPass : null;
                }
            }
        } elseif ($replicaType === 'manual') {
            $h = trim((string) ($params['read_replica_host'] ?? ''));
            $replicaHost = $h !== '' ? $h : null;
            $p = trim((string) ($params['read_replica_port'] ?? ''));
            $replicaPort = $p !== '' ? $p : null;
            $u = trim((string) ($params['read_replica_username'] ?? ''));
            $replicaUsername = $u !== '' ? $u : null;
            $pw = (string) ($params['read_replica_password'] ?? '');
            $replicaPassword = $pw !== '' ? $pw : null;
        }

        // --- Tuning options ---
        $opts = [
            'prefix' => trim((string) ($params['db_prefix'] ?? '')),
            'charset' => trim((string) ($params['db_charset'] ?? '')),
            'collation' => trim((string) ($params['db_collation'] ?? '')),
            'strict' => trim((string) ($params['db_strict'] ?? '')),
            'storage_engine' => trim((string) ($params['db_engine'] ?? '')),
            'socket' => trim((string) ($params['db_socket'] ?? '')),
            'schema' => trim((string) ($params['db_schema'] ?? '')),
            'sslmode' => trim((string) ($params['db_sslmode'] ?? '')),
            'timezone' => trim((string) ($params['db_timezone'] ?? '')),
            'read_replica_host' => $replicaHost,
            'read_replica_port' => $replicaPort,
            'read_replica_username' => $replicaUsername,
            'read_replica_password' => $replicaPassword,
        ];

        $binding = $this->persist($site, 'database', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $db->name,
            'target_type' => 'server_database',
            'target_id' => (string) $db->id,
            'injected_env' => $this->databaseEnv($db, $site, $opts),
            'config' => array_filter([
                'engine' => $db->engine,
                // Cross-server attachments carry the source so the UI can show
                // a "network peer" badge and warn when remote access is off.
                'source_server_id' => $crossServer ? (string) $db->server_id : null,
                'needs_remote_access' => ($crossServer && ! $db->remote_access) ? true : null,
                // Read replica (password stored only in encrypted injected_env)
                'read_replica_type' => ($replicaHost !== null && $replicaType !== '') ? $replicaType : null,
                'read_replica_id' => ($replicaType === 'managed' && $replicaHost !== null) ? (string) ($params['read_replica_id'] ?? '') : null,
                'read_replica_host' => ($replicaType === 'manual' && $replicaHost !== null) ? $replicaHost : null,
                'read_replica_port' => $replicaPort ?: null,
                'read_replica_username' => $replicaUsername ?: null,
                // Tuning options (all optional)
                'db_prefix' => $opts['prefix'] ?: null,
                'db_charset' => $opts['charset'] ?: null,
                'db_collation' => $opts['collation'] ?: null,
                'db_strict' => $opts['strict'] ?: null,
                'db_engine' => $opts['storage_engine'] ?: null,
                'db_socket' => $opts['socket'] ?: null,
                'db_schema' => $opts['schema'] ?: null,
                'db_sslmode' => $opts['sslmode'] ?: null,
                'db_timezone' => $opts['timezone'] ?: null,
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
     * $options keys (all optional, blank = use framework default):
     *   read_replica_host, read_replica_port, read_replica_username, read_replica_password
     *   prefix, charset, collation, strict, storage_engine, socket (MySQL)
     *   schema, sslmode (Postgres)
     *   timezone (all)
     *
     * @param  array<string, string|null>  $options
     * @return array<string, string>
     */
    private function databaseEnv(ServerDatabase $db, Site $site, array $options = []): array
    {
        if ($db->engine === 'sqlite') {
            return array_filter([
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => (string) ($db->host ?: ''),
                'DATABASE_URL' => $db->connectionUrl(),
                'DB_PREFIX' => ($options['prefix'] ?? '') ?: null,
                'DB_TIMEZONE' => ($options['timezone'] ?? '') ?: null,
            ], fn ($v) => $v !== null);
        }

        $host = $this->effectiveDatabaseHost($db, $site);
        $connection = $db->engine === 'postgres' ? 'pgsql' : 'mysql';

        $env = [
            'DB_CONNECTION' => $connection,
            'DB_HOST' => $host,
            'DB_PORT' => (string) $db->defaultPort(),
            'DB_DATABASE' => (string) $db->name,
            'DB_USERNAME' => (string) $db->username,
            'DB_PASSWORD' => (string) $db->password,
            'DATABASE_URL' => $db->connectionUrl($host),
        ];

        // Read replica — inject DB_READ_HOST and DB_STICKY; optional overrides
        // for port/username/password only when they differ from the primary.
        $readHost = (string) ($options['read_replica_host'] ?? '');
        if ($readHost !== '') {
            $env['DB_READ_HOST'] = $readHost;
            if (($options['read_replica_port'] ?? '') !== '') {
                $env['DB_READ_PORT'] = (string) $options['read_replica_port'];
            }
            if (($options['read_replica_username'] ?? '') !== '') {
                $env['DB_READ_USERNAME'] = (string) $options['read_replica_username'];
            }
            if (($options['read_replica_password'] ?? '') !== '') {
                $env['DB_READ_PASSWORD'] = (string) $options['read_replica_password'];
            }
            $env['DB_STICKY'] = 'true';
        }

        // Per-connection tuning shared by MySQL and Postgres
        if (($options['prefix'] ?? '') !== '') { $env['DB_PREFIX'] = (string) $options['prefix']; }
        if (($options['timezone'] ?? '') !== '') { $env['DB_TIMEZONE'] = (string) $options['timezone']; }

        if ($connection === 'mysql') {
            if (($options['charset'] ?? '') !== '') { $env['DB_CHARSET'] = (string) $options['charset']; }
            if (($options['collation'] ?? '') !== '') { $env['DB_COLLATION'] = (string) $options['collation']; }
            if (($options['strict'] ?? '') !== '') { $env['DB_STRICT'] = (string) $options['strict']; }
            if (($options['storage_engine'] ?? '') !== '') { $env['DB_ENGINE'] = (string) $options['storage_engine']; }
            if (($options['socket'] ?? '') !== '') { $env['DB_SOCKET'] = (string) $options['socket']; }
        }

        if ($connection === 'pgsql') {
            if (($options['charset'] ?? '') !== '') { $env['DB_CHARSET'] = (string) $options['charset']; }
            if (($options['schema'] ?? '') !== '') { $env['DB_SCHEMA'] = (string) $options['schema']; }
            if (($options['sslmode'] ?? '') !== '') { $env['DB_SSLMODE'] = (string) $options['sslmode']; }
        }

        return $env;
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
        $this->assertDriverDependency($site, __('the queue'), $driver);

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

    /**
     * Guard a driver-style binding (queue/cache/session) against a missing
     * dependency. The `redis` driver only injects QUEUE_CONNECTION/CACHE_STORE/
     * SESSION_DRIVER=redis — the actual REDIS_HOST/PORT/PASSWORD come from a
     * Redis binding. Without one, the config saves, the app boots, then dies at
     * runtime the first time it touches the store. Block it up front with a
     * message that says what to do instead. (We only enforce Redis: a database
     * connection commonly already exists via server defaults or loose DB_* env,
     * so requiring a database binding would false-positive too often.)
     */
    private function assertDriverDependency(Site $site, string $resource, string $driver): void
    {
        if ($driver !== 'redis') {
            return;
        }
        if (! $site->bindings()->where('type', 'redis')->exists()) {
            throw new InvalidArgumentException(__(
                'Attach a Redis resource before setting :resource to the redis driver — Redis supplies REDIS_HOST and the connection credentials.',
                ['resource' => $resource],
            ));
        }
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
        $this->assertDriverDependency($site, __('the cache store'), $driver);

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
    /** Framework defaults (config/session.php) materialized for a blank field. */
    private const SESSION_DEFAULTS = [
        'SESSION_DRIVER' => 'database',
        'SESSION_LIFETIME' => '120',
        'SESSION_ENCRYPT' => 'false',
        'SESSION_PATH' => '/',
        'SESSION_DOMAIN' => 'null',
        'SESSION_SECURE_COOKIE' => 'false',
        'SESSION_HTTP_ONLY' => 'true',
        'SESSION_SAME_SITE' => 'lax',
    ];

    private function attachSession(Site $site, array $params): SiteBinding
    {
        // Inject the FULL session config: every SESSION_* key gets a value —
        // the operator's choice, or the framework default when left blank — so
        // the binding is one complete, explicit snapshot rather than a partial
        // set of overrides.
        $raw = [
            'SESSION_DRIVER' => strtolower(trim((string) ($params['driver'] ?? ''))),
            'SESSION_LIFETIME' => trim((string) ($params['lifetime'] ?? '')),
            'SESSION_ENCRYPT' => trim((string) ($params['encrypt'] ?? '')),
            'SESSION_PATH' => trim((string) ($params['path'] ?? '')),
            'SESSION_DOMAIN' => trim((string) ($params['domain'] ?? '')),
            'SESSION_SECURE_COOKIE' => trim((string) ($params['secure_cookie'] ?? '')),
            'SESSION_HTTP_ONLY' => trim((string) ($params['http_only'] ?? '')),
            'SESSION_SAME_SITE' => trim((string) ($params['same_site'] ?? '')),
        ];

        $injected = [];
        foreach (self::SESSION_DEFAULTS as $key => $default) {
            $injected[$key] = $raw[$key] === '' ? $default : $raw[$key];
        }

        if (! in_array($injected['SESSION_DRIVER'], ['file', 'database', 'cookie', 'redis', 'memcached', 'array'], true)) {
            throw new InvalidArgumentException(__('Unsupported session driver.'));
        }
        $this->assertDriverDependency($site, __('sessions'), $injected['SESSION_DRIVER']);
        if (! ctype_digit($injected['SESSION_LIFETIME'])) {
            throw new InvalidArgumentException(__('Session lifetime must be a whole number of minutes.'));
        }
        if (! str_starts_with($injected['SESSION_PATH'], '/')) {
            throw new InvalidArgumentException(__('Session cookie path must start with "/".'));
        }

        return $this->persist($site, 'session', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'session-'.$injected['SESSION_DRIVER'],
            'target_type' => 'session_driver',
            'target_id' => null,
            'injected_env' => $injected,
            // Persist the RAW field values (blank where the operator defaulted) so
            // re-opening the modal shows their choices with placeholders standing
            // in for the blanks, not the materialized defaults.
            'config' => [
                'driver' => $raw['SESSION_DRIVER'],
                'lifetime' => $raw['SESSION_LIFETIME'],
                'encrypt' => $raw['SESSION_ENCRYPT'],
                'path' => $raw['SESSION_PATH'],
                'domain' => $raw['SESSION_DOMAIN'],
                'secure_cookie' => $raw['SESSION_SECURE_COOKIE'],
                'http_only' => $raw['SESSION_HTTP_ONLY'],
                'same_site' => $raw['SESSION_SAME_SITE'],
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
        // Auto-minted keys (DO Spaces) aren't active on the S3 gateway for a few
        // seconds, so let the provisioner retry the rejection codes in that case.
        $result = $this->bucketProvisioner->create($provider, $region, $key, $secret, $bucket, awaitKeyPropagation: $autoMinted);
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
        // Full-access key so it can create the bucket — createSpacesKey() turns
        // an empty grant list into an explicit full-access grant (a DO Spaces key
        // with NO grants has NO access). DO returns the secret only at creation
        // time; it lives on the binding's encrypted env after.
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

    // ---- logging ----------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachLogging(Site $site, array $params): SiteBinding
    {
        $validProviders = ['papertrail', 'logtail', 'syslog', 'dply_realtime'];
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));
        if (! in_array($provider, $validProviders, true)) {
            throw new InvalidArgumentException(__('Unsupported log drain provider.'));
        }

        $creds = $this->resolveLogDrainCredentials($site, $provider, $params);
        $this->validateLogDrainCredentials($provider, $creds);

        // Build the v2 logging spec (the structure dply will own and generate
        // into config/logging.php from Phase 2 on) behaviour-preservingly from
        // the single-provider form. We store it on `config` now so the data is
        // ready ahead of the generator/overlay; `injected_env` stays the legacy
        // drain env so nothing about today's behaviour changes yet. The
        // transitional `provider` key keeps the current modal's form binding
        // working until the Phase 3 editor replaces it.
        $spec = LoggingSpec::fromLegacyProvider($provider, $creds);
        (new LoggingSpecValidator)->validate($spec);

        $binding = $this->persist($site, 'logging', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->logDrainLabel($provider, $creds),
            'target_type' => 'log_drain',
            'target_id' => null,
            'injected_env' => $this->logDrainEnv($provider, $creds),
            'config' => ['provider' => $provider] + $spec,
            'last_error' => null,
        ]);

        $this->maybeSaveLogDrainCredential($site, $provider, $params, $creds);

        return $binding;
    }

    /**
     * Persist a full v2 logging spec from the Phase 3 editor. The spec (the
     * secret-free structure dply generates into config/logging.php) is stored on
     * `config`; the secret leaf values are written to `injected_env` keyed by
     * each channel's env map, so the generated file's `env(...)` references
     * resolve. Secrets the operator left blank on an edit are preserved from the
     * existing binding rather than wiped.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, array<string, string>>  $secrets  [channelName][field] => value
     */
    public function saveLoggingSpec(Site $site, array $spec, array $secrets = []): SiteBinding
    {
        $spec['version'] = LoggingSpec::VERSION;
        (new LoggingSpecValidator)->validate($spec);

        $existing = $site->bindings->firstWhere('type', 'logging');
        $existingEnv = ($existing instanceof SiteBinding && is_array($existing->injected_env)) ? $existing->injected_env : [];

        return $this->persist($site, 'logging', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->loggingSpecLabel($spec),
            'target_type' => 'log_drain',
            'target_id' => null,
            'injected_env' => $this->loggingInjectedEnvFromSpec($spec, $secrets, $existingEnv, $site),
            'config' => $spec,
            'last_error' => null,
        ]);
    }

    /**
     * The site's stable dply Realtime routing token, minted on first use. The
     * generated SyslogUdpHandler stamps it as the syslog ident and the drain
     * receiver maps datagrams back to the site by it.
     */
    private function ensureLogDrainToken(Site $site): string
    {
        $token = trim((string) ($site->log_drain_token ?? ''));
        if ($token === '') {
            $token = 'dly_'.Str::lower(Str::random(40));
            $site->forceFill(['log_drain_token' => $token])->save();
        }

        return $token;
    }

    /**
     * Build the secret env map a spec injects. Each channel's `env` map names
     * the env key per secret field; the value comes from $secrets, falling back
     * to the previously-stored value when the operator didn't re-enter it.
     * dply Realtime is special: its endpoint comes from config, not the form.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, array<string, string>>  $secrets
     * @param  array<string, string>  $existingEnv
     * @return array<string, string>
     */
    private function loggingInjectedEnvFromSpec(array $spec, array $secrets, array $existingEnv, Site $site): array
    {
        $env = [];
        foreach ((array) ($spec['channels'] ?? []) as $channel) {
            if (! is_array($channel)) {
                continue;
            }
            $name = (string) ($channel['name'] ?? '');
            $type = (string) ($channel['type'] ?? '');
            $envMap = is_array($channel['env'] ?? null) ? $channel['env'] : [];

            if ($type === 'dply_realtime') {
                // Endpoint comes from config; the routing token from the site.
                foreach (['host' => 'host', 'port' => 'port'] as $field => $cfgKey) {
                    if (isset($envMap[$field])) {
                        $env[$envMap[$field]] = (string) config('log_drains.dply_realtime.'.$cfgKey, '');
                    }
                }
                if (isset($envMap['token'])) {
                    $env[$envMap['token']] = $this->ensureLogDrainToken($site);
                }

                continue;
            }

            foreach ($envMap as $field => $key) {
                $key = (string) $key;
                $new = trim((string) ($secrets[$name][$field] ?? ''));
                $value = $new !== '' ? $new : (string) ($existingEnv[$key] ?? '');
                if ($value !== '') {
                    $env[$key] = $value;
                }
            }
        }

        return $env;
    }

    /** @param  array<string, mixed>  $spec */
    private function loggingSpecLabel(array $spec): string
    {
        $channels = (array) ($spec['channels'] ?? []);
        $count = count($channels);
        $default = (string) ($spec['default'] ?? '');

        return $count === 1
            ? __('Logging · :default', ['default' => $default])
            : __('Logging · :count channels (default :default)', ['count' => $count, 'default' => $default]);
    }

    /**
     * Resolve drain credentials: from a saved LogDrainCredential when
     * $params['credential_id'] is set, otherwise from the typed form fields.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function resolveLogDrainCredentials(Site $site, string $provider, array $params): array
    {
        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = LogDrainCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof LogDrainCredential) {
                throw new InvalidArgumentException(__('That saved log drain credential is no longer available.'));
            }

            return is_array($cred->credentials) ? $cred->credentials : [];
        }

        return match ($provider) {
            'papertrail' => [
                'host' => trim((string) ($params['host'] ?? 'logs.papertrailapp.com')),
                'port' => trim((string) ($params['port'] ?? '')),
            ],
            'logtail' => [
                'source_token' => trim((string) ($params['source_token'] ?? '')),
            ],
            'syslog' => [
                'host' => trim((string) ($params['host'] ?? '')),
                'port' => trim((string) ($params['port'] ?? '514')),
            ],
            default => [],
        };
    }

    /** @param  array<string, string>  $creds */
    private function validateLogDrainCredentials(string $provider, array $creds): void
    {
        match ($provider) {
            'papertrail' => ($creds['port'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Papertrail port is required.'))
                : null,
            'logtail' => ($creds['source_token'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Logtail source token is required.'))
                : null,
            'syslog' => ($creds['host'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Syslog host is required.'))
                : null,
            default => null,
        };
    }

    /**
     * Build the env vars the logging binding injects at deploy.
     *
     * @param  array<string, string>  $creds
     * @return array<string, string>
     */
    private function logDrainEnv(string $provider, array $creds): array
    {
        return match ($provider) {
            'papertrail' => array_filter([
                'LOG_CHANNEL' => 'papertrail',
                'PAPERTRAIL_URL' => ($creds['host'] ?? '') ?: null,
                'PAPERTRAIL_PORT' => ($creds['port'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'logtail' => array_filter([
                'LOG_CHANNEL' => 'stack',
                'LOG_STACK' => 'single,logtail',
                'LOGTAIL_SOURCE_TOKEN' => ($creds['source_token'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'syslog' => ['LOG_CHANNEL' => 'syslog'],
            'dply_realtime' => array_filter([
                'LOG_CHANNEL' => 'papertrail',
                // PAPERTRAIL_* drives the stock channel on env-only hosts;
                // DPLY_LOG_DRAIN_* are the dedicated keys the generated overlay
                // file references (so dply Realtime never collides with a real
                // Papertrail channel). Emitting both is harmless.
                'PAPERTRAIL_URL' => (string) config('log_drains.dply_realtime.host', ''),
                'PAPERTRAIL_PORT' => (string) config('log_drains.dply_realtime.port', ''),
                'DPLY_LOG_DRAIN_HOST' => (string) config('log_drains.dply_realtime.host', ''),
                'DPLY_LOG_DRAIN_PORT' => (string) config('log_drains.dply_realtime.port', ''),
            ], fn ($v) => $v !== ''),
            default => [],
        };
    }

    /** @param  array<string, string>  $creds */
    private function logDrainLabel(string $provider, array $creds): string
    {
        return match ($provider) {
            'papertrail' => 'Papertrail'.($creds['port'] !== '' ? ' :'.$creds['port'] : ''),
            'logtail' => 'Logtail',
            'syslog' => 'Syslog'.($creds['host'] !== '' ? ' '.$creds['host'] : ''),
            'dply_realtime' => 'dply Realtime',
            default => $provider,
        };
    }

    /**
     * Persist typed credentials as a reusable LogDrainCredential when the
     * operator ticked "save for reuse". No-op when reusing a saved credential
     * or when the provider supplies no user credentials (dply_realtime).
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $creds
     */
    private function maybeSaveLogDrainCredential(Site $site, string $provider, array $params, array $creds): void
    {
        if (! (bool) ($params['save_credential'] ?? false)) {
            return;
        }
        if (trim((string) ($params['credential_id'] ?? '')) !== '') {
            return;
        }
        if ($provider === 'dply_realtime' || $creds === []) {
            return;
        }

        $name = trim((string) ($params['credential_name'] ?? ''));
        if ($name === '') {
            $labels = ['papertrail' => 'Papertrail', 'logtail' => 'Logtail', 'syslog' => 'Syslog'];
            $name = ($labels[$provider] ?? ucfirst($provider)).' drain';
        }

        LogDrainCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'credentials' => $creds,
        ]);
    }

    // ---- mail -------------------------------------------------------------

    /**
     * Providers whose transport ships as a separate Composer package the app
     * must already require (deploy runs the app's own `composer install`, so
     * dply can't add it). Keyed slug → package, surfaced as a note in the modal
     * and as the failure signal a test-send produces when the package is absent.
     *
     * @var array<string, string>
     */
    public const MAIL_TRANSPORT_PACKAGES = [
        'mailgun' => 'symfony/mailgun-mailer',
        'postmark' => 'symfony/postmark-mailer',
        'ses' => 'aws/aws-sdk-php',
        'resend' => 'resend/resend-laravel',
    ];

    /**
     * Configure how the app sends mail. Like logging this is a config binding
     * (no provisioned resource): it injects the chosen MAIL_* keys. The provider
     * secret/connection comes from a saved {@see MailCredential} or the typed
     * form; the from-address/name are always per-site and entered each time.
     *
     * @param  array<string, mixed>  $params
     */
    /** Single-transport mail providers (a failover chain is built from these). */
    public const MAIL_LEG_PROVIDERS = ['smtp', 'mailgun', 'postmark', 'ses', 'resend', 'log'];

    private function attachMail(Site $site, array $params): SiteBinding
    {
        $provider = strtolower(trim((string) ($params['provider'] ?? '')));

        // failover / roundrobin compose several single-transport "legs"; their
        // chain ORDER lives in the app's config/mail.php (it can't be expressed
        // in env), so here we only inject MAIL_MAILER + every leg's credentials.
        if (in_array($provider, ['failover', 'roundrobin'], true)) {
            return $this->attachFailoverMail($site, $provider, $params);
        }

        if (! in_array($provider, self::MAIL_LEG_PROVIDERS, true)) {
            throw new InvalidArgumentException(__('Unsupported mail provider.'));
        }

        $fromAddress = trim((string) ($params['from_address'] ?? ''));
        $fromName = trim((string) ($params['from_name'] ?? ''));
        if ($provider !== 'log' && ($fromAddress === '' || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidArgumentException(__('A valid "from" email address is required.'));
        }

        $creds = $this->resolveMailCredentials($site, $provider, $params);
        $this->validateMailCredentials($provider, $creds);

        $binding = $this->persist($site, 'mail', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $this->mailLabel($provider, $creds),
            'target_type' => 'mail_transport',
            'target_id' => null,
            'injected_env' => $this->mailEnv($provider, $creds, $fromAddress, $fromName),
            'config' => array_filter([
                'provider' => $provider,
                'from_address' => $fromAddress ?: null,
                'from_name' => $fromName ?: null,
            ]),
            'last_error' => null,
        ]);

        $this->maybeSaveMailCredential($site, $provider, $params, $creds);

        return $binding;
    }

    /**
     * Attach a failover or round-robin mail chain: inject MAIL_MAILER=<transport>
     * plus the merged credential env of every leg, so the app's failover mailer
     * (which it must define in config/mail.php) resolves each sub-mailer. The
     * leg ORDER is shown to the operator as a config/mail.php snippet — it's the
     * one piece we can't inject.
     *
     * @param  array<string, mixed>  $params
     */
    private function attachFailoverMail(Site $site, string $transport, array $params): SiteBinding
    {
        $fromAddress = trim((string) ($params['from_address'] ?? ''));
        $fromName = trim((string) ($params['from_name'] ?? ''));
        if ($fromAddress === '' || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException(__('A valid "from" email address is required.'));
        }

        $legsInput = is_array($params['legs'] ?? null) ? array_values($params['legs']) : [];

        $legs = [];          // ordered list of provider slugs (for config + label)
        $merged = [];        // union of every leg's transport env
        $sawSmtp = false;
        foreach ($legsInput as $leg) {
            if (! is_array($leg)) {
                continue;
            }
            $p = strtolower(trim((string) ($leg['provider'] ?? '')));
            if (! in_array($p, self::MAIL_LEG_PROVIDERS, true)) {
                continue;
            }
            // Only one SMTP leg is possible — Laravel's `smtp` mailer reads a
            // single MAIL_HOST/PASSWORD set, so two SMTP endpoints would collide.
            if ($p === 'smtp') {
                if ($sawSmtp) {
                    throw new InvalidArgumentException(__('A failover chain can include at most one SMTP mailer (they share the MAIL_* keys).'));
                }
                $sawSmtp = true;
            }

            $creds = $this->resolveMailCredentials($site, $p, $leg);
            if ($p !== 'log') {
                $this->validateMailCredentials($p, $creds);
            }

            // Drop the per-leg MAIL_MAILER/FROM — the chain owns those.
            $legEnv = $this->mailEnv($p, $creds, '', '');
            unset($legEnv['MAIL_MAILER']);
            $merged = [...$merged, ...$legEnv];
            $legs[] = $p;
        }

        if (count($legs) < 2) {
            throw new InvalidArgumentException(__('A failover chain needs at least two mailers.'));
        }

        $injected = [
            'MAIL_MAILER' => $transport,
            ...$merged,
            'MAIL_FROM_ADDRESS' => $fromAddress,
        ];
        if ($fromName !== '') {
            $injected['MAIL_FROM_NAME'] = $fromName;
        }

        return $this->persist($site, 'mail', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => ucfirst($transport).' ('.implode(' → ', $legs).')',
            'target_type' => 'mail_transport',
            'target_id' => null,
            'injected_env' => $injected,
            'config' => array_filter([
                'provider' => $transport,
                'legs' => $legs,
                'from_address' => $fromAddress ?: null,
                'from_name' => $fromName ?: null,
            ]),
            'last_error' => null,
        ]);
    }

    /**
     * Resolve transport credentials: from a saved MailCredential when
     * $params['credential_id'] is set, otherwise from the typed form fields.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function resolveMailCredentials(Site $site, string $provider, array $params): array
    {
        $credentialId = trim((string) ($params['credential_id'] ?? ''));
        if ($credentialId !== '') {
            $cred = MailCredential::query()
                ->where('organization_id', $site->organization_id)
                ->where('provider', $provider)
                ->whereKey($credentialId)
                ->first();

            if (! $cred instanceof MailCredential) {
                throw new InvalidArgumentException(__('That saved mail credential is no longer available.'));
            }

            return is_array($cred->credentials) ? $cred->credentials : [];
        }

        return match ($provider) {
            'smtp' => [
                'host' => trim((string) ($params['host'] ?? '')),
                'port' => trim((string) ($params['port'] ?? '587')),
                'username' => trim((string) ($params['username'] ?? '')),
                'password' => (string) ($params['password'] ?? ''),
                'encryption' => strtolower(trim((string) ($params['encryption'] ?? 'tls'))),
            ],
            'mailgun' => [
                'secret' => trim((string) ($params['secret'] ?? '')),
                'domain' => trim((string) ($params['domain'] ?? '')),
                'endpoint' => trim((string) ($params['endpoint'] ?? 'api.mailgun.net')),
            ],
            'postmark' => [
                'token' => trim((string) ($params['token'] ?? '')),
            ],
            'ses' => [
                'access_key_id' => trim((string) ($params['access_key_id'] ?? '')),
                'secret_access_key' => trim((string) ($params['secret_access_key'] ?? '')),
                'region' => trim((string) ($params['region'] ?? '')),
            ],
            'resend' => [
                'key' => trim((string) ($params['key'] ?? '')),
            ],
            default => [],
        };
    }

    /** @param  array<string, string>  $creds */
    private function validateMailCredentials(string $provider, array $creds): void
    {
        match ($provider) {
            'smtp' => ($creds['host'] ?? '') === ''
                ? throw new InvalidArgumentException(__('SMTP host is required.'))
                : null,
            'mailgun' => (($creds['secret'] ?? '') === '' || ($creds['domain'] ?? '') === '')
                ? throw new InvalidArgumentException(__('Mailgun secret and domain are required.'))
                : null,
            'postmark' => ($creds['token'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Postmark server token is required.'))
                : null,
            'ses' => (($creds['access_key_id'] ?? '') === '' || ($creds['secret_access_key'] ?? '') === '' || ($creds['region'] ?? '') === '')
                ? throw new InvalidArgumentException(__('SES access key, secret, and region are required.'))
                : null,
            'resend' => ($creds['key'] ?? '') === ''
                ? throw new InvalidArgumentException(__('Resend API key is required.'))
                : null,
            default => null,
        };
    }

    /**
     * Build the MAIL_* vars the mail binding injects at deploy. MAIL_MAILER
     * selects the transport; the provider-specific keys carry the secret; the
     * from-address/name are shared across providers.
     *
     * Note: `ses` reuses the AWS_* keys the object-storage binding also injects
     * — they are genuinely shared by the AWS SDK, so a site using both SES mail
     * and S3 storage must point both at the same AWS account (surfaced in the
     * modal copy rather than namespaced here).
     *
     * @param  array<string, string>  $creds
     * @return array<string, string>
     */
    private function mailEnv(string $provider, array $creds, string $fromAddress, string $fromName): array
    {
        $shared = array_filter([
            'MAIL_FROM_ADDRESS' => $fromAddress !== '' ? $fromAddress : null,
            'MAIL_FROM_NAME' => $fromName !== '' ? $fromName : null,
        ], fn ($v) => $v !== null);

        $transport = match ($provider) {
            'smtp' => array_filter([
                'MAIL_MAILER' => 'smtp',
                'MAIL_HOST' => ($creds['host'] ?? '') ?: null,
                'MAIL_PORT' => ($creds['port'] ?? '') ?: null,
                'MAIL_USERNAME' => ($creds['username'] ?? '') ?: null,
                'MAIL_PASSWORD' => ($creds['password'] ?? '') ?: null,
                // Laravel 11 reads MAIL_SCHEME (smtp/smtps); Laravel ≤10 reads
                // MAIL_ENCRYPTION (tls/ssl). Inject both so the binding works on
                // either generation — the unused key is harmlessly ignored.
                //   tls → STARTTLS  (scheme smtp,  encryption tls)
                //   ssl → implicit  (scheme smtps, encryption ssl)
                'MAIL_ENCRYPTION' => in_array($creds['encryption'] ?? '', ['tls', 'ssl'], true) ? $creds['encryption'] : null,
                'MAIL_SCHEME' => match ($creds['encryption'] ?? '') {
                    'ssl' => 'smtps',
                    'tls' => 'smtp',
                    default => null,
                },
            ], fn ($v) => $v !== null),
            'mailgun' => array_filter([
                'MAIL_MAILER' => 'mailgun',
                'MAILGUN_DOMAIN' => ($creds['domain'] ?? '') ?: null,
                'MAILGUN_SECRET' => ($creds['secret'] ?? '') ?: null,
                'MAILGUN_ENDPOINT' => ($creds['endpoint'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'postmark' => array_filter([
                'MAIL_MAILER' => 'postmark',
                'POSTMARK_TOKEN' => ($creds['token'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'ses' => array_filter([
                'MAIL_MAILER' => 'ses',
                'AWS_ACCESS_KEY_ID' => ($creds['access_key_id'] ?? '') ?: null,
                'AWS_SECRET_ACCESS_KEY' => ($creds['secret_access_key'] ?? '') ?: null,
                'AWS_DEFAULT_REGION' => ($creds['region'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'resend' => array_filter([
                'MAIL_MAILER' => 'resend',
                'RESEND_KEY' => ($creds['key'] ?? '') ?: null,
            ], fn ($v) => $v !== null),
            'log' => ['MAIL_MAILER' => 'log'],
            default => [],
        };

        return [...$transport, ...$shared];
    }

    /** @param  array<string, string>  $creds */
    private function mailLabel(string $provider, array $creds): string
    {
        return match ($provider) {
            'smtp' => 'SMTP'.(($creds['host'] ?? '') !== '' ? ' '.$creds['host'] : ''),
            'mailgun' => 'Mailgun'.(($creds['domain'] ?? '') !== '' ? ' '.$creds['domain'] : ''),
            'postmark' => 'Postmark',
            'ses' => 'Amazon SES'.(($creds['region'] ?? '') !== '' ? ' ('.$creds['region'].')' : ''),
            'resend' => 'Resend',
            'log' => 'Log (no delivery)',
            default => $provider,
        };
    }

    /**
     * Persist typed credentials as a reusable MailCredential when the operator
     * ticked "save for reuse". No-op when reusing a saved credential or for the
     * `log` provider (no credentials to store).
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $creds
     */
    private function maybeSaveMailCredential(Site $site, string $provider, array $params, array $creds): void
    {
        if (! (bool) ($params['save_credential'] ?? false)) {
            return;
        }
        if (trim((string) ($params['credential_id'] ?? '')) !== '') {
            return;
        }
        if ($provider === 'log' || $creds === []) {
            return;
        }

        $name = trim((string) ($params['credential_name'] ?? ''));
        if ($name === '') {
            $labels = ['smtp' => 'SMTP', 'mailgun' => 'Mailgun', 'postmark' => 'Postmark', 'ses' => 'Amazon SES', 'resend' => 'Resend'];
            $name = ($labels[$provider] ?? ucfirst($provider)).' '.__('mail keys');
        }

        MailCredential::query()->create([
            'organization_id' => $site->organization_id,
            'created_by_user_id' => auth()->id(),
            'provider' => $provider,
            'name' => Str::limit($name, 120, ''),
            'credentials' => $creds,
        ]);
    }

    // ---- broadcasting -----------------------------------------------------

    /** BYO broadcasting drivers the operator can wire their own credentials for. */
    private const BROADCAST_BYO_DRIVERS = ['pusher', 'reverb', 'ably', 'log', 'null'];

    /**
     * Configure how the app broadcasts. Two paths share one binding:
     *  - kind=managed → a dply-managed RealtimeApp (Cloudflare relay), either an
     *    existing app (shared across sites) or a freshly provisioned, billed one.
     *  - kind=byo     → the operator's own Pusher/Reverb/Ably (or log/null).
     * Both inject BROADCAST_CONNECTION + the driver's connection vars, plus the
     * VITE_ mirror so Laravel Echo works without hand-adding client vars.
     *
     * @param  array<string, mixed>  $params
     */
    private function attachBroadcasting(Site $site, array $params): SiteBinding
    {
        $kind = strtolower(trim((string) ($params['kind'] ?? 'managed')));

        return match ($kind) {
            'managed' => $this->attachManagedBroadcasting($site, $params),
            'byo' => $this->attachByoBroadcasting($site, $params),
            default => throw new InvalidArgumentException(__('Choose a broadcasting option.')),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachManagedBroadcasting(Site $site, array $params): SiteBinding
    {
        if ((bool) ($params['provision'] ?? false)) {
            return $this->provisionManagedBroadcasting($site, $params);
        }

        $appId = (string) ($params['realtime_app_id'] ?? '');
        if ($appId === '') {
            throw new InvalidArgumentException(__('Choose a broadcasting app to attach.'));
        }

        $app = RealtimeApp::query()
            ->where('organization_id', $site->organization_id)
            ->whereKey($appId)
            ->first();

        if (! $app instanceof RealtimeApp) {
            throw new InvalidArgumentException(__('That broadcasting app is not available.'));
        }

        return $this->persist($site, 'broadcasting', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $app->name,
            'target_type' => 'realtime_app',
            'target_id' => (string) $app->id,
            'injected_env' => $this->managedBroadcastingEnv($app),
            'config' => ['kind' => 'managed', 'tier' => $app->tierSlug()],
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function provisionManagedBroadcasting(Site $site, array $params): SiteBinding
    {
        // The dply relay is the one billed path — confirm the charge before we
        // create (and start billing) a new app.
        if (! (bool) ($params['confirm_charge'] ?? false)) {
            throw new InvalidArgumentException(__('Please confirm the monthly charge to provision a managed broadcasting app.'));
        }

        $org = Organization::query()->find($site->organization_id);
        if (! $org instanceof Organization) {
            throw new RuntimeException(__('This site has no organization to bill the broadcasting app to.'));
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            throw new RuntimeException(__('You must be signed in to provision a managed broadcasting app.'));
        }

        $tier = (string) ($params['tier'] ?? config('realtime.default_tier'));
        if (! array_key_exists($tier, (array) config('realtime.tiers', []))) {
            throw new InvalidArgumentException(__('Unknown broadcasting tier.'));
        }

        $name = trim((string) ($params['app_name'] ?? '')) ?: (string) ($site->name ?: $site->slug);

        // Creates the RealtimeApp (status: provisioning) and dispatches the
        // queued KV publish; credentials exist immediately so the env contract
        // is known up front and the binding is configured right away.
        $app = app(CreateRealtimeApp::class)->handle($user, $org, ['name' => $name, 'tier' => $tier]);

        return $this->persist($site, 'broadcasting', [
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $app->name,
            'target_type' => 'realtime_app',
            'target_id' => (string) $app->id,
            'injected_env' => $this->managedBroadcastingEnv($app),
            'config' => ['kind' => 'managed', 'tier' => $app->tierSlug()],
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachByoBroadcasting(Site $site, array $params): SiteBinding
    {
        $driver = strtolower(trim((string) ($params['driver'] ?? 'pusher')));
        if (! in_array($driver, self::BROADCAST_BYO_DRIVERS, true)) {
            throw new InvalidArgumentException(__('Unsupported broadcasting driver.'));
        }

        $env = $this->byoBroadcastingEnv($driver, $params);

        return $this->persist($site, 'broadcasting', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'broadcasting-'.$driver,
            'target_type' => 'broadcasting_byo',
            'target_id' => null,
            'injected_env' => $env,
            'config' => ['kind' => 'byo', 'driver' => $driver],
        ]);
    }

    /**
     * The env the dply-managed relay (Pusher protocol) injects: server vars plus
     * the VITE_ mirror for Echo. The signing secret is server-only — never
     * mirrored to a VITE_ (client) var.
     *
     * @return array<string, string>
     */
    private function managedBroadcastingEnv(RealtimeApp $app): array
    {
        $env = [
            'BROADCAST_CONNECTION' => 'pusher',
            'PUSHER_APP_ID' => (string) $app->id,
            'PUSHER_APP_KEY' => (string) $app->app_key,
            'PUSHER_APP_SECRET' => (string) $app->app_secret,
            'PUSHER_HOST' => $app->host(),
            'PUSHER_PORT' => '443',
            'PUSHER_SCHEME' => 'https',
            // pusher-js requires a non-empty cluster even when host is set; it's
            // ignored once PUSHER_HOST points at the relay.
            'PUSHER_APP_CLUSTER' => 'mt1',
        ];

        return [...$env, ...$this->broadcastingViteMirror($env)];
    }

    /**
     * Build the BYO env for a driver. pusher/reverb carry full connection vars;
     * ably a single key; log/null just flip BROADCAST_CONNECTION.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function byoBroadcastingEnv(string $driver, array $params): array
    {
        $p = fn (string $k): string => trim((string) ($params[$k] ?? ''));

        $env = match ($driver) {
            'pusher' => $this->validatedPusherEnv($p),
            'reverb' => $this->validatedReverbEnv($p),
            'ably' => $this->validatedAblyEnv($p),
            'log' => ['BROADCAST_CONNECTION' => 'log'],
            'null' => ['BROADCAST_CONNECTION' => 'null'],
            default => throw new InvalidArgumentException(__('Unsupported broadcasting driver.')),
        };

        return [...$env, ...$this->broadcastingViteMirror($env)];
    }

    /**
     * @param  callable(string): string  $p
     * @return array<string, string>
     */
    private function validatedPusherEnv(callable $p): array
    {
        if ($p('pusher_app_key') === '' || $p('pusher_app_secret') === '' || $p('pusher_app_id') === '') {
            throw new InvalidArgumentException(__('Pusher app id, key, and secret are required.'));
        }

        return array_filter([
            'BROADCAST_CONNECTION' => 'pusher',
            'PUSHER_APP_ID' => $p('pusher_app_id'),
            'PUSHER_APP_KEY' => $p('pusher_app_key'),
            'PUSHER_APP_SECRET' => $p('pusher_app_secret'),
            'PUSHER_HOST' => $p('pusher_host') ?: null,
            'PUSHER_PORT' => $p('pusher_port') ?: null,
            'PUSHER_SCHEME' => $p('pusher_scheme') ?: null,
            'PUSHER_APP_CLUSTER' => $p('pusher_cluster') ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  callable(string): string  $p
     * @return array<string, string>
     */
    private function validatedReverbEnv(callable $p): array
    {
        if ($p('reverb_app_key') === '' || $p('reverb_app_secret') === '' || $p('reverb_app_id') === '' || $p('reverb_host') === '') {
            throw new InvalidArgumentException(__('Reverb app id, key, secret, and host are required.'));
        }

        return array_filter([
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_ID' => $p('reverb_app_id'),
            'REVERB_APP_KEY' => $p('reverb_app_key'),
            'REVERB_APP_SECRET' => $p('reverb_app_secret'),
            'REVERB_HOST' => $p('reverb_host'),
            'REVERB_PORT' => $p('reverb_port') ?: null,
            'REVERB_SCHEME' => $p('reverb_scheme') ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  callable(string): string  $p
     * @return array<string, string>
     */
    private function validatedAblyEnv(callable $p): array
    {
        if ($p('ably_key') === '') {
            throw new InvalidArgumentException(__('Ably key is required.'));
        }

        return [
            'BROADCAST_CONNECTION' => 'ably',
            'ABLY_KEY' => $p('ably_key'),
        ];
    }

    /**
     * Mirror the PUSHER_ and REVERB_ connection vars to VITE_ so Laravel Echo
     * (the browser client, which reads import.meta.env at build time) connects
     * without the operator hand-adding them. The signing secret is excluded —
     * it must never reach client-side bundles.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function broadcastingViteMirror(array $env): array
    {
        $mirror = [];
        foreach ($env as $key => $value) {
            if ($key === 'PUSHER_APP_SECRET' || $key === 'REVERB_APP_SECRET') {
                continue;
            }
            if (str_starts_with($key, 'PUSHER_') || str_starts_with($key, 'REVERB_')) {
                $mirror['VITE_'.$key] = $value;
            }
        }

        return $mirror;
    }

    /**
     * Tear down a managed broadcasting app when its last site detaches: pull the
     * KV record (revokes connect/publish immediately) and mark the app inactive
     * so billing stops. BYO bindings own no external infra, so this is a no-op
     * for them. Shared apps with other bindings are left running.
     */
    private function teardownBroadcasting(SiteBinding $binding): void
    {
        if ((string) $binding->target_type !== 'realtime_app') {
            return;
        }

        $appId = (string) ($binding->target_id ?? '');
        if ($appId === '') {
            return;
        }

        $stillBound = SiteBinding::query()
            ->where('type', 'broadcasting')
            ->where('target_type', 'realtime_app')
            ->where('target_id', $appId)
            ->whereKeyNot($binding->id)
            ->exists();

        if ($stillBound) {
            return; // another site still uses this app — keep it running.
        }

        $app = RealtimeApp::query()->find($appId);
        if (! $app instanceof RealtimeApp) {
            return;
        }

        try {
            RealtimeBackendFactory::make()->deprovision($app);
        } catch (\Throwable) {
            // Best-effort: a relay error must not block detaching the binding.
        }

        $app->forceFill(['status' => RealtimeApp::STATUS_PAUSED])->save();
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
