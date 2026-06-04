<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

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
     * @return array<string, array<int, array<string, mixed>>>  keyed by resource id
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
