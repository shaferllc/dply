<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Organization;
use App\Models\Site;
use App\Models\SitePreviewDomain;
use App\Support\Preview\UnifiedPreviewHostname;

/**
 * Org-wide inventory of managed preview hostnames across BYO + Edge.
 */
final class UnifiedPreviewCatalog
{
    public function __construct(
        private readonly UnifiedPreviewHostname $hostnames,
    ) {}

    /**
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
                apex: $domain->zone ? $domain->zone : ($this->hostnames->apexFromHostname($hostname) ?? ''),
                parentName: null,
            );
        }

        foreach ($sites as $site) {
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
}
