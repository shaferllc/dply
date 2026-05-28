<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Organization;
use App\Models\Site;
use App\Models\SitePreviewDomain;
use App\Support\Preview\UnifiedPreviewHostname;
use Illuminate\Support\Collection;

/**
 * Org-wide inventory of managed preview hostnames across BYO + Edge.
 */
final class UnifiedPreviewCatalog
{
    public function __construct(
        private readonly UnifiedPreviewHostname $hostnames,
    ) {}

    /**
     * @return list<array{
     *     hostname: string,
     *     site_id: string,
     *     site_name: string,
     *     product: string,
     *     kind: string,
     *     apex: string,
     *     href: string|null,
     *     parent_name: string|null,
     * }>
     */
    public function forOrganization(Organization $organization): array
    {
        $rows = [];

        $sites = Site::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'server_id', 'edge_backend', 'container_backend', 'meta', 'type']);

        $previewDomains = SitePreviewDomain::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->orderBy('hostname')
            ->get(['site_id', 'hostname', 'is_primary', 'zone']);

        $sitesById = $sites->keyBy('id');

        foreach ($previewDomains as $domain) {
            $site = $sitesById->get($domain->site_id);
            if ($site === null) {
                continue;
            }

            $hostname = strtolower((string) $domain->hostname);
            $rows[] = $this->row(
                hostname: $hostname,
                site: $site,
                product: 'byo',
                kind: ($domain->is_primary ?? false) ? 'primary' : 'preview',
                apex: is_string($domain->zone) && $domain->zone !== '' ? $domain->zone : ($this->hostnames->apexFromHostname($hostname) ?? ''),
                parentName: null,
            );
        }

        foreach ($sites as $site) {
            if ($site->usesEdgeRuntime()) {
                $hostname = strtolower(trim($site->edgeHostname()));
                if ($hostname !== '') {
                    $rows[] = $this->row(
                        hostname: $hostname,
                        site: $site,
                        product: 'edge',
                        kind: $site->isEdgePreview() ? 'branch_preview' : 'primary',
                        apex: $this->hostnames->apexFromHostname($hostname) ?? '',
                        parentName: $this->edgePreviewParentName($site, $sitesById),
                    );
                }

                continue;
            }

            if ($site->usesFunctionsRuntime()) {
                continue;
            }

            if ($site->usesContainerRuntime()) {
                continue;
            }

            $testing = strtolower(trim($site->testingHostname()));
            if ($testing === '') {
                continue;
            }

            if (collect($rows)->contains(fn (array $row): bool => $row['hostname'] === $testing)) {
                continue;
            }

            $rows[] = $this->row(
                hostname: $testing,
                site: $site,
                product: 'byo',
                kind: 'primary',
                apex: $this->hostnames->apexFromHostname($testing) ?? '',
                parentName: null,
            );
        }

        usort($rows, static fn (array $a, array $b): int => [$a['apex'], $a['hostname']] <=> [$b['apex'], $b['hostname']]);

        return $rows;
    }

    /**
     * @return array{hostname: string, site_id: string, site_name: string, product: string, kind: string, apex: string, href: string|null, parent_name: string|null}
     */
    private function row(
        string $hostname,
        Site $site,
        string $product,
        string $kind,
        string $apex,
        ?string $parentName,
    ): array {
        return [
            'hostname' => $hostname,
            'site_id' => (string) $site->id,
            'site_name' => (string) $site->name,
            'product' => $product,
            'kind' => $kind,
            'apex' => $apex,
            'href' => $site->server_id
                ? route('sites.show', ['server' => $site->server_id, 'site' => $site])
                : null,
            'parent_name' => $parentName,
        ];
    }

    /**
     * @param  Collection<string, Site>  $sitesById
     */
    private function edgePreviewParentName(Site $site, $sitesById): ?string
    {
        if (! $site->isEdgePreview()) {
            return null;
        }

        $parentId = $site->edgeMeta()['preview_parent_site_id'] ?? null;
        if (! is_string($parentId) || $parentId === '') {
            return null;
        }

        $parent = $sitesById->get($parentId);

        return $parent !== null ? (string) $parent->name : null;
    }
}
