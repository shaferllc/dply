<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;

/**
 * Surfaces the sites that consume a server resource (database / cache service)
 * via a {@see SiteBinding}, from the *backend* server's side.
 *
 * Today that relationship is invisible on the resource's workspace: you can't
 * tell a database is being used by a site living on another server, reaching in
 * over the private network. This builds a "who uses this" list — every consumer
 * flagged local/remote and badged for reachability (from the binding's
 * connectivity probe) — so the resource tab can show its dependents and link an
 * unreachable one back to the owning site's Fix-connectivity flow.
 */
trait SurfacesBindingConsumers
{
    /**
     * Consumer rows for a set of server resources, grouped by target id.
     *
     * The caller passes the resource's `target_type` ('server_database' or
     * 'server_cache_service'), the ids to match, and the backend server id so
     * each consumer can be flagged local (same server) or remote.
     *
     * @param  array<int, int|string>  $resourceIds
     * @return array<string, array<int, array<string, mixed>>> keyed by resource id
     */
    protected function buildBindingConsumers(string $targetType, array $resourceIds, int|string $backendServerId): array
    {
        $ids = array_values(array_unique(array_map('strval', $resourceIds)));
        if ($ids === []) {
            return [];
        }

        $bindings = SiteBinding::query()
            ->where('target_type', $targetType)
            ->whereIn('target_id', $ids)
            ->with('site.server')
            ->get();

        $out = [];
        foreach ($bindings as $binding) {
            $site = $binding->site;
            if (! $site instanceof Site || $site->server === null) {
                continue;
            }

            $conn = is_array($binding->config) ? ($binding->config['connectivity'] ?? null) : null;
            $reachable = is_array($conn) && array_key_exists('ok', $conn) ? (bool) $conn['ok'] : null;

            $out[(string) $binding->target_id][] = [
                'binding_id' => (string) $binding->id,
                'site_id' => (string) $site->id,
                'site_name' => (string) ($site->name ?: __('Site')),
                'server_name' => (string) $site->server->name,
                'is_remote' => (string) $site->server->id !== (string) $backendServerId,
                'type' => (string) $binding->type,
                'reachable' => $reachable,
                'checked_at' => is_array($conn) ? ($conn['checked_at'] ?? null) : null,
                'detail' => $binding->last_error ?: (is_array($conn) ? ($conn['detail'] ?? null) : null),
                'site_url' => route('sites.show', ['server' => $site->server_id, 'site' => $site->id, 'section' => 'general']),
                'fix_url' => $this->bindingFixUrl($site),
            ];
        }

        return $out;
    }

    /**
     * The inverse of {@see buildBindingConsumers()}: databases / caches hosted on
     * *other* servers that sites on the given server attach to — what this server
     * reaches out to over the private network. Local attachments (target on the
     * same box) are excluded; those live on the Databases/Caches tabs already.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildAttachedRemoteResources(int|string $serverId): array
    {
        $bindings = SiteBinding::query()
            ->whereIn('target_type', ['server_database', 'server_cache_service'])
            ->whereHas('site', fn ($q) => $q->where('server_id', $serverId))
            ->with('site')
            ->get();

        if ($bindings->isEmpty()) {
            return [];
        }

        $dbIds = $bindings->where('target_type', 'server_database')->pluck('target_id')->filter()->unique()->all();
        $cacheIds = $bindings->where('target_type', 'server_cache_service')->pluck('target_id')->filter()->unique()->all();

        $databases = $dbIds === []
            ? collect()
            : ServerDatabase::query()->whereIn('id', $dbIds)->with('server')->get()->keyBy('id');
        $caches = $cacheIds === []
            ? collect()
            : ServerCacheService::query()->whereIn('id', $cacheIds)->with('server')->get()->keyBy('id');

        $out = [];
        foreach ($bindings as $binding) {
            $isDb = $binding->target_type === 'server_database';
            $target = $isDb ? $databases->get($binding->target_id) : $caches->get($binding->target_id);
            if ($target === null || $target->server === null) {
                continue;
            }
            // Remote only — skip resources hosted on this same server.
            if ((string) $target->server->id === (string) $serverId) {
                continue;
            }

            $site = $binding->site;
            $conn = is_array($binding->config) ? ($binding->config['connectivity'] ?? null) : null;
            $reachable = is_array($conn) && array_key_exists('ok', $conn) ? (bool) $conn['ok'] : null;

            $out[] = [
                'kind' => $isDb ? 'database' : 'cache',
                'resource_name' => (string) ($target->name ?: ucfirst((string) $target->engine)),
                'engine' => (string) $target->engine,
                'host_server_name' => (string) $target->server->name,
                'site_name' => $site instanceof Site ? (string) ($site->name ?: __('Site')) : '—',
                'type' => (string) $binding->type,
                'reachable' => $reachable,
                'checked_at' => is_array($conn) ? ($conn['checked_at'] ?? null) : null,
                'detail' => $binding->last_error ?: (is_array($conn) ? ($conn['detail'] ?? null) : null),
                'fix_url' => $site instanceof Site ? $this->bindingFixUrl($site) : null,
            ];
        }

        return $out;
    }

    /** Deep-link to the consuming site's Settings → environment section, where the Fix-connectivity flow lives. */
    protected function bindingFixUrl(Site $site): string
    {
        return route('sites.show', [
            'server' => $site->server_id,
            'site' => $site->id,
            'section' => 'environment',
        ]);
    }
}
