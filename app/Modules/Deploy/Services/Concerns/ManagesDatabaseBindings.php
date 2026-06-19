<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Jobs\CreateSiteDatabaseJob;
use App\Jobs\EnsureSitePhpDatabaseDriverJob;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Support\Servers\DatabaseWorkspaceEngines;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Attach / provision the `database` binding type and build its connection env.
 *
 * @property-read ServerDatabaseProvisioner $databaseProvisioner
 */
trait ManagesDatabaseBindings
{
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

        $databases = ServerDatabase::query()
            ->whereIn('server_id', $this->reachableServerIds($server))
            ->with('server:id,name,organization_id,private_ip_address,private_network_id')
            ->orderBy('name')
            ->get();

        $consumers = $this->bindingConsumerCounts(
            'server_database',
            $databases->map(fn (ServerDatabase $d): string => (string) $d->id)->all(),
            (string) $site->id,
        );

        return $databases
            ->map(function (ServerDatabase $db) use ($server, $consumers): array {
                $sameBox = (string) $db->server_id === (string) $server->id;
                if ($sameBox) {
                    $where = __('this server');
                } else {
                    $where = ($db->server?->name ?: __('network peer'))
                        .($db->remote_access ? '' : ' — '.__('remote access off'));
                }
                $used = $consumers[(string) $db->id] ?? 0;

                return [
                    'id' => (string) $db->id,
                    'label' => $db->name.' ('.$db->engine.') · '.$where.$this->usageSuffix($used),
                    'engine' => (string) $db->engine,
                    'group' => $sameBox ? 'local' : 'peer',
                    'consumers' => $used,
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed> $params
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
     * @param  array<string, mixed> $params
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

        // Fast, NON-SSH pre-check: the engine must be installed on this server
        // (a ServerDatabaseEngine row exists). The actual CREATE DATABASE — and
        // any "installed but not TCP-listening" failure — is handled by the
        // queued CreateSiteDatabaseJob below, so nothing in this request path
        // touches SSH (the no-render-path-SSH rule; inline SSH here is exactly
        // what made "Provision" hang past the 30s limit).
        if ($engine !== 'sqlite'
            && ! ServerDatabaseEngine::query()->where('server_id', $server->id)->where('engine', $engine)->exists()) {
            throw new RuntimeException(__(':engine is not installed on this server — install it from the Databases tab first.', [
                'engine' => DatabaseWorkspaceEngines::label($engine),
            ]));
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

        // Record the binding as PROVISIONING with its connection variables
        // already resolved — credentials are generated above in PHP, so the
        // injected DB_* are correct immediately even though the database itself
        // is created asynchronously.
        $binding = $this->persist($site, 'database', [
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_PROVISIONING,
            'name' => $db->name,
            'target_type' => 'server_database',
            'target_id' => (string) $db->id,
            'injected_env' => $this->databaseEnv($db, $site),
            'config' => ['engine' => $db->engine],
            'last_error' => null,
        ]);

        // Hand the slow, SSH-bound CREATE DATABASE to the queued job (the same
        // one the Database tab uses). It flips this binding to configured — or
        // error, with the message — when it finishes. writeEnv is off because the
        // binding already owns the .env injection.
        CreateSiteDatabaseJob::dispatch(
            (string) $db->id,
            (string) $site->id,
            writeEnv: false,
            pushEnv: false,
            userId: auth()->id(),
            seededConsoleRunId: null,
            siteBindingId: (string) $binding->id,
        );

        return $binding;
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
     * @param  array<string, mixed> $options
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
        if (($options['prefix'] ?? '') !== '') {
            $env['DB_PREFIX'] = (string) $options['prefix'];
        }
        if (($options['timezone'] ?? '') !== '') {
            $env['DB_TIMEZONE'] = (string) $options['timezone'];
        }

        if ($connection === 'mysql') {
            if (($options['charset'] ?? '') !== '') {
                $env['DB_CHARSET'] = (string) $options['charset'];
            }
            if (($options['collation'] ?? '') !== '') {
                $env['DB_COLLATION'] = (string) $options['collation'];
            }
            if (($options['strict'] ?? '') !== '') {
                $env['DB_STRICT'] = (string) $options['strict'];
            }
            if (($options['storage_engine'] ?? '') !== '') {
                $env['DB_ENGINE'] = (string) $options['storage_engine'];
            }
            if (($options['socket'] ?? '') !== '') {
                $env['DB_SOCKET'] = (string) $options['socket'];
            }
        }

        if ($connection === 'pgsql') {
            if (($options['charset'] ?? '') !== '') {
                $env['DB_CHARSET'] = (string) $options['charset'];
            }
            if (($options['schema'] ?? '') !== '') {
                $env['DB_SCHEMA'] = (string) $options['schema'];
            }
            if (($options['sslmode'] ?? '') !== '') {
                $env['DB_SSLMODE'] = (string) $options['sslmode'];
            }
        }

        return $env;
    }

    /**
     * The address $site should dial to reach $db:
     *  - same server  → loopback (127.0.0.1), ALWAYS
     *  - network peer → the peer server's private IP
     *  - otherwise    → the stored host (a public IP/hostname set deliberately)
     */
    private function effectiveDatabaseHost(ServerDatabase $db, Site $site): string
    {
        $siteServer = $site->server;
        $dbServer = $db->server;

        $sameBox = $siteServer !== null && $dbServer !== null
            && (string) $dbServer->id === (string) $siteServer->id;

        // Co-located DB → loopback, unconditionally. The engine listens on
        // localhost by default (Postgres listen_addresses='localhost', MySQL
        // bind-address 127.0.0.1); a stored private-IP host (e.g. a 10.x address
        // saved at provision time) is NOT bound there, so dialing it reads as
        // "unreachable" even though the database is right here. 127.0.0.1 is
        // always reachable and needs no private network or firewall rule.
        if ($sameBox) {
            return '127.0.0.1';
        }

        // Different boxes that share a private network → the backend's private IP.
        if ($siteServer !== null && $dbServer !== null
            && $this->sharePrivateNetwork($siteServer, $dbServer)
            && filled($dbServer->private_ip_address)) {
            return (string) $dbServer->private_ip_address;
        }

        // Cross-box with no shared private network → the stored host, which must
        // be a publicly reachable endpoint the operator configured deliberately.
        return (string) ($db->host ?: '127.0.0.1');
    }
}
