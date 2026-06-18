<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;

/**
 * Builds intra-server shared resource couplings from control-plane metadata.
 */
final class SharedStackMapBuilder
{
    /**
     * @return array{
     *     site_count: int,
     *     shared_resources: list<array{
     *         id: string,
     *         type: string,
     *         label: string,
     *         site_count: int,
     *         sites: list<array{slug: string, name: string, href: string}>,
     *         restart_impact: string,
     *     }>,
     *     edges: list<array{from: string, to: string, kind: string, label: string}>,
     * }
     */
    public function forServer(Server $server): array
    {
        $server->loadMissing([
            'sites.bindings',
            'serverDatabases',
        ]);

        $sites = $server->sites->sortBy('name')->values();
        $siteCount = $sites->count();

        if ($siteCount < 2) {
            return [
                'site_count' => $siteCount,
                'shared_resources' => [],
                'edges' => [],
            ];
        }

        $databaseGroups = [];
        $redisGroups = [];
        $edges = [];

        foreach ($sites as $site) {
            $siteNodeId = $this->siteNodeId($site);

            foreach ($site->bindings as $binding) {
                $type = (string) $binding->type;
                if (! in_array($type, ['database', 'redis', 'queue'], true)) {
                    continue;
                }

                $resourceKey = $this->resourceKey($binding, $server);
                if ($resourceKey === null) {
                    continue;
                }

                $resourceId = $type.':'.$resourceKey;
                $label = $this->resourceLabel($type, $resourceKey, $binding, $server);

                if ($type === 'database') {
                    $databaseGroups[$resourceId]['label'] = $label;
                    $databaseGroups[$resourceId]['type'] = 'database';
                    $databaseGroups[$resourceId]['sites'][] = $this->siteRef($server, $site);
                } elseif (in_array($type, ['redis', 'queue'], true)) {
                    $redisGroups[$resourceId]['label'] = $label;
                    $redisGroups[$resourceId]['type'] = $type === 'queue' ? 'queue' : 'redis';
                    $redisGroups[$resourceId]['sites'][] = $this->siteRef($server, $site);
                }

                $edges[] = [
                    'from' => $siteNodeId,
                    'to' => $resourceId,
                    'kind' => 'depends_on',
                    'label' => match ($type) {
                        'database' => __('Database'),
                        'queue' => __('Queue'),
                        default => __('Cache'),
                    },
                ];
            }
        }

        $sharedResources = [];

        foreach ([$databaseGroups, $redisGroups] as $groups) {
            foreach ($groups as $resourceId => $group) {
                $uniqueSites = $this->uniqueSiteRefs($group['sites']);
                if (count($uniqueSites) < 2) {
                    continue;
                }

                $type = $group['type'];
                $sharedResources[] = [
                    'id' => (string) $resourceId,
                    'type' => $type,
                    'label' => $group['label'],
                    'site_count' => count($uniqueSites),
                    'sites' => $uniqueSites,
                    'restart_impact' => $this->restartImpactCopy($type, count($uniqueSites)),
                ];
            }
        }

        usort($sharedResources, static fn (array $a, array $b): int => ($b['site_count'] <=> $a['site_count']) ?: strcmp($a['label'], $b['label']));

        return [
            'site_count' => $siteCount,
            'shared_resources' => $sharedResources,
            'edges' => $edges,
        ];
    }

    private function siteNodeId(Site $site): string
    {
        return 'site:'.(string) $site->id;
    }

    /**
     * @return array{slug: string, name: string, href: string}
     */
    private function siteRef(Server $server, Site $site): array
    {
        return [
            'slug' => (string) $site->slug,
            'name' => (string) $site->name,
            'href' => route('sites.show', ['server' => $server, 'site' => $site]),
        ];
    }

    /**
     * @param  list<array{slug: string, name: string, href: string}>  $sites
     * @return list<array{slug: string, name: string, href: string}>
     */
    private function uniqueSiteRefs(array $sites): array
    {
        $seen = [];
        $unique = [];
        foreach ($sites as $site) {
            $slug = $site['slug'];
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $unique[] = $site;
        }

        return $unique;
    }

    private function resourceKey(SiteBinding $binding, Server $server): ?string
    {
        $targetId = (string) ($binding->target_id ?? '');
        if ($targetId !== '') {
            return $targetId;
        }

        $env = $binding->connectionEnv();
        if ($binding->type === 'database') {
            $database = (string) ($env['DB_DATABASE'] ?? '');
            $host = (string) ($env['DB_HOST'] ?? '127.0.0.1');

            return $database !== '' ? $host.'/'.$database : null;
        }

        if (in_array($binding->type, ['redis', 'queue'], true)) {
            $host = (string) ($env['REDIS_HOST'] ?? $env['QUEUE_CONNECTION'] ?? '127.0.0.1');
            $port = (string) ($env['REDIS_PORT'] ?? '6379');

            return $host.':'.$port;
        }

        return null;
    }

    private function resourceLabel(string $type, string $resourceKey, SiteBinding $binding, Server $server): string
    {
        if ($binding->target_type === ServerDatabase::class && $binding->target_id) {
            $database = $server->serverDatabases->firstWhere('id', $binding->target_id);
            if ($database instanceof ServerDatabase) {
                return (string) ($database->name ?: $database->engine);
            }
        }

        $name = trim((string) ($binding->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return match ($type) {
            'database' => __('Database :key', ['key' => $resourceKey]),
            'queue' => __('Queue :key', ['key' => $resourceKey]),
            default => __('Redis :key', ['key' => $resourceKey]),
        };
    }

    private function restartImpactCopy(string $type, int $siteCount): string
    {
        return match ($type) {
            'database' => trans_choice(
                'Restarting or failing over this database engine may interrupt :count site until connections recover.|Restarting or failing over this database engine may interrupt :count sites until connections recover.',
                $siteCount,
                ['count' => $siteCount],
            ),
            'queue' => trans_choice(
                'Restarting this queue backend may stall workers on :count site.|Restarting this queue backend may stall workers on :count sites.',
                $siteCount,
                ['count' => $siteCount],
            ),
            default => trans_choice(
                'Restarting this cache instance may increase latency on :count site.|Restarting this cache instance may increase latency on :count sites.',
                $siteCount,
                ['count' => $siteCount],
            ),
        };
    }
}
