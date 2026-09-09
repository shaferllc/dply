<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\CloudDatabase;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\Site;
use App\Models\SiteBinding;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * When the site workspace Database tab is worth showing, and how to describe
 * the remote / hosted databases that keep it visible without an on-box engine.
 */
final class SiteDatabaseWorkspace
{
    /** @var list<string> */
    private const USABLE_ENGINE_STATUSES = [
        ServerDatabaseEngine::STATUS_PENDING,
        ServerDatabaseEngine::STATUS_INSTALLING,
        ServerDatabaseEngine::STATUS_RUNNING,
    ];

    /**
     * Show the Database tab when this site has on-box databases (or an engine
     * that can create one), or a remote / hosted database that can be configured.
     */
    public static function shouldShowTab(Site $site, Server $server): bool
    {
        return self::hasOnBoxDatabaseSurface($site, $server)
            || self::hasRemoteConfigurableDatabase($site);
    }

    public static function hasOnBoxDatabaseSurface(Site $site, Server $server): bool
    {
        return self::hasLinkedServerDatabases($site)
            || self::hasUsableOnBoxEngine($server);
    }

    public static function hasRemoteConfigurableDatabase(Site $site): bool
    {
        return self::databaseBindings($site)->contains(
            static fn (SiteBinding $binding): bool => $binding->isRemoteConfigurableDatabase()
        );
    }

    /**
     * @return Collection<int, SiteBinding>
     */
    public static function remoteConfigurableBindings(Site $site): Collection
    {
        return self::databaseBindings($site)
            ->filter(static fn (SiteBinding $binding): bool => $binding->isRemoteConfigurableDatabase())
            ->values();
    }

    /**
     * Cards for the site Database page: hosted / remote bindings the operator
     * can still configure (resize, credentials, detach) from Environment.
     *
     * @return list<array{id: string, name: string, engine: string, placement: string, status: string, host: ?string}>
     */
    public static function remoteConfigurableSummaries(Site $site): array
    {
        $bindings = self::remoteConfigurableBindings($site);
        if ($bindings->isEmpty()) {
            return [];
        }

        $clusterIds = $bindings
            ->filter(static fn (SiteBinding $binding): bool => $binding->target_type === 'cloud_database' && filled($binding->target_id))
            ->pluck('target_id')
            ->unique()
            ->values()
            ->all();

        $clusters = $clusterIds === []
            ? collect()
            : CloudDatabase::query()->whereIn('id', $clusterIds)->get()->keyBy('id');

        return $bindings->map(function (SiteBinding $binding) use ($clusters): array {
            $config = is_array($binding->config) ? $binding->config : [];
            $cluster = $binding->target_type === 'cloud_database'
                ? $clusters->get((string) $binding->target_id)
                : null;

            $env = $binding->connectionEnv();
            $host = trim((string) ($env['DB_HOST'] ?? $config['host'] ?? ''));

            return [
                'id' => (string) $binding->id,
                'name' => self::bindingDisplayName($binding, $cluster instanceof CloudDatabase ? $cluster : null),
                'engine' => self::bindingEngineLabel($binding, $cluster instanceof CloudDatabase ? $cluster : null),
                'placement' => self::bindingPlacementLabel($binding, $cluster instanceof CloudDatabase ? $cluster : null),
                'status' => $cluster instanceof CloudDatabase ? (string) $cluster->status : (string) $binding->status,
                'host' => $host !== '' ? $host : null,
            ];
        })->all();
    }

    private static function hasLinkedServerDatabases(Site $site): bool
    {
        if ($site->relationLoaded('serverDatabases')) {
            return $site->serverDatabases->isNotEmpty();
        }

        return $site->exists && $site->serverDatabases()->exists();
    }

    private static function hasUsableOnBoxEngine(Server $server): bool
    {
        if ($server->relationLoaded('databaseEngines')) {
            return $server->databaseEngines->contains(
                static fn (ServerDatabaseEngine $engine): bool => in_array($engine->status, self::USABLE_ENGINE_STATUSES, true)
            );
        }

        return $server->exists
            && $server->databaseEngines()->whereIn('status', self::USABLE_ENGINE_STATUSES)->exists();
    }

    /**
     * @return Collection<int, SiteBinding>
     */
    private static function databaseBindings(Site $site): Collection
    {
        if ($site->relationLoaded('bindings')) {
            return $site->bindings
                ->filter(static fn (SiteBinding $binding): bool => $binding->type === 'database')
                ->values();
        }

        if (! $site->exists) {
            return collect();
        }

        return $site->bindings()->where('type', 'database')->get();
    }

    private static function bindingDisplayName(SiteBinding $binding, ?CloudDatabase $cluster): string
    {
        if ($cluster instanceof CloudDatabase && filled($cluster->name)) {
            return (string) $cluster->name;
        }

        if (filled($binding->name)) {
            return (string) $binding->name;
        }

        return __('Hosted database');
    }

    private static function bindingEngineLabel(SiteBinding $binding, ?CloudDatabase $cluster): string
    {
        $engine = $cluster?->engine
            ?? (is_array($binding->config) ? ($binding->config['engine'] ?? null) : null);

        return filled($engine) ? Str::headline((string) $engine) : __('Database');
    }

    private static function bindingPlacementLabel(SiteBinding $binding, ?CloudDatabase $cluster): string
    {
        $config = is_array($binding->config) ? $binding->config : [];

        if ($cluster instanceof CloudDatabase) {
            return match ($cluster->backend) {
                CloudDatabase::BACKEND_DIGITALOCEAN => __('DigitalOcean managed'),
                CloudDatabase::BACKEND_VULTR => __('Vultr managed'),
                CloudDatabase::BACKEND_NEON => __('Neon'),
                CloudDatabase::BACKEND_SUPABASE => __('Supabase'),
                CloudDatabase::BACKEND_PLANETSCALE => __('PlanetScale'),
                CloudDatabase::BACKEND_UPSTASH => __('Upstash'),
                CloudDatabase::BACKEND_EXTERNAL => __('External host'),
                default => __('Hosted'),
            };
        }

        if (! empty($config['managed']) || ($config['placement'] ?? '') === 'managed') {
            return __('Managed cluster');
        }

        return match ($config['placement'] ?? null) {
            'dedicated_vm' => __('Dedicated database server'),
            'docker_vm' => __('Dedicated Docker database server'),
            default => filled($config['placement'] ?? null)
                ? Str::headline((string) $config['placement'])
                : __('Remote database'),
        };
    }
}
