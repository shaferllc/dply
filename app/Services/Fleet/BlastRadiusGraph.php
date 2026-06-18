<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;

/**
 * Org inventory as a dependency graph — servers, sites, databases, and
 * hybrid Edge ↔ Cloud links. Powers the Fleet blast-radius explorer.
 */
final class BlastRadiusGraph
{
    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];

    /** @var list<array{from: string, to: string, kind: string, label: string}> */
    private array $edges = [];

    /** @var array<string, list<string>> */
    private array $dependents = [];

    public static function forOrganization(Organization $organization): self
    {
        $graph = new self;
        $graph->build($organization);

        return $graph;
    }

    /**
     * @return list<array<string, mixed>>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, mixed>>
     */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    /**
     * @return list<array<string, mixed>>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, string>>
     */
    public function edges(): array
    {
        return $this->edges;
    }

    /**
     * @return list<array<string, string>>
     */
    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    /**
     * Resources that would break if $nodeId fails (transitive dependents).
     *
     * @return list<array<string, mixed>>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, mixed>>
     */
    public function affectedBy(string $nodeId): array
    {
        if (! isset($this->nodes[$nodeId])) {
            return [];
        }

        $seen = [];
        $queue = [$nodeId];
        $affected = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($this->dependents[$current] ?? [] as $dependentId) {
                if (isset($seen[$dependentId])) {
                    continue;
                }
                $seen[$dependentId] = true;
                $affected[] = $this->nodes[$dependentId];
                $queue[] = $dependentId;
            }
        }

        return $affected;
    }

    /**
     * @return list<array<string, mixed>>
     */
    /** @return array<string, mixed> */
    public function counts(): array
    {
        $servers = $sites = $databases = 0;
        foreach ($this->nodes as $node) {
            match ($node['kind']) {
                'server' => $servers++,
                'site' => $sites++,
                'database' => $databases++,
                default => null,
            };
        }

        return [
            'servers' => $servers,
            'sites' => $sites,
            'databases' => $databases,
            'links' => count($this->edges),
        ];
    }

    private function build(Organization $organization): void
    {
        $servers = Server::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'provider', 'ip_address']);

        $serverIds = $servers->pluck('id');

        $sites = Site::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'status', 'server_id', 'edge_backend', 'container_backend', 'meta', 'type']);

        $databases = ServerDatabase::query()
            ->whereIn('server_id', $serverIds)
            ->orderBy('name')
            ->get(['id', 'name', 'engine', 'server_id']);

        foreach ($servers as $server) {
            $this->addNode([
                'id' => $this->serverId($server->id),
                'kind' => 'server',
                'label' => $server->name,
                'status' => $server->status,
                'product' => 'byo',
                'href' => route('servers.overview', $server),
            ]);
        }

        foreach ($databases as $database) {
            $from = $this->serverId($database->server_id);
            $id = $this->databaseId($database->id);
            $this->addNode([
                'id' => $id,
                'kind' => 'database',
                'label' => $database->name,
                'status' => 'provisioned',
                'product' => 'byo',
                'engine' => $database->engine,
                'href' => route('servers.databases', $database->server_id),
            ]);
            if (isset($this->nodes[$from])) {
                $this->addEdge($from, $id, 'hosts', __('Database on server'));
            }
        }

        $siteIndex = [];
        foreach ($sites as $site) {
            $product = $this->siteProduct($site);
            $id = $this->siteId($site->id);
            $href = $site->server_id
                ? route('sites.show', [$site->server_id, $site, 'section' => 'general'])
                : null;

            $this->addNode([
                'id' => $id,
                'kind' => 'site',
                'label' => $site->name,
                'status' => $site->status,
                'product' => $product,
                'href' => $href,
            ]);
            $siteIndex[$site->id] = $site;

            if ($site->server_id && isset($this->nodes[$this->serverId($site->server_id)])) {
                $this->addEdge(
                    $this->serverId($site->server_id),
                    $id,
                    'hosts',
                    __('Site on server'),
                );
            }
        }

        foreach ($sites as $site) {
            if (! $site->usesEdgeRuntime()) {
                continue;
            }

            $edgeId = $this->siteId($site->id);
            $origin = is_array($site->edgeMeta()['origin'] ?? null) ? $site->edgeMeta()['origin'] : [];
            $cloudSiteId = (string) ($origin['cloud_site_id'] ?? '');
            if ($cloudSiteId !== '' && isset($siteIndex[$cloudSiteId])) {
                $this->addEdge(
                    $this->siteId($cloudSiteId),
                    $edgeId,
                    'origin',
                    __('Hybrid SSR origin'),
                );
            } elseif (is_string($origin['url'] ?? null) && trim((string) $origin['url']) !== '') {
                $this->nodes[$edgeId]['external_origin'] = trim((string) $origin['url']);
            }
        }

        foreach ($sites as $site) {
            if (! $site->usesContainerRuntime()) {
                continue;
            }
            $meta = ($site->meta );
            $stack = is_array($meta['container']['hybrid_edge_stack'] ?? null)
                ? $meta['container']['hybrid_edge_stack']
                : [];
            $edgeSiteId = (string) ($stack['edge_site_id'] ?? '');
            if ($edgeSiteId === '' || ! isset($siteIndex[$edgeSiteId])) {
                continue;
            }
            $this->addEdge(
                $this->siteId($site->id),
                $this->siteId($edgeSiteId),
                'hybrid_stack',
                __('Hybrid stack pair'),
            );
        }
    }

    /**
     * @param  array<string, mixed> $node
     */
    private function addNode(array $node): void
    {
        $this->nodes[$node['id']] = $node;
    }

    private function addEdge(string $from, string $to, string $kind, string $label): void
    {
        $this->edges[] = [
            'from' => $from,
            'to' => $to,
            'kind' => $kind,
            'label' => $label,
        ];

        if (! isset($this->dependents[$from])) {
            $this->dependents[$from] = [];
        }
        $this->dependents[$from][] = $to;
    }

    private function serverId(string $id): string
    {
        return 'server:'.$id;
    }

    private function siteId(string $id): string
    {
        return 'site:'.$id;
    }

    private function databaseId(string $id): string
    {
        return 'database:'.$id;
    }

    private function siteProduct(Site $site): string
    {
        if ($site->usesEdgeRuntime()) {
            return 'edge';
        }
        if ($site->usesFunctionsRuntime()) {
            return 'serverless';
        }
        if ($site->usesContainerRuntime()) {
            return 'cloud';
        }

        return 'byo';
    }
}
