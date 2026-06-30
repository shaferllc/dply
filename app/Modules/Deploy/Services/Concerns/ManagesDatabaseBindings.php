<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Jobs\CreateSiteDatabaseJob;
use App\Jobs\EnsureSitePhpDatabaseDriverJob;
use App\Models\CloudDatabase;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Modules\Database\Backends\DatabaseRouter;
use App\Modules\Database\Jobs\ProvisionManagedDatabaseJob;
use App\Modules\Database\Support\ServerlessDatabaseVendors;
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

        // --- Instance / connection name ---
        // Blank = the site's primary database (bare DB_* keys); a slug names a
        // secondary connection (DB_<SLUG>_* + a config snippet). See databaseEnv.
        $connection = $this->resolveDatabaseConnectionName($site, $params);
        $primary = $this->databaseConnectionIsPrimary($connection);
        $editingId = trim((string) ($params['binding_id'] ?? ''));

        $attributes = [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            // Primary collapses to one row (name='primary'); a named instance is
            // keyed by its slug so several can coexist per site.
            'name' => $primary ? 'primary' : $connection,
            'target_type' => 'server_database',
            'target_id' => (string) $db->id,
            'injected_env' => $this->databaseEnv($db, $site, $opts, $connection),
            'config' => array_filter([
                'engine' => $db->engine,
                // The named connection slug ('' for primary) + a display name and
                // the config/database.php snippet to register a named connection.
                'connection' => $primary ? '' : $connection,
                'database_name' => (string) $db->name,
                'connection_snippet' => $primary ? null : $this->databaseConnectionSnippet($db, $connection, $opts),
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
            ], fn ($v) => $v !== null),
        ];

        $binding = $this->persistDatabaseBinding($site, $attributes, $primary, $editingId);

        // The app server may have been provisioned for a different DB engine
        // than the one just attached (e.g. MySQL server, Postgres attached).
        // Install the matching PHP client driver so the app doesn't deploy into
        // "could not find driver".
        EnsureSitePhpDatabaseDriverJob::dispatch((string) $site->id, (string) $db->engine);

        return $binding;
    }

    /**
     * Resolve and validate the connection-name slug for a database binding.
     * Blank → primary. A named slug must be unique among the site's other named
     * database instances (the row being edited is excluded), mirroring how
     * storage rejects a duplicate disk name.
     *
     * @param  array<string, mixed>  $params
     */
    private function resolveDatabaseConnectionName(Site $site, array $params): string
    {
        return $this->resolveInstanceConnectionName($site, 'database', $params);
    }

    /**
     * Upsert a database binding (edit-by-id / one-primary supersede). Thin
     * wrapper over the shared {@see SiteBindingManager::persistInstanceBinding()}.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function persistDatabaseBinding(Site $site, array $attributes, bool $primary, string $editingId): SiteBinding
    {
        return $this->persistInstanceBinding($site, 'database', $attributes, $primary, $editingId);
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

        // "Provision new" creates the site's PRIMARY database (this flow offers
        // no connection name). Refuse — BEFORE creating any real infra — when a
        // primary already exists, so an existing primary is never replaced.
        // (Covers managed/serverless too: both dispatch from here.)
        if ($this->databaseConnectionIsPrimary($this->resolveDatabaseConnectionName($site, $params))) {
            $this->assertNoOtherPrimaryInstance($site, 'database', trim((string) ($params['binding_id'] ?? '')));
        }

        // Placement decides where the database lives. `on_box` (default) creates
        // it on the site's own server; `do_managed` (and future co-located
        // backends) provision an isolated managed cluster and attach it. Both
        // resolve to the same `database` SiteBinding — only the target differs.
        $placement = strtolower(trim((string) ($params['placement'] ?? 'on_box')));
        if ($placement !== '' && $placement !== 'on_box') {
            return $this->provisionManagedDatabase($site, $server, $placement, $params);
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

        // Instance / connection name (blank = primary). Same scheme as attach.
        $connection = $this->resolveDatabaseConnectionName($site, $params);
        $primary = $this->databaseConnectionIsPrimary($connection);

        // Record the binding as PROVISIONING with its connection variables
        // already resolved — credentials are generated above in PHP, so the
        // injected DB_* are correct immediately even though the database itself
        // is created asynchronously.
        $binding = $this->persistDatabaseBinding($site, [
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_PROVISIONING,
            'name' => $primary ? 'primary' : $connection,
            'target_type' => 'server_database',
            'target_id' => (string) $db->id,
            'injected_env' => $this->databaseEnv($db, $site, [], $connection),
            'config' => array_filter([
                'engine' => $db->engine,
                'connection' => $primary ? '' : $connection,
                'database_name' => (string) $db->name,
                'connection_snippet' => $primary ? null : $this->databaseConnectionSnippet($db, $connection),
            ], fn ($v) => $v !== null),
            'last_error' => null,
        ], $primary, '');

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
     * Provision a co-located managed-database cluster (DigitalOcean Managed
     * Databases today) and attach it to the site via a `database` binding.
     *
     * The cluster takes minutes to come online, so we create the CloudDatabase
     * row + a PROVISIONING binding now and hand the slow work to
     * {@see ProvisionManagedDatabaseJob}, which fills in the connection vars and
     * flips the binding to configured once the provider reports online.
     *
     * @param  array<string, mixed>  $params
     */
    private function provisionManagedDatabase(Site $site, Server $server, string $placement, array $params): SiteBinding
    {
        // BYO serverless vendors (Neon …) aren't co-located with the server —
        // they take their own credential + region rather than the server's.
        if (ServerlessDatabaseVendors::isServerless($placement)) {
            return $this->provisionServerlessDatabase($site, $placement, $params);
        }

        $router = app(DatabaseRouter::class);
        $backend = $router->colocatedBackendFor($server);
        if ($backend === null) {
            throw new RuntimeException(__('This server\'s provider has no managed database option.'));
        }

        $engine = strtolower(trim((string) ($params['engine'] ?? 'postgres')));
        if (! in_array($engine, $backend->supportedEngines(), true)) {
            throw new InvalidArgumentException(__('That engine is not available as a managed database here.'));
        }

        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '' || preg_match('/^[a-zA-Z0-9_]+$/', $name) !== 1) {
            throw new InvalidArgumentException(__('Database name must be alphanumeric/underscore.'));
        }

        $region = $backend->regionForServer($server);
        if ($region === null) {
            throw new RuntimeException(__('Could not determine a managed database region for this server.'));
        }

        $credential = $this->resolveManagedDatabaseCredential($site, $server);
        if ($credential === null) {
            throw new RuntimeException(__('No :provider credential is connected for this server.', [
                'provider' => $server->provider->label(),
            ]));
        }

        $size = strtolower(trim((string) ($params['size'] ?? 'small')));
        if (! array_key_exists($size, CloudDatabase::SIZE_TIERS)) {
            $size = 'small';
        }

        $database = CloudDatabase::query()->create([
            'organization_id' => $site->organization_id,
            'name' => $name,
            'engine' => $engine,
            'version' => trim((string) ($params['version'] ?? '')),
            'size' => $size,
            'region' => $region,
            'backend' => $backend->key(),
            'provider_credential_id' => $credential->id,
            'status' => CloudDatabase::STATUS_PROVISIONING,
            'meta' => ['provisioned_for_site_id' => (string) $site->id],
        ]);

        // The connection vars aren't known until the cluster is online, so the
        // binding starts with an empty injected_env; the job fills it in. A
        // managed cluster is provisioned as the PRIMARY database (the modal
        // offers no connection name), superseding any existing primary.
        $binding = $this->persistDatabaseBinding($site, [
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_PROVISIONING,
            'name' => 'primary',
            'target_type' => 'cloud_database',
            'target_id' => (string) $database->id,
            'injected_env' => [],
            'config' => array_filter([
                'engine' => $engine,
                'connection' => '',
                'database_name' => $name,
                'placement' => $placement,
                'managed' => true,
                'region' => $region,
                'size' => $size,
            ], fn ($v) => $v !== null),
            'last_error' => null,
        ], true, '');

        ProvisionManagedDatabaseJob::dispatch(
            (string) $database->id,
            (string) $binding->id,
            (string) $server->id,
        );

        return $binding;
    }

    /**
     * The provider credential a managed database for this server should bill
     * to — mirroring how the server itself was created. A customer-connected
     * server uses its own credential (the DB lands on their account); we fall
     * back to any same-provider credential in the org.
     */
    private function resolveManagedDatabaseCredential(Site $site, Server $server): ?ProviderCredential
    {
        $server->loadMissing('providerCredential');
        if ($server->providerCredential !== null) {
            return $server->providerCredential;
        }

        $provider = $server->provider->value;
        $orgId = $site->organization_id ?? $server->organization_id;
        if ($orgId === null) {
            return null;
        }

        return ProviderCredential::query()
            ->where('organization_id', $orgId)
            ->where('provider', $provider)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Provision a BYO serverless-vendor database (Neon …) and attach it.
     * Region-agnostic: takes the vendor's own region + an API key (entered in
     * the modal or an existing connected credential) rather than the server's.
     *
     * @param  array<string, mixed>  $params
     */
    private function provisionServerlessDatabase(Site $site, string $placement, array $params): SiteBinding
    {
        $vendor = ServerlessDatabaseVendors::find($placement);
        if ($vendor === null) {
            throw new RuntimeException(__('Unknown serverless database vendor.'));
        }

        $backend = app(DatabaseRouter::class)->backend($placement);

        $engine = strtolower(trim((string) ($params['engine'] ?? 'postgres')));
        if (! in_array($engine, $backend->supportedEngines(), true)) {
            throw new InvalidArgumentException(__('That engine is not available on :vendor.', ['vendor' => $vendor['label']]));
        }

        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '' || preg_match('/^[a-zA-Z0-9_]+$/', $name) !== 1) {
            throw new InvalidArgumentException(__('Database name must be alphanumeric/underscore.'));
        }

        $orgId = $site->organization_id;
        if ($orgId === null) {
            throw new RuntimeException(__('No organization for this site.'));
        }

        $region = trim((string) ($params['vendor_region'] ?? ''));
        if ($region === '') {
            $region = (string) ($vendor['regions'][0]['value'] ?? '');
        }

        if (($vendor['account_required'] ?? false) && trim((string) ($params['vendor_account'] ?? '')) === '') {
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

        $database = CloudDatabase::query()->create([
            'organization_id' => $orgId,
            'name' => $name,
            'engine' => $engine,
            'version' => trim((string) ($params['version'] ?? '')),
            'size' => 'small',
            'region' => $region,
            'backend' => $backend->key(),
            'provider_credential_id' => $credential->id,
            'status' => CloudDatabase::STATUS_PROVISIONING,
            'meta' => ['provisioned_for_site_id' => (string) $site->id, 'serverless' => true],
        ]);

        $binding = $this->persistDatabaseBinding($site, [
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_PROVISIONING,
            'name' => 'primary',
            'target_type' => 'cloud_database',
            'target_id' => (string) $database->id,
            'injected_env' => [],
            'config' => [
                'engine' => $engine,
                'connection' => '',
                'database_name' => $name,
                'placement' => $placement,
                'managed' => true,
                'serverless' => true,
                'vendor' => $vendor['label'],
                'region' => $region,
            ],
            'last_error' => null,
        ], true, '');

        ProvisionManagedDatabaseJob::dispatch(
            (string) $database->id,
            (string) $binding->id,
            (string) ($site->server_id ?? ''),
        );

        return $binding;
    }

    /**
     * The provider credential for a serverless vendor: a freshly-entered API
     * key is saved as a new credential; otherwise reuse an existing connected
     * one for this org+vendor.
     */
    private function resolveServerlessCredential(string $orgId, string $provider, string $apiKey, string $account, string $label): ProviderCredential
    {
        if ($apiKey !== '') {
            return ProviderCredential::query()->create([
                'organization_id' => $orgId,
                'user_id' => auth()->id(),
                'provider' => $provider,
                'name' => $label,
                'credentials' => array_filter([
                    'api_token' => $apiKey,
                    'account' => $account !== '' ? $account : null,
                ]),
            ]);
        }

        $existing = ProviderCredential::query()
            ->where('organization_id', $orgId)
            ->where('provider', $provider)
            ->orderBy('created_at')
            ->first();

        if ($existing === null) {
            throw new RuntimeException(__('Enter a :label API key to connect.', ['label' => $label]));
        }

        return $existing;
    }

    /**
     * Wire a `database` binding to a now-ready ServerDatabase (used by the
     * dedicated-DB-VM flow once its server finished provisioning). Reuses the
     * host-aware {@see databaseEnv()} so a co-located DB box is dialed over the
     * shared private network. Stamps connection_ready_at to drive the
     * "redeploy to apply" prompt and flips the binding to configured.
     */
    public function wireServerDatabaseBinding(SiteBinding $binding, ServerDatabase $db, Site $site): void
    {
        $config = $binding->config;
        $config['connection_ready_at'] = now()->toIso8601String();

        $binding->forceFill([
            'status' => SiteBinding::STATUS_CONFIGURED,
            'injected_env' => $this->databaseEnv($db, $site),
            'config' => $config,
            'last_error' => null,
        ])->save();
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
     * $connection is the named-instance slug. Blank/'default'/'primary' = the
     * site's PRIMARY database, which keeps Laravel's bare keys (DB_CONNECTION,
     * DB_HOST, …). A non-empty slug (e.g. 'clickhouse') is a SECONDARY instance:
     * every key is namespaced to DB_<SLUG>_* so it can't collide with the
     * primary (or another instance), and no DB_CONNECTION is emitted — the
     * driver is set in the config/database.php connection block instead (see
     * {@see databaseConnectionSnippet()}). This mirrors how storage namespaces
     * named disks as AWS_<DISK>_*.
     *
     * @param  array<string, mixed> $options
     * @return array<string, string>
     */
    private function databaseEnv(ServerDatabase $db, Site $site, array $options = [], string $connection = ''): array
    {
        $slug = $this->databaseConnectionSlug($connection);
        $primary = $this->databaseConnectionIsPrimary($slug);
        $p = $primary ? 'DB_' : 'DB_'.strtoupper($slug).'_';

        if ($db->engine === 'sqlite') {
            $env = [$p.'DATABASE' => (string) ($db->host ?: '')];
            if ($primary) {
                $env['DB_CONNECTION'] = 'sqlite';
                $env['DATABASE_URL'] = (string) $db->connectionUrl();
            } elseif (($url = (string) $db->connectionUrl()) !== '') {
                $env[$p.'URL'] = $url;
            }
            if (($options['prefix'] ?? '') !== '') {
                $env[$p.'PREFIX'] = (string) $options['prefix'];
            }
            if (($options['timezone'] ?? '') !== '') {
                $env[$p.'TIMEZONE'] = (string) $options['timezone'];
            }

            return array_filter($env, fn ($v) => $v !== null && $v !== '');
        }

        $host = $this->effectiveDatabaseHost($db, $site);
        $driver = $this->databaseConnectionDriver((string) $db->engine);

        $env = [
            $p.'HOST' => $host,
            $p.'PORT' => (string) $db->defaultPort(),
            $p.'DATABASE' => (string) $db->name,
            $p.'USERNAME' => (string) $db->username,
            $p.'PASSWORD' => (string) $db->password,
        ];
        if ($primary) {
            // DB_CONNECTION selects the app's DEFAULT connection — only the
            // primary owns it; a named instance is registered separately.
            $env = ['DB_CONNECTION' => $driver] + $env;
            $env['DATABASE_URL'] = (string) $db->connectionUrl($host);
        } elseif (($url = (string) $db->connectionUrl($host)) !== '') {
            $env[$p.'URL'] = $url;
        }

        // Read replica — a read/write split is a property of the DEFAULT
        // connection, so it only applies to the primary instance. Injects
        // DB_READ_HOST and DB_STICKY; optional overrides for port/username/
        // password only when they differ from the primary.
        $readHost = $primary ? (string) ($options['read_replica_host'] ?? '') : '';
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

        // Per-connection tuning shared by MySQL and Postgres (namespaced for
        // named instances so the snippet's env() refs line up).
        if (($options['prefix'] ?? '') !== '') {
            $env[$p.'PREFIX'] = (string) $options['prefix'];
        }
        if (($options['timezone'] ?? '') !== '') {
            $env[$p.'TIMEZONE'] = (string) $options['timezone'];
        }

        if ($driver === 'mysql') {
            if (($options['charset'] ?? '') !== '') {
                $env[$p.'CHARSET'] = (string) $options['charset'];
            }
            if (($options['collation'] ?? '') !== '') {
                $env[$p.'COLLATION'] = (string) $options['collation'];
            }
            if (($options['strict'] ?? '') !== '') {
                $env[$p.'STRICT'] = (string) $options['strict'];
            }
            if (($options['storage_engine'] ?? '') !== '') {
                $env[$p.'ENGINE'] = (string) $options['storage_engine'];
            }
            if (($options['socket'] ?? '') !== '') {
                $env[$p.'SOCKET'] = (string) $options['socket'];
            }
        }

        if ($driver === 'pgsql') {
            if (($options['charset'] ?? '') !== '') {
                $env[$p.'CHARSET'] = (string) $options['charset'];
            }
            if (($options['schema'] ?? '') !== '') {
                $env[$p.'SCHEMA'] = (string) $options['schema'];
            }
            if (($options['sslmode'] ?? '') !== '') {
                $env[$p.'SSLMODE'] = (string) $options['sslmode'];
            }
        }

        return $env;
    }

    // Connection-name slug / primary detection are generic across multi-instance
    // types — delegate to the shared helpers on SiteBindingManager.
    private function databaseConnectionSlug(string $raw): string
    {
        return $this->connectionSlug($raw);
    }

    private function databaseConnectionIsPrimary(string $slug): bool
    {
        return $this->connectionIsPrimary($slug);
    }

    /** Laravel connection driver for a ServerDatabase engine. */
    private function databaseConnectionDriver(string $engine): string
    {
        return match (strtolower($engine)) {
            'postgres', 'pgsql' => 'pgsql',
            'clickhouse' => 'clickhouse',
            'mongodb', 'mongo' => 'mongodb',
            'sqlite' => 'sqlite',
            default => 'mysql', // mysql, mariadb
        };
    }

    /**
     * A ready-to-paste config/database.php connection block for a NAMED
     * instance (empty for the primary, which uses the framework defaults). The
     * env() refs match the namespaced keys {@see databaseEnv()} injects. For a
     * non-core driver (clickhouse, mongodb) it prepends a note that a community
     * package supplying the driver is required.
     */
    private function databaseConnectionSnippet(ServerDatabase $db, string $connection, array $options = []): string
    {
        $slug = $this->databaseConnectionSlug($connection);
        if ($this->databaseConnectionIsPrimary($slug)) {
            return '';
        }

        $driver = $this->databaseConnectionDriver((string) $db->engine);
        $p = 'DB_'.strtoupper($slug).'_';

        $lines = ["'{$slug}' => [", "    'driver' => '{$driver}',"];
        if ($db->engine === 'sqlite') {
            $lines[] = "    'database' => env('{$p}DATABASE'),";
        } else {
            $lines[] = "    'host' => env('{$p}HOST', '127.0.0.1'),";
            $lines[] = "    'port' => env('{$p}PORT', '".$db->defaultPort()."'),";
            $lines[] = "    'database' => env('{$p}DATABASE'),";
            $lines[] = "    'username' => env('{$p}USERNAME'),";
            $lines[] = "    'password' => env('{$p}PASSWORD', ''),";
            $lines[] = "    'url' => env('{$p}URL'),";
        }
        if ($driver === 'pgsql') {
            $lines[] = "    'charset' => env('{$p}CHARSET', 'utf8'),";
            $lines[] = "    'search_path' => env('{$p}SCHEMA', 'public'),";
            $lines[] = "    'sslmode' => env('{$p}SSLMODE', 'prefer'),";
        } elseif ($driver === 'mysql') {
            $lines[] = "    'charset' => env('{$p}CHARSET', 'utf8mb4'),";
        }
        $lines[] = '],';

        $snippet = implode("\n", $lines);

        if (! in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            $snippet = "// '{$driver}' is not a built-in Laravel driver — install a community package\n"
                ."// that registers it (e.g. ClickHouse: composer require bavix/laravel-clickhouse),\n"
                ."// then add this connection to config/database.php → connections:\n"
                .$snippet;
        }

        return $snippet;
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
