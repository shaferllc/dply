<?php

declare(strict_types=1);

namespace App\Services\DeployContract;

use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Resolves Cloud / BYO sites linked to an Edge parent for cross-engine checks.
 */
final class DeployContractLinkedResources
{
    /**
     * @return array{cloud: ?Site, byo: list<Site>}
     */
    /** @return array<string, mixed> */
    public function forParent(Site $parent): array
    {
        $cloud = $this->linkedCloudSite($parent);
        $byo = $this->linkedByoSites($parent)->values()->all();

        return [
            'cloud' => $cloud,
            'byo' => $byo,
        ];
    }

    public function linkedCloudSite(Site $parent): ?Site
    {
        $origin = is_array($parent->edgeMeta()['origin'] ?? null) ? $parent->edgeMeta()['origin'] : [];
        $cloudSiteId = trim((string) ($origin['cloud_site_id'] ?? ''));
        if ($cloudSiteId === '') {
            return null;
        }

        $cloud = Site::query()->find($cloudSiteId);
        if ($cloud === null || $cloud->organization_id !== $parent->organization_id) {
            return null;
        }

        return $cloud->usesContainerRuntime() ? $cloud : null;
    }

    /**
     * @return Collection<int, Site>
     */
    public function linkedByoSites(Site $parent): Collection
    {
        $parentRepo = $parent->sourceControlRepositoryUrl();
        $repo = is_string($parentRepo) ? $this->normalizeRepo($parentRepo) : '';
        if ($repo === '') {
            return collect();
        }

        return Site::query()
            ->where('organization_id', $parent->organization_id)
            ->where('id', '!=', $parent->id)
            ->get()
            ->filter(function (Site $site) use ($repo): bool {
                if ($site->usesEdgeRuntime() || $site->usesContainerRuntime()) {
                    return false;
                }

                $siteRepo = $site->sourceControlRepositoryUrl();

                return is_string($siteRepo) && $this->normalizeRepo($siteRepo) === $repo;
            })
            ->values();
    }

    private function normalizeRepo(string $url): string
    {
        $url = strtolower(trim($url));
        $url = preg_replace('/\.git$/', '', $url) ?? $url;
        $url = preg_replace('#^https?://github\.com/#', 'github.com/', $url) ?? $url;

        return $url;
    }
}
