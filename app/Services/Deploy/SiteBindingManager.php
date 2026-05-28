<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\Servers\ServerDatabaseProvisioner;
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
            'database' => ServerDatabase::query()
                ->where('server_id', $site->server_id)
                ->orderBy('name')
                ->get()
                ->map(fn (ServerDatabase $db) => [
                    'id' => (string) $db->id,
                    'label' => $db->name.' ('.$db->engine.')',
                ])
                ->all(),
            default => [],
        };
    }

    /**
     * Attach an existing resource to the site.
     *
     * @param  array<string, mixed>  $params
     */
    public function attachExisting(Site $site, string $type, array $params): SiteBinding
    {
        $this->assertType($type);

        return match ($type) {
            'database' => $this->attachDatabase($site, $params),
            'redis' => $this->attachRedis($site),
            'queue' => $this->attachQueue($site, $params),
            'storage' => $this->attachStorage($site, $params),
            'scheduler', 'workers' => $this->attachMarker($site, $type),
            default => throw new InvalidArgumentException(__('This binding type cannot be attached yet.')),
        };
    }

    /**
     * Provision a brand-new resource, then attach it.
     *
     * @param  array<string, mixed>  $params
     */
    public function provisionNew(Site $site, string $type, array $params): SiteBinding
    {
        $this->assertType($type);

        return match ($type) {
            'database' => $this->provisionDatabase($site, $params),
            // Redis/queue/storage/scheduler/workers have no separate resource to
            // spin up beyond what attach already wires, so provision falls back
            // to the attach path for v1.
            default => $this->attachExisting($site, $type, $params),
        };
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

        $db = ServerDatabase::query()
            ->where('server_id', $site->server_id)
            ->whereKey($databaseId)
            ->first();

        if (! $db instanceof ServerDatabase) {
            throw new InvalidArgumentException(__('That database is not on this site\'s server.'));
        }

        return $this->persist($site, 'database', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $db->name,
            'target_type' => 'server_database',
            'target_id' => (string) $db->id,
            'injected_env' => $this->databaseEnv($db),
            'config' => ['engine' => $db->engine],
        ]);
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
                'injected_env' => $this->databaseEnv($db),
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
            'injected_env' => $this->databaseEnv($db),
            'config' => ['engine' => $db->engine],
            'last_error' => null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function databaseEnv(ServerDatabase $db): array
    {
        if ($db->engine === 'sqlite') {
            return [
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => (string) ($db->host ?: ''),
                'DATABASE_URL' => $db->connectionUrl(),
            ];
        }

        return [
            'DB_CONNECTION' => $db->engine === 'postgres' ? 'pgsql' : 'mysql',
            'DB_HOST' => (string) ($db->host ?: '127.0.0.1'),
            'DB_PORT' => (string) $db->defaultPort(),
            'DB_DATABASE' => (string) $db->name,
            'DB_USERNAME' => (string) $db->username,
            'DB_PASSWORD' => (string) $db->password,
            'DATABASE_URL' => $db->connectionUrl(),
        ];
    }

    // ---- redis ------------------------------------------------------------

    private function attachRedis(Site $site): SiteBinding
    {
        $server = $site->server;
        $cacheService = is_array($server?->meta) ? ($server->meta['cache_service'] ?? null) : null;
        if ($cacheService !== 'redis') {
            throw new RuntimeException(__('This server does not have Redis installed. Install it from the server Caches workspace first.'));
        }

        return $this->persist($site, 'redis', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => 'server-redis',
            'target_type' => 'server_cache_service',
            'target_id' => (string) $server->id,
            'injected_env' => [
                'REDIS_CLIENT' => 'phpredis',
                'REDIS_HOST' => '127.0.0.1',
                'REDIS_PORT' => '6379',
            ],
            'config' => [],
        ]);
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

    // ---- object storage ---------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    private function attachStorage(Site $site, array $params): SiteBinding
    {
        $bucket = trim((string) ($params['bucket'] ?? ''));
        $key = trim((string) ($params['access_key_id'] ?? ''));
        $secret = trim((string) ($params['secret_access_key'] ?? ''));
        if ($bucket === '' || $key === '' || $secret === '') {
            throw new InvalidArgumentException(__('Bucket, access key, and secret are required.'));
        }

        $env = array_filter([
            'FILESYSTEM_DISK' => 's3',
            'AWS_BUCKET' => $bucket,
            'AWS_ACCESS_KEY_ID' => $key,
            'AWS_SECRET_ACCESS_KEY' => $secret,
            'AWS_DEFAULT_REGION' => trim((string) ($params['region'] ?? '')) ?: null,
            'AWS_URL' => trim((string) ($params['url'] ?? '')) ?: null,
            'AWS_ENDPOINT' => trim((string) ($params['endpoint'] ?? '')) ?: null,
        ], fn ($v) => $v !== null);

        return $this->persist($site, 'storage', [
            'mode' => 'attach_existing',
            'status' => SiteBinding::STATUS_CONFIGURED,
            'name' => $bucket,
            'target_type' => 'object_storage',
            'target_id' => null,
            'injected_env' => $env,
            'config' => ['bucket' => $bucket],
        ]);
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
