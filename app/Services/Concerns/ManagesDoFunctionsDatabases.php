<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Models\CloudDatabase;
use App\Support\Servers\ProviderManagedDatabaseRegion;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDoFunctionsDatabases
{
    /**
     * Create a DigitalOcean Functions (serverless) namespace. The returned
     * api_host + access_key are the OpenWhisk credentials a function deploy
     * needs — stored on the serverless host Server's meta.
     *
     * @return array{api_host: string, namespace: string, access_key: string, region: string}
     */
    public function createFunctionsNamespace(string $region, string $label): array
    {
        $response = $this->request('post', '/functions/namespaces', [
            'region' => $region,
            'label' => $label,
        ]);
        $this->assertSuccess($response, 'create functions namespace');

        $ns = $response->json('namespace');
        $ns = is_array($ns) ? $ns : [];

        // OpenWhisk (which backs DO Functions) authenticates with a
        // `uuid:key` pair — the deployer splits the access key on the colon.
        // DO returns `uuid` and `key` separately, so recombine them here.
        $uuid = (string) ($ns['uuid'] ?? '');
        $key = (string) ($ns['key'] ?? '');
        $accessKey = ($uuid !== '' && $key !== '') ? $uuid.':'.$key : $key;

        return [
            'api_host' => (string) ($ns['api_host'] ?? ''),
            'namespace' => (string) ($ns['namespace'] ?? $ns['uuid'] ?? ''),
            'access_key' => $accessKey,
            'region' => (string) ($ns['region'] ?? $region),
        ];
    }

    /**
     * Delete a DigitalOcean Functions namespace — the teardown counterpart of
     * {@see createFunctionsNamespace()}. Returns true on a successful delete,
     * false on a 404 (already gone) so teardown is idempotent, mirroring
     * {@see deleteDatabaseCluster()}.
     *
     * A namespace outlives the function deployed into it, so removing the dply
     * Site is not enough — without this the customer keeps paying DigitalOcean
     * for an empty namespace nothing references.
     */
    public function deleteFunctionsNamespace(string $namespace): bool
    {
        $response = $this->request('delete', '/functions/namespaces/'.$namespace);
        if ($response->status() === 404) {
            return false;
        }
        $this->assertSuccess($response, 'delete functions namespace');

        return true;
    }

    /**
     * List the DigitalOcean Functions scheduled triggers in a namespace.
     *
     * @return list<array<string, mixed>>
     */
    public function functionTriggers(string $namespace): array
    {
        $response = $this->request('get', "/functions/namespaces/{$namespace}/triggers");
        $this->assertSuccess($response, 'list function triggers');

        $triggers = $response->json('triggers');

        return is_array($triggers) ? array_values($triggers) : [];
    }

    /**
     * Create a SCHEDULED trigger — DigitalOcean fires `$function` on the cron
     * (evaluated in UTC). `body` must be a JSON object, so an empty payload is
     * sent as `{}` (a PHP `[]` would serialize to `[]` and DO rejects it).
     *
     * @return array<string, mixed> the created trigger
     */
    public function createScheduledFunctionTrigger(string $namespace, string $name, string $function, string $cron): array
    {
        $response = $this->request('post', "/functions/namespaces/{$namespace}/triggers", [
            'name' => $name,
            'function' => $function,
            'type' => 'SCHEDULED',
            'is_enabled' => true,
            'scheduled_details' => ['cron' => $cron, 'body' => (object) []],
        ]);
        $this->assertSuccess($response, 'create scheduled function trigger');

        return (array) $response->json('trigger');
    }

    /**
     * Delete a function trigger. A 404 (already gone) is treated as success
     * so removal is idempotent.
     */
    public function deleteFunctionTrigger(string $namespace, string $name): void
    {
        $response = $this->request('delete', "/functions/namespaces/{$namespace}/triggers/{$name}");

        if (! $response->successful() && $response->status() !== 404) {
            $this->assertSuccess($response, 'delete function trigger');
        }
    }

    /**
     * Create a DigitalOcean Managed Database cluster. It returns immediately
     * with status `creating`; poll {@see getDatabaseCluster()} until `online`.
     *
     * @return array{id: string, name: string, status: string, engine: string, connection: array{host: string, port: int, user: string, password: string, database: string, uri: string, ssl: bool}}
     */
    /**
     * Regions DigitalOcean currently offers for this managed-database engine.
     * Always the live /v2/databases/options list — no hardcoded allowlist.
     *
     * @return list<string>
     */
    public function getDatabaseEngineRegions(string $engine): array
    {
        foreach ($this->engineOptionKeys($engine) as $key) {
            $slugs = $this->regionSlugsFromOption($this->cachedDatabaseOptions()[$key] ?? null);
            if ($slugs !== []) {
                return $slugs;
            }
        }

        return [];
    }

    /**
     * Size slugs DigitalOcean offers for a single-node cluster of this engine.
     *
     * @return list<string>
     */
    public function getDatabaseEngineSizes(string $engine, int $numNodes = 1): array
    {
        foreach ($this->engineOptionKeys($engine) as $key) {
            $slugs = $this->sizeSlugsFromOption($this->cachedDatabaseOptions()[$key] ?? null, $numNodes);
            if ($slugs !== []) {
                return $slugs;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public function getDatabaseEngineVersions(string $engine): array
    {
        foreach ($this->engineOptionKeys($engine) as $key) {
            $versions = $this->versionsFromOption($this->cachedDatabaseOptions()[$key] ?? null);
            if ($versions !== []) {
                return $versions;
            }
        }

        return [];
    }

    public function getDatabaseEngineDefaultVersion(string $engine): ?string
    {
        foreach ($this->engineOptionKeys($engine) as $key) {
            $option = $this->cachedDatabaseOptions()[$key] ?? null;
            $default = is_array($option) ? trim((string) ($option['default_version'] ?? '')) : '';
            if ($default !== '') {
                return $default;
            }
            $versions = $this->versionsFromOption($option);
            if ($versions !== []) {
                return $versions[0];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedDatabaseOptions(): array
    {
        $cacheKey = 'do_db_options:'.sha1($this->token);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request('get', '/databases/options');
        $this->assertSuccess($response, 'list database options');
        $options = $response->json('options');
        $options = is_array($options) ? $options : [];
        Cache::put($cacheKey, $options, now()->addMinutes(2));

        return $options;
    }

    /**
     * @return list<string>
     */
    private function engineOptionKeys(string $engine): array
    {
        return match (strtolower(trim($engine))) {
            'postgres', 'postgresql', 'pg' => ['pg', 'postgres'],
            'mysql' => ['mysql'],
            // Managed Redis was discontinued — never read the leftover redis dump.
            'redis', 'valkey' => ['valkey'],
            default => [strtolower(trim($engine))],
        };
    }

    /**
     * @return list<string>
     */
    private function regionSlugsFromOption(mixed $option): array
    {
        $regions = is_array($option) ? ($option['regions'] ?? []) : [];
        if (! is_array($regions)) {
            return [];
        }

        $slugs = [];
        foreach ($regions as $region) {
            if (is_string($region) && $region !== '') {
                $slugs[] = strtolower($region);
            } elseif (is_array($region) && filled($region['slug'] ?? null)) {
                $slugs[] = strtolower((string) $region['slug']);
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @return list<string>
     */
    private function sizeSlugsFromOption(mixed $option, int $numNodes): array
    {
        $layouts = is_array($option) ? ($option['layouts'] ?? []) : [];
        if (! is_array($layouts)) {
            return [];
        }

        $slugs = [];
        foreach ($layouts as $layout) {
            if (! is_array($layout) || (int) ($layout['num_nodes'] ?? 0) !== $numNodes) {
                continue;
            }
            $sizes = $layout['sizes'] ?? [];
            if (! is_array($sizes)) {
                continue;
            }
            foreach ($sizes as $size) {
                if (is_string($size) && $size !== '') {
                    $slugs[] = strtolower($size);
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * @return list<string>
     */
    private function versionsFromOption(mixed $option): array
    {
        $versions = is_array($option) ? ($option['versions'] ?? []) : [];
        if (! is_array($versions)) {
            return [];
        }

        $normalized = [];
        foreach ($versions as $version) {
            if (is_string($version) && $version !== '') {
                $normalized[] = $version;
            } elseif (is_int($version) || is_float($version)) {
                $normalized[] = (string) $version;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $tags
     */
    public function createDatabaseCluster(string $engine, string $region, string $size, string $name, ?string $version = null, array $tags = []): array
    {
        $constrained = $this->constrainDatabaseCreateToCatalog($engine, $region, $size, $version);

        $payload = [
            'name' => $name,
            'engine' => $constrained['engine'],
            'region' => $constrained['region'],
            'size' => $constrained['size'],
            'num_nodes' => 1,
        ];
        if ($constrained['version'] !== '') {
            $payload['version'] = $constrained['version'];
        }
        $tags = $this->normalizeDatabaseTags($tags);
        if ($tags !== []) {
            $payload['tags'] = $tags;
        }

        return $this->sendDatabaseCreate($payload);
    }

    /**
     * POST a create payload to /v2/databases. Shared by a fresh create and a
     * restore-from-backup create, which differ only by a `backup_restore`
     * block — the error handling below (a slow API reads as a bad token, and
     * a 4xx says nothing about what we sent) is the reason not to duplicate it.
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: string, name: string, status: string, engine: string, tags: list<string>, connection: array{host: string, port: int, user: string, password: string, database: string, uri: string, ssl: bool}}
     */
    private function sendDatabaseCreate(array $payload): array
    {
        try {
            $response = $this->request('post', '/databases', $payload);
        } catch (ConnectionException $e) {
            throw new \RuntimeException(
                'DigitalOcean did not accept the database create in time. This is usually a slow API, not a bad token — retry provision.',
                0,
                $e,
            );
        }
        if (! $response->successful()) {
            $message = $response->json('message') ?? $response->json('error') ?? $response->body() ?: $response->reason();

            throw new \RuntimeException(sprintf(
                'DigitalOcean API failed to create database cluster: %s (sent engine=%s version=%s region=%s size=%s)',
                $message,
                $payload['engine'] ?? '',
                $payload['version'] ?? '',
                $payload['region'] ?? '',
                $payload['size'] ?? '',
            ));
        }

        return $this->normalizeDatabaseCluster($response->json('database'));
    }

    /**
     * @return array{engine: string, region: string, size: string, version: string}
     */
    private function constrainDatabaseCreateToCatalog(string $engine, string $region, string $size, ?string $version): array
    {
        $engine = strtolower(trim($engine));
        $region = strtolower(trim($region));
        $size = strtolower(trim($size));
        $version = trim((string) $version);

        // Managed Redis was discontinued 30 June 2025. New clusters must be
        // Valkey (Redis-compatible). Sending engine=redis makes the API 400
        // with a misleading "region 'X' is not valid" for every datacenter.
        if ($engine === 'redis') {
            $engine = 'valkey';
        }

        $size = CloudDatabase::resolveSizeSlug($size !== '' ? $size : 'small');

        $availableRegions = [];
        $availableSizes = [];
        $availableVersions = [];
        $defaultVersion = null;
        try {
            $availableRegions = $this->getDatabaseEngineRegions($engine);
            $availableSizes = $this->getDatabaseEngineSizes($engine);
            $availableVersions = $this->getDatabaseEngineVersions($engine);
            $defaultVersion = $this->getDatabaseEngineDefaultVersion($engine);
        } catch (\Throwable) {
            // Catalog is best-effort — still remap engine/size below.
        }

        if ($availableRegions !== []) {
            $resolved = ProviderManagedDatabaseRegion::resolve('digitalocean', $region !== '' ? $region : null, null, $availableRegions);
            if (is_string($resolved) && $resolved !== '') {
                $region = $resolved;
            }
        }

        if ($availableSizes !== [] && ! in_array($size, $availableSizes, true)) {
            $size = $availableSizes[0];
        }

        if ($engine === 'valkey' && ($version === '' || $version === '7' || str_starts_with($version, '7.'))) {
            $version = $defaultVersion ?? '8';
        }
        if ($availableVersions !== [] && ($version === '' || ! in_array($version, $availableVersions, true))) {
            $version = $defaultVersion ?? $availableVersions[0];
        }

        return [
            'engine' => $engine,
            'region' => $region,
            'size' => $size,
            'version' => $version,
        ];
    }

    /**
     * @return array{id: string, name: string, status: string, engine: string, connection: array{host: string, port: int, user: string, password: string, database: string, uri: string, ssl: bool}}
     */
    public function getDatabaseCluster(string $id): array
    {
        $response = $this->request('get', '/databases/'.$id);
        $this->assertSuccess($response, 'get database cluster');

        return $this->normalizeDatabaseCluster($response->json('database'));
    }

    /**
     * Change the cluster plan in place. DigitalOcean moves the cluster to
     * `resizing` then back to `online`; the hostname usually stays the same.
     *
     * @return array{id: string, name: string, status: string, engine: string, connection: array{host: string, port: int, user: string, password: string, database: string, uri: string, ssl: bool}}
     */
    public function resizeDatabaseCluster(string $id, string $size, int $numNodes = 1): array
    {
        $size = CloudDatabase::resolveSizeSlug(strtolower(trim($size)));
        $numNodes = max(1, $numNodes);

        $response = $this->request('put', '/databases/'.$id, [
            'size' => $size,
            'num_nodes' => $numNodes,
        ]);
        $this->assertSuccess($response, 'resize database cluster');

        return $this->normalizeDatabaseCluster($response->json('database'));
    }

    /**
     * Create a transaction-mode connection pool (PgBouncer) on a Postgres
     * cluster. Serverless functions open a fresh connection on every cold
     * start; a pool multiplexes those onto a small set of backend
     * connections so the cluster's connection limit is not exhausted.
     */
    public function createDatabaseConnectionPool(string $clusterId, string $name, string $database, string $user, int $size = 10): array
    {
        $response = $this->request('post', '/databases/'.$clusterId.'/pools', [
            'name' => $name,
            'mode' => 'transaction',
            'size' => $size,
            'db' => $database,
            'user' => $user,
        ]);
        $this->assertSuccess($response, 'create database connection pool');

        $pool = $response->json('pool');
        $pool = is_array($pool) ? $pool : [];
        $connection = is_array($pool['connection'] ?? null) ? $pool['connection'] : [];

        return [
            'name' => (string) ($pool['name'] ?? $name),
            'connection' => [
                'host' => (string) ($connection['host'] ?? ''),
                'port' => (int) ($connection['port'] ?? 0),
                'user' => (string) ($connection['user'] ?? ''),
                'password' => (string) ($connection['password'] ?? ''),
                'database' => (string) ($connection['database'] ?? ''),
                'ssl' => (bool) ($connection['ssl'] ?? true),
            ],
        ];
    }

    /**
     * Replace a cluster's trusted sources (firewall). Passing the app server
     * as the only rule closes the cluster to the public internet while keeping
     * it reachable from the box that uses it. Each rule is
     * {type: 'droplet'|'ip_addr'|'tag'|'k8s', value: string}.
     *
     * @param  list<array{type: string, value: string}>  $rules
     */
    /**
     * Attach tags to an existing cluster (DigitalOcean's create accepts tags,
     * but a cluster minted before we sent them — or a create that dropped
     * them — still needs the Tags API). Creating a tag name that already
     * exists is a no-op.
     *
     * @param  list<string>  $tags
     */
    public function tagDatabaseCluster(string $clusterId, array $tags): void
    {
        $clusterId = trim($clusterId);
        $tags = $this->normalizeDatabaseTags($tags);
        if ($clusterId === '' || $tags === []) {
            return;
        }

        foreach ($tags as $tag) {
            $created = $this->request('post', '/tags', ['name' => $tag]);
            if (! $created->successful() && ! in_array($created->status(), [409, 422], true)) {
                $this->assertSuccess($created, 'create tag '.$tag);
            }

            $attached = $this->request('post', '/tags/'.rawurlencode($tag).'/resources', [
                'resources' => [[
                    'resource_id' => $clusterId,
                    'resource_type' => 'database',
                ]],
            ]);
            if (! $attached->successful() && $attached->status() !== 409) {
                $this->assertSuccess($attached, 'tag database cluster');
            }
        }
    }

    /**
     * @param  list<string>  $wanted
     * @param  list<string>  $existing
     */
    public function ensureDatabaseClusterTags(string $clusterId, array $wanted, array $existing = []): void
    {
        $missing = array_values(array_diff(
            $this->normalizeDatabaseTags($wanted),
            $this->normalizeDatabaseTags($existing),
        ));
        if ($missing === []) {
            return;
        }

        $this->tagDatabaseCluster($clusterId, $missing);
    }

    /**
     * @param  list<mixed>  $tags
     * @return list<string>
     */
    private function normalizeDatabaseTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            if (! is_string($tag) && ! is_int($tag)) {
                continue;
            }
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $normalized[] = $tag;
            }
        }

        return array_values(array_unique($normalized));
    }

    public function setDatabaseTrustedSources(string $clusterId, array $rules): void
    {
        $response = $this->request('put', '/databases/'.$clusterId.'/firewall', [
            'rules' => $rules,
        ]);
        $this->assertSuccess($response, 'set database trusted sources');
    }

    /**
     * Users that exist on a managed cluster.
     *
     * Lets an operator connect as something other than the cluster admin — a
     * GUI table editor authenticated as doadmin is one mis-clicked cell away
     * from a very bad afternoon.
     *
     * @return list<array{name: string, role: string, password: string}>
     */
    public function listDatabaseUsers(string $clusterId): array
    {
        $response = $this->request('get', '/databases/'.$clusterId.'/users');
        $this->assertSuccess($response, 'list database users');

        $users = [];
        foreach ((array) $response->json('users', []) as $user) {
            if (! is_array($user)) {
                continue;
            }

            $name = trim((string) ($user['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $users[] = [
                'name' => $name,
                'role' => (string) ($user['role'] ?? 'normal'),
                'password' => (string) ($user['password'] ?? ''),
            ];
        }

        return $users;
    }

    /**
     * Create a user on a managed cluster. The provider generates the password
     * and returns it once, in this response.
     *
     * @return array{name: string, role: string, password: string}
     */
    public function createDatabaseUser(string $clusterId, string $name): array
    {
        $response = $this->request('post', '/databases/'.$clusterId.'/users', [
            'name' => $name,
        ]);
        $this->assertSuccess($response, 'create database user');

        $user = (array) $response->json('user', []);

        return [
            'name' => (string) ($user['name'] ?? $name),
            'role' => (string) ($user['role'] ?? 'normal'),
            'password' => (string) ($user['password'] ?? ''),
        ];
    }

    /**
     * Read a cluster's current trusted sources.
     *
     * {@see setDatabaseTrustedSources()} replaces the entire rule set, so any
     * caller adding a single entry MUST read first and send the union — omitting
     * the app server's rule would cut the live site off from its own database.
     *
     * Rules are returned in the shape the setter expects, so a read → append →
     * write round trip needs no translation.
     *
     * @return list<array{type: string, value: string}>
     */
    public function getDatabaseTrustedSources(string $clusterId): array
    {
        $response = $this->request('get', '/databases/'.$clusterId.'/firewall');
        $this->assertSuccess($response, 'get database trusted sources');

        $rules = [];
        foreach ((array) $response->json('rules', []) as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $type = trim((string) ($rule['type'] ?? ''));
            $value = trim((string) ($rule['value'] ?? ''));
            if ($type !== '' && $value !== '') {
                $rules[] = ['type' => $type, 'value' => $value];
            }
        }

        return $rules;
    }

    /**
     * Delete a user from a managed cluster.
     *
     * The provider's own admin user (doadmin / valkey's default) cannot be
     * removed; DigitalOcean answers 403 and we let that surface rather than
     * pretending it worked. A 404 is success — the user is gone either way.
     */
    public function deleteDatabaseUser(string $clusterId, string $name): bool
    {
        $response = $this->request('delete', '/databases/'.$clusterId.'/users/'.rawurlencode($name));
        if ($response->status() === 404) {
            return false;
        }
        $this->assertSuccess($response, 'delete database user');

        return true;
    }

    /**
     * Rotate a user's password. The provider generates the replacement and
     * returns it in this response and nowhere else — the caller has one
     * chance to show it.
     *
     * @return array{name: string, role: string, password: string}
     */
    public function resetDatabaseUserAuth(string $clusterId, string $name): array
    {
        $response = $this->request('post', '/databases/'.$clusterId.'/users/'.rawurlencode($name).'/reset_auth', []);
        $this->assertSuccess($response, 'reset database user password');

        $user = (array) $response->json('user', []);

        return [
            'name' => (string) ($user['name'] ?? $name),
            'role' => (string) ($user['role'] ?? 'normal'),
            'password' => (string) ($user['password'] ?? ''),
        ];
    }

    /**
     * A cluster's automatic backups, newest first.
     *
     * DigitalOcean keeps a rolling window (7 days on current plans) and does
     * not expose individual restore points beyond `created_at` — that
     * timestamp is the handle {@see createDatabaseClusterFromBackup()} takes.
     *
     * @return list<array{created_at: string, size_gigabytes: float}>
     */
    public function listDatabaseBackups(string $clusterId): array
    {
        $response = $this->request('get', '/databases/'.$clusterId.'/backups');
        $this->assertSuccess($response, 'list database backups');

        $backups = [];
        foreach ((array) $response->json('database_backups', []) as $backup) {
            if (! is_array($backup)) {
                continue;
            }

            $createdAt = trim((string) ($backup['created_at'] ?? ''));
            if ($createdAt === '') {
                continue;
            }

            $backups[] = [
                'created_at' => $createdAt,
                'size_gigabytes' => (float) ($backup['size_gigabytes'] ?? 0),
            ];
        }

        usort($backups, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return $backups;
    }

    /**
     * Create a new cluster from another cluster's backup.
     *
     * Restore never touches the source: DigitalOcean builds a second cluster
     * seeded from the backup, which is also why $sourceClusterName is the
     * cluster's *provider* name rather than its id — `backup_restore` keys off
     * the name DigitalOcean knows it by.
     *
     * @param  list<string>  $tags
     * @return array{id: string, name: string, status: string, engine: string, tags: list<string>, connection: array{host: string, port: int, user: string, password: string, database: string, uri: string, ssl: bool}}
     */
    public function createDatabaseClusterFromBackup(
        string $engine,
        string $region,
        string $size,
        string $name,
        string $sourceClusterName,
        string $backupCreatedAt,
        ?string $version = null,
        array $tags = [],
    ): array {
        $constrained = $this->constrainDatabaseCreateToCatalog($engine, $region, $size, $version);

        $payload = [
            'name' => $name,
            'engine' => $constrained['engine'],
            'region' => $constrained['region'],
            'size' => $constrained['size'],
            'num_nodes' => 1,
            'backup_restore' => [
                'database_name' => $sourceClusterName,
                'backup_created_at' => $backupCreatedAt,
            ],
        ];
        if ($constrained['version'] !== '') {
            $payload['version'] = $constrained['version'];
        }
        $tags = $this->normalizeDatabaseTags($tags);
        if ($tags !== []) {
            $payload['tags'] = $tags;
        }

        return $this->sendDatabaseCreate($payload);
    }

    /**
     * Fetch a monitoring metric for a managed cluster over a UNIX-timestamp
     * window. $metric is the DigitalOcean metric path segment — `cpu`,
     * `memory_utilization`, `disk_utilization`, `load_1`, `load_5`, `load_15`.
     *
     * The payload is the same Prometheus-style `matrix` App Platform returns
     * ({@see \App\Modules\Cloud\Services\DigitalOceanAppPlatformService::getAppMetric()}):
     * `data.result[].values` is a list of [unix-ts, "string-value"] pairs. A
     * cluster too young to have datapoints answers 200 with an empty result,
     * so an empty list here means "nothing to plot", never "the call failed".
     *
     * Cached 60s per cluster+metric+window: the metrics tab draws one chart
     * per metric and Livewire re-renders all of them on every interaction.
     *
     * Docs: GET /v2/monitoring/metrics/database/{metric}
     *
     * @return list<array{t: int, v: float}>
     */
    public function getDatabaseMetric(string $clusterId, string $metric, int $start, int $end): array
    {
        $cacheKey = 'do_db_metric:'.sha1(implode('|', [$this->token, $clusterId, $metric, (string) $start, (string) $end]));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->request('get', '/monitoring/metrics/database/'.$metric, [
            'host_id' => $clusterId,
            'start' => (string) $start,
            'end' => (string) $end,
        ]);
        $this->assertSuccess($response, 'get database metric '.$metric);

        $points = $this->flattenPrometheusMatrix($response->json());
        Cache::put($cacheKey, $points, now()->addSeconds(60));

        return $points;
    }

    /**
     * Flatten the first series of a Prometheus `matrix` response into
     * {t, v} points. Anything unexpected degrades to an empty list — a
     * missing chart is a better failure than a 500 on the page around it.
     *
     * @return list<array{t: int, v: float}>
     */
    private function flattenPrometheusMatrix(mixed $payload): array
    {
        $payload = is_array($payload) ? $payload : [];
        $result = $payload['data']['result'] ?? null;
        if (! is_array($result) || $result === []) {
            return [];
        }

        $series = is_array($result[0] ?? null) ? $result[0] : null;
        if (! is_array($series) || ! is_array($series['values'] ?? null)) {
            return [];
        }

        $points = [];
        foreach ($series['values'] as $pair) {
            if (! is_array($pair) || count($pair) < 2) {
                continue;
            }
            $points[] = [
                't' => (int) $pair[0],
                'v' => (float) $pair[1],
            ];
        }

        return $points;
    }

    /**
     * Delete a DigitalOcean Managed Database cluster. Returns true on a
     * successful delete (204), false on a 404 (already gone) so teardown
     * is idempotent — mirrors {@see deleteKubernetesCluster()}.
     */
    public function deleteDatabaseCluster(string $clusterId): bool
    {
        $response = $this->request('delete', '/databases/'.$clusterId);
        if ($response->status() === 404) {
            return false;
        }
        $this->assertSuccess($response, 'delete database cluster');

        return true;
    }

    /**
     * @return array{id: string, name: string, status: string, engine: string, tags: list<string>, connection: array{host: string, port: int, user: string, password: string, database: string, uri: string, ssl: bool}}
     */
    private function normalizeDatabaseCluster(mixed $database): array
    {
        $database = is_array($database) ? $database : [];
        $connection = is_array($database['connection'] ?? null) ? $database['connection'] : [];

        return [
            'id' => (string) ($database['id'] ?? ''),
            // The provider-side cluster name. dply generates it at create and
            // never stores it, so a restore has to read it back from here —
            // `backup_restore` keys off the name, not the id.
            'name' => (string) ($database['name'] ?? ''),
            'status' => (string) ($database['status'] ?? ''),
            'engine' => (string) ($database['engine'] ?? ''),
            'tags' => $this->normalizeDatabaseTags(is_array($database['tags'] ?? null) ? $database['tags'] : []),
            'connection' => [
                'host' => (string) ($connection['host'] ?? ''),
                'port' => (int) ($connection['port'] ?? 0),
                'user' => (string) ($connection['user'] ?? ''),
                'password' => (string) ($connection['password'] ?? ''),
                'database' => (string) ($connection['database'] ?? ''),
                'uri' => (string) ($connection['uri'] ?? ''),
                'ssl' => (bool) ($connection['ssl'] ?? true),
            ],
        ];
    }
}
