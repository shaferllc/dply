<?php

declare(strict_types=1);

namespace App\Services\Launch;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Support\Launch\StandbyPlaybook;
use Laravel\Pennant\Feature;

/**
 * Merges standby blueprint templates with org inventory — hybrid Edge stacks,
 * BYO servers, and custom domains — to produce actionable failover playbooks.
 */
final class StandbyBlueprintPlanner
{
    /**
     * @return list<array{key: string, title: string, summary: string, available: bool, unavailable_reason: string|null}>
     */
    public function catalog(Organization $organization): array
    {
        $inventory = $this->inventory($organization);
        $catalog = [];

        foreach (config('standby_blueprints', []) as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            [$available, $reason] = $this->availability($key, $inventory);

            $catalog[] = [
                'key' => (string) $key,
                'title' => (string) ($definition['title'] ?? $key),
                'summary' => (string) ($definition['summary'] ?? ''),
                'available' => $available,
                'unavailable_reason' => $reason,
            ];
        }

        return $catalog;
    }

    public function playbook(Organization $organization, string $key): ?StandbyPlaybook
    {
        $definition = config("standby_blueprints.{$key}");
        if (! is_array($definition)) {
            return null;
        }

        $inventory = $this->inventory($organization);
        [$available, $unavailableReason] = $this->availability($key, $inventory);

        $resources = match ($key) {
            'edge_hybrid_origin' => $this->hybridEdgeResources($inventory),
            'byo_standby_server' => $this->byoResources($inventory),
            'dns_cutover' => $this->dnsResources($inventory),
            default => [],
        };

        $gaps = $this->gaps($key, $inventory);
        $steps = $this->buildSteps($key, $definition, $inventory);

        return new StandbyPlaybook(
            key: $key,
            title: (string) ($definition['title'] ?? $key),
            summary: (string) ($definition['summary'] ?? ''),
            docSlug: is_string($definition['doc_slug'] ?? null) ? $definition['doc_slug'] : null,
            available: $available,
            unavailableReason: $unavailableReason,
            steps: $steps,
            resources: $resources,
            gaps: $gaps,
        );
    }

    /**
     * @return array{
     *     hybrid_edges: list<array<string, mixed>>,
     *     byo_servers: list<array<string, mixed>>,
     *     byo_sites: list<array<string, mixed>>,
     *     cloud_sites: list<array<string, mixed>>,
     *     custom_domains: list<array<string, mixed>>,
     * }
     */
    private function inventory(Organization $organization): array
    {
        $servers = Server::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'ip_address']);

        $sites = Site::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'status', 'server_id', 'edge_backend', 'container_backend', 'meta', 'type']);

        $hybridEdges = [];
        $byoSites = [];
        $cloudSites = [];

        foreach ($sites as $site) {
            if ($site->usesEdgeRuntime()) {
                $edgeMeta = $site->edgeMeta();
                $isHybrid = ($edgeMeta['runtime_mode'] ?? 'static') === 'hybrid';
                $origin = is_array($edgeMeta['origin'] ?? null) ? $edgeMeta['origin'] : [];
                $cloudSiteId = (string) ($origin['cloud_site_id'] ?? '');
                $originUrl = is_string($origin['url'] ?? null) ? trim($origin['url']) : '';

                if ($isHybrid || $cloudSiteId !== '' || $originUrl !== '') {
                    $hybridEdges[] = [
                        'id' => (string) $site->id,
                        'name' => (string) $site->name,
                        'server_id' => $site->server_id !== null ? (string) $site->server_id : null,
                        'runtime_mode' => (string) ($edgeMeta['runtime_mode'] ?? 'static'),
                        'cloud_site_id' => $cloudSiteId !== '' ? $cloudSiteId : null,
                        'origin_url' => $originUrl !== '' ? $originUrl : null,
                        'href' => $this->siteHref($site, 'edge-delivery'),
                    ];
                }

                continue;
            }

            if ($site->usesContainerRuntime()) {
                $cloudSites[] = [
                    'id' => (string) $site->id,
                    'name' => (string) $site->name,
                    'server_id' => $site->server_id !== null ? (string) $site->server_id : null,
                    'href' => $this->siteHref($site, 'deploy'),
                ];

                continue;
            }

            if ($site->server_id !== null && ! $site->usesFunctionsRuntime()) {
                $byoSites[] = [
                    'id' => (string) $site->id,
                    'name' => (string) $site->name,
                    'server_id' => (string) $site->server_id,
                    'href' => $this->siteHref($site, 'deploy'),
                ];
            }
        }

        $siteIds = $sites->pluck('id');
        $domains = SiteDomain::query()
            ->whereIn('site_id', $siteIds)
            ->orderBy('hostname')
            ->get(['id', 'site_id', 'hostname', 'is_primary']);

        $customDomains = [];
        foreach ($domains as $domain) {
            $site = $sites->firstWhere('id', $domain->site_id);
            $customDomains[] = [
                'domain' => (string) $domain->hostname,
                'site_name' => $site !== null ? (string) $site->name : __('Unknown site'),
                'is_primary' => (bool) $domain->is_primary,
                'href' => $site !== null ? $this->siteHref($site, $site->usesEdgeRuntime() ? 'edge-domains' : 'routing') : null,
            ];
        }

        $byoServers = [];
        foreach ($servers as $server) {
            $byoServers[] = [
                'id' => (string) $server->id,
                'name' => (string) $server->name,
                'status' => (string) $server->status,
                'ip_address' => is_string($server->ip_address) ? $server->ip_address : null,
                'href' => route('servers.overview', $server),
            ];
        }

        return [
            'hybrid_edges' => $hybridEdges,
            'byo_servers' => $byoServers,
            'byo_sites' => $byoSites,
            'cloud_sites' => $cloudSites,
            'custom_domains' => $customDomains,
        ];
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array{0: bool, 1: string|null}
     */
    private function availability(string $key, array $inventory): array
    {
        return match ($key) {
            'edge_hybrid_origin' => count($inventory['hybrid_edges']) > 0
                ? [true, null]
                : [false, __('No hybrid Edge sites with an origin link in this org.')],
            'byo_standby_server' => count($inventory['byo_sites']) > 0
                ? [true, null]
                : [false, __('No BYO VM sites in this org.')],
            'dns_cutover' => count($inventory['custom_domains']) > 0
                ? [true, null]
                : [false, __('No custom domains attached to sites yet.')],
            default => [false, __('Unknown blueprint.')],
        };
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return list<string>
     */
    private function gaps(string $key, array $inventory): array
    {
        $gaps = [];

        if ($key === 'edge_hybrid_origin') {
            if (count($inventory['cloud_sites']) === 0) {
                $gaps[] = __('No Cloud container apps — origin swap may require a manual external URL.');
            }
            foreach ($inventory['hybrid_edges'] as $edge) {
                if (($edge['cloud_site_id'] ?? null) === null && ($edge['origin_url'] ?? null) === null) {
                    $gaps[] = __(':site has hybrid Edge but no linked origin yet.', ['site' => $edge['name']]);
                }
            }
        }

        if ($key === 'byo_standby_server') {
            if (count($inventory['byo_servers']) < 2) {
                $gaps[] = __('Only one BYO server — provision a standby before you need it.');
            }
        }

        if ($key === 'dns_cutover') {
            $primaryCount = collect($inventory['custom_domains'])->where('is_primary', true)->count();
            if ($primaryCount === 0) {
                $gaps[] = __('No primary custom domains flagged — confirm apex routing before cutover.');
            }
        }

        return $gaps;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $inventory
     * @return list<array{text: string, href: string|null, link_label: string|null}>
     */
    private function buildSteps(string $key, array $definition, array $inventory): array
    {
        $rawSteps = is_array($definition['steps'] ?? null) ? $definition['steps'] : [];
        $steps = [];

        foreach ($rawSteps as $index => $text) {
            if (! is_string($text)) {
                continue;
            }

            [$href, $linkLabel] = $this->stepLink($key, $index, $inventory);

            $steps[] = [
                'text' => $text,
                'href' => $href,
                'link_label' => $linkLabel,
            ];
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array{0: string|null, 1: string|null}
     */
    private function stepLink(string $key, int $index, array $inventory): array
    {
        if ($key === 'edge_hybrid_origin') {
            $fleetLink = Feature::active('surface.fleet')
                ? [route('fleet.blast-radius'), __('Open blast radius')]
                : [null, null];

            return match ($index) {
                1 => $this->firstCloudOriginLink($inventory),
                2 => $this->firstHybridEdgeLink($inventory),
                3 => $fleetLink,
                default => [null, null],
            };
        }

        if ($key === 'byo_standby_server') {
            return match ($index) {
                0 => $this->firstByoSiteLink($inventory),
                1 => [route('servers.create'), __('Create standby server')],
                3 => $this->firstByoSiteLink($inventory),
                default => [null, null],
            };
        }

        if ($key === 'dns_cutover') {
            $fleetDomains = Feature::active('surface.fleet')
                ? [route('fleet.domains'), __('Fleet domains')]
                : [null, null];

            return match ($index) {
                0 => $fleetDomains,
                1 => [route('settings.servers'), __('Server providers')],
                default => [null, null],
            };
        }

        return [null, null];
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return list<array{kind: string, label: string, href: string|null, meta: string|null}>
     */
    private function hybridEdgeResources(array $inventory): array
    {
        $resources = [];

        foreach ($inventory['hybrid_edges'] as $edge) {
            $meta = ($edge['runtime_mode'] ?? '') === 'hybrid' ? __('Hybrid SSR') : __('Origin-linked Edge');
            if ($edge['cloud_site_id'] ?? null) {
                $meta .= ' · '.__('Cloud origin linked');
            } elseif ($edge['origin_url'] ?? null) {
                $meta .= ' · '.$edge['origin_url'];
            }

            $resources[] = [
                'kind' => 'edge',
                'label' => (string) $edge['name'],
                'href' => $edge['href'] ?? null,
                'meta' => $meta,
            ];
        }

        foreach ($inventory['cloud_sites'] as $cloud) {
            $resources[] = [
                'kind' => 'cloud',
                'label' => (string) $cloud['name'],
                'href' => $cloud['href'] ?? null,
                'meta' => __('Cloud origin candidate'),
            ];
        }

        return $resources;
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return list<array{kind: string, label: string, href: string|null, meta: string|null}>
     */
    private function byoResources(array $inventory): array
    {
        $resources = [];

        foreach ($inventory['byo_servers'] as $server) {
            $meta = is_string($server['ip_address'] ?? null) && $server['ip_address'] !== ''
                ? $server['ip_address']
                : (string) ($server['status'] ?? '');

            $resources[] = [
                'kind' => 'server',
                'label' => (string) $server['name'],
                'href' => $server['href'] ?? null,
                'meta' => $meta,
            ];
        }

        foreach ($inventory['byo_sites'] as $site) {
            $resources[] = [
                'kind' => 'site',
                'label' => (string) $site['name'],
                'href' => $site['href'] ?? null,
                'meta' => __('BYO VM site'),
            ];
        }

        return $resources;
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return list<array{kind: string, label: string, href: string|null, meta: string|null}>
     */
    private function dnsResources(array $inventory): array
    {
        $resources = [];

        foreach ($inventory['custom_domains'] as $row) {
            $resources[] = [
                'kind' => 'domain',
                'label' => (string) $row['domain'],
                'href' => $row['href'] ?? null,
                'meta' => ($row['is_primary'] ?? false) ? __('Primary · :site', ['site' => $row['site_name']]) : (string) $row['site_name'],
            ];
        }

        return $resources;
    }

    private function siteHref(Site $site, string $section): ?string
    {
        if ($site->server_id === null) {
            return null;
        }

        return route('sites.show', [
            'server' => $site->server_id,
            'site' => $site,
            'section' => $section,
        ]);
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array{0: string|null, 1: string|null}
     */
    private function firstHybridEdgeLink(array $inventory): array
    {
        $edge = $inventory['hybrid_edges'][0] ?? null;
        if (! is_array($edge)) {
            return [null, null];
        }

        return [$edge['href'] ?? null, __('Open Edge delivery')];
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array{0: string|null, 1: string|null}
     */
    private function firstCloudOriginLink(array $inventory): array
    {
        $edges = $inventory['hybrid_edges'];
        if ($edges !== []) {
            $cloudSiteId = $edges[0]['cloud_site_id'] ?? null;
            if (is_string($cloudSiteId) && $cloudSiteId !== '') {
                foreach ($inventory['cloud_sites'] as $cloud) {
                    if (($cloud['id'] ?? null) === $cloudSiteId) {
                        return [$cloud['href'] ?? null, __('Open Cloud origin')];
                    }
                }
            }
        }

        $cloud = $inventory['cloud_sites'][0] ?? null;
        if (is_array($cloud)) {
            return [$cloud['href'] ?? null, __('Open Cloud app')];
        }

        return [route('cloud.index'), __('Browse Cloud apps')];
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array{0: string|null, 1: string|null}
     */
    private function firstByoSiteLink(array $inventory): array
    {
        $site = $inventory['byo_sites'][0] ?? null;
        if (! is_array($site)) {
            return [null, null];
        }

        return [$site['href'] ?? null, __('Open site deploy')];
    }
}
