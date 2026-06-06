<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\ServerCronJob;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\SupervisorProgram;
use App\Support\Deployment\SiteResourceBinding;

final class SiteResourceBindingResolver
{
    /**
     * Per-request memo keyed by site id. Lives as long as this instance does;
     * the AppServiceProvider binds the class as `scoped` so the cache resets
     * between requests (Octane-safe).
     *
     * @var array<string, list<SiteResourceBinding>>
     */
    private array $cache = [];

    public function __construct(
        private readonly DeploymentSecretInventory $secretInventory,
    ) {}

    /**
     * @return list<SiteResourceBinding>
     */
    public function forSite(Site $site): array
    {
        $key = (string) $site->id;
        if ($key !== '' && isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $bindings = [];
        $mode = $site->runtimeTargetMode();
        $serverId = $site->server_id;

        // Role-aware suppression: queue-worker hosts already ARE the queue
        // worker for some other app, and they don't host their own DB. So
        // the "database binding pending" and "workers binding pending"
        // preflight warnings are structurally wrong on these boxes — the
        // DB lives on a separate server, and the supervisor programs are
        // exactly what the worker host provides. Mark those bindings
        // required=false here so DeploymentPreflightValidator drops them
        // from the preflight summary while still leaving the binding rows
        // visible for debugging.
        $serverMeta = is_array($site->server?->meta) ? $site->server->meta : [];
        $installProfile = (string) ($serverMeta['install_profile'] ?? '');
        $serverRole = (string) ($serverMeta['server_role'] ?? '');
        $isWorkerHost = $installProfile === 'queue_worker' || $serverRole === 'worker';

        $databaseCount = $serverId
            ? ServerDatabase::query()->where('server_id', $serverId)->count()
            : 0;
        $cronCount = $serverId
            ? ServerCronJob::query()
                ->where('server_id', $serverId)
                ->where(function ($query) use ($site): void {
                    $query->whereNull('site_id')->orWhere('site_id', $site->id);
                })
                ->count()
            : 0;
        $workerCount = $serverId
            ? SupervisorProgram::query()
                ->where('server_id', $serverId)
                ->where(function ($query) use ($site): void {
                    $query->whereNull('site_id')->orWhere('site_id', $site->id);
                })
                ->count()
            : 0;

        $env = $this->secretInventory->effectiveEnvironmentMapForSite($site);

        $bindings[] = new SiteResourceBinding(
            type: 'database',
            mode: $mode === 'vm' ? 'provision_new' : 'attach_existing',
            required: ! $isWorkerHost
                && in_array($mode, ['vm', 'docker', 'kubernetes'], true)
                && $site->type?->value !== 'static',
            status: $databaseCount > 0 ? 'configured' : 'pending',
            source: $databaseCount > 0 ? 'server_database' : 'inferred_requirement',
            name: $databaseCount > 0 ? 'server-database' : null,
            config: ['count' => $databaseCount],
        );

        $bindings[] = new SiteResourceBinding(
            type: 'scheduler',
            mode: $mode === 'vm' ? 'provision_new' : 'attach_existing',
            required: $mode === 'vm',
            status: $cronCount > 0 ? 'configured' : 'pending',
            source: $cronCount > 0 ? 'server_cron_jobs' : 'inferred_requirement',
            name: $cronCount > 0 ? 'server-cron' : null,
            config: ['count' => $cronCount],
        );

        $bindings[] = new SiteResourceBinding(
            type: 'workers',
            mode: $mode === 'vm' ? 'provision_new' : 'attach_existing',
            required: $mode === 'vm' && ! $isWorkerHost,
            status: $workerCount > 0 ? 'configured' : 'pending',
            source: $workerCount > 0 ? 'supervisor_programs' : 'inferred_requirement',
            name: $workerCount > 0 ? 'supervisor' : null,
            config: ['count' => $workerCount],
        );

        $bindings[] = new SiteResourceBinding(
            type: 'publication',
            mode: 'provision_new',
            required: true,
            status: $this->publicationStatus($site),
            source: 'runtime_target',
            name: 'publication',
            config: is_array(data_get($site->runtimeTarget(), 'publication')) ? data_get($site->runtimeTarget(), 'publication') : [],
        );

        $bindings[] = $this->redisBinding($env);
        $bindings[] = $this->queueBinding($env);
        $bindings[] = $this->cacheBinding($env);
        $bindings[] = $this->objectStorageBinding($env);

        // A persisted SiteBinding (an explicit operator attach/provision) is
        // authoritative: it replaces the derived inference for its type so the
        // UI shows the managed state and the deploy reads the bound resource.
        // The publication binding stays runtime-owned and is never manageable.
        // Reuse the already-loaded relation (effectiveEnvironmentMapForSite()
        // above calls loadMissing('bindings')) rather than bindings()->get(),
        // which would re-query the same rows.
        $persisted = $site->loadMissing('bindings')->bindings->keyBy('type');
        $bindings = array_map(function (SiteResourceBinding $derived) use ($persisted): SiteResourceBinding {
            $manageable = $derived->type !== 'publication';

            $row = $persisted->get($derived->type);
            if (! $row instanceof SiteBinding) {
                return new SiteResourceBinding(
                    type: $derived->type,
                    mode: $derived->mode,
                    required: $derived->required,
                    status: $derived->status,
                    source: $derived->source,
                    name: $derived->name,
                    config: $derived->config,
                    bindingId: null,
                    manageable: $manageable,
                );
            }

            return new SiteResourceBinding(
                type: $derived->type,
                mode: (string) ($row->mode ?: $derived->mode),
                required: $derived->required,
                status: (string) ($row->status ?: $derived->status),
                source: 'binding',
                name: $row->name ?: $derived->name,
                config: array_merge($derived->config, is_array($row->config) ? $row->config : [], [
                    'last_error' => $row->last_error,
                ]),
                bindingId: (string) $row->id,
                manageable: $manageable,
            );
        }, $bindings);

        if ($key !== '') {
            $this->cache[$key] = $bindings;
        }

        return $bindings;
    }

    /**
     * @param  array<string, string>  $env
     */
    private function redisBinding(array $env): SiteResourceBinding
    {
        if ($this->envHasRedisConnection($env)) {
            return new SiteResourceBinding(
                type: 'redis',
                mode: 'attach_existing',
                required: false,
                status: 'configured',
                source: 'environment',
                name: 'redis',
                config: [],
            );
        }

        if ($this->envReferencesRedisWithoutHost($env)) {
            return new SiteResourceBinding(
                type: 'redis',
                mode: 'attach_existing',
                required: false,
                status: 'pending',
                source: 'environment',
                name: null,
                config: ['reason' => 'drivers_reference_redis_without_connection'],
            );
        }

        return new SiteResourceBinding(
            type: 'redis',
            mode: 'attach_existing',
            required: false,
            status: 'pending',
            source: 'deferred',
            name: null,
            config: [],
        );
    }

    /**
     * @param  array<string, string>  $env
     */
    private function queueBinding(array $env): SiteResourceBinding
    {
        $driver = strtolower(trim((string) ($env['QUEUE_CONNECTION'] ?? '')));

        if ($driver === '' || $driver === 'sync' || $driver === 'null') {
            return new SiteResourceBinding(
                type: 'queue',
                mode: 'attach_existing',
                required: false,
                status: 'pending',
                source: $driver === 'sync' || $driver === '' ? 'environment_defaults' : 'environment',
                name: null,
                config: ['driver' => $driver !== '' ? $driver : 'sync'],
            );
        }

        return new SiteResourceBinding(
            type: 'queue',
            mode: 'attach_existing',
            required: false,
            status: 'configured',
            source: 'environment',
            name: 'queue-'.$driver,
            config: ['driver' => $driver],
        );
    }

    /**
     * @param  array<string, string>  $env
     */
    private function cacheBinding(array $env): SiteResourceBinding
    {
        $driver = strtolower(trim((string) ($env['CACHE_STORE'] ?? $env['CACHE_DRIVER'] ?? '')));

        // No store, or the per-request `array` store (which caches nothing
        // across requests), reads as "not configured yet" — same treatment the
        // queue binding gives sync/null.
        if ($driver === '' || $driver === 'array') {
            return new SiteResourceBinding(
                type: 'cache',
                mode: 'attach_existing',
                required: false,
                status: 'pending',
                source: $driver === 'array' ? 'environment' : 'environment_defaults',
                name: null,
                config: ['driver' => $driver !== '' ? $driver : 'array'],
            );
        }

        return new SiteResourceBinding(
            type: 'cache',
            mode: 'attach_existing',
            required: false,
            status: 'configured',
            source: 'environment',
            name: 'cache-'.$driver,
            config: ['driver' => $driver],
        );
    }

    /**
     * @param  array<string, string>  $env
     */
    private function objectStorageBinding(array $env): SiteResourceBinding
    {
        $disk = strtolower(trim((string) ($env['FILESYSTEM_DISK'] ?? '')));
        $s3LikeDisk = in_array($disk, ['s3', 'spaces', 'digitalocean', 'r2'], true);
        $hasBucket = $this->envFilled($env, 'AWS_BUCKET');
        $hasUrl = $this->envFilled($env, 'AWS_URL');
        $hasKeys = $this->envFilled($env, 'AWS_ACCESS_KEY_ID') && $this->envFilled($env, 'AWS_SECRET_ACCESS_KEY');

        if (! $s3LikeDisk && ! $hasBucket && ! $hasUrl) {
            return new SiteResourceBinding(
                type: 'storage',
                mode: 'attach_existing',
                required: false,
                status: 'pending',
                source: 'deferred',
                name: null,
                config: [],
            );
        }

        if (($hasBucket || $hasUrl) && $hasKeys) {
            return new SiteResourceBinding(
                type: 'storage',
                mode: 'attach_existing',
                required: false,
                status: 'configured',
                source: 'environment',
                name: 'object-storage',
                config: array_filter([
                    'filesystem_disk' => $disk !== '' ? $disk : null,
                ]),
            );
        }

        $reason = match (true) {
            $s3LikeDisk && ! $hasBucket && ! $hasUrl => 's3_disk_without_bucket',
            ($hasBucket || $hasUrl) && ! $hasKeys => 'bucket_without_keys',
            default => 'incomplete_object_storage_env',
        };

        return new SiteResourceBinding(
            type: 'storage',
            mode: 'attach_existing',
            required: false,
            status: 'pending',
            source: 'environment',
            name: null,
            config: ['reason' => $reason],
        );
    }

    /**
     * @param  array<string, string>  $env
     */
    private function envFilled(array $env, string $key): bool
    {
        $value = $env[$key] ?? '';

        return is_string($value) && trim($value) !== '';
    }

    /**
     * @param  array<string, string>  $env
     */
    private function envHasRedisConnection(array $env): bool
    {
        return $this->envFilled($env, 'REDIS_URL')
            || $this->envFilled($env, 'REDIS_HOST');
    }

    /**
     * @param  array<string, string>  $env
     */
    private function envReferencesRedisWithoutHost(array $env): bool
    {
        if ($this->envHasRedisConnection($env)) {
            return false;
        }

        $cache = strtolower(trim((string) ($env['CACHE_STORE'] ?? $env['CACHE_DRIVER'] ?? '')));
        $session = strtolower(trim((string) ($env['SESSION_DRIVER'] ?? '')));
        $queue = strtolower(trim((string) ($env['QUEUE_CONNECTION'] ?? '')));

        return $cache === 'redis' || $session === 'redis' || $queue === 'redis';
    }

    private function publicationStatus(Site $site): string
    {
        $publication = is_array(data_get($site->runtimeTarget(), 'publication')) ? data_get($site->runtimeTarget(), 'publication') : [];
        $status = $publication['status'] ?? $site->testingHostnameStatus();

        if (is_string($status) && in_array($status, ['ready', 'configured'], true)) {
            return 'configured';
        }

        return 'pending';
    }
}
