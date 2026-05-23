<?php

declare(strict_types=1);

namespace App\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Estimates per-site R2 artifact storage from deployment metadata.
 */
final class EdgeSiteR2StorageEstimator
{
    /**
     * @param  Collection<int, Site>  $sites
     * @return array<string, int> site_id => bytes
     */
    public function storageBytesBySite(Collection $sites): array
    {
        if ($sites->isEmpty()) {
            return [];
        }

        $siteIds = $sites->pluck('id')->all();

        $deployments = EdgeDeployment::query()
            ->whereIn('site_id', $siteIds)
            ->whereNull('pruned_at')
            ->whereNotNull('storage_prefix')
            ->get(['site_id', 'meta']);

        /** @var array<string, int> $totals */
        $totals = [];

        foreach ($deployments as $deployment) {
            $meta = is_array($deployment->meta) ? $deployment->meta : [];
            $bytes = (int) ($meta['artifact_bytes'] ?? 0);
            if ($bytes <= 0) {
                continue;
            }

            $totals[$deployment->site_id] = ($totals[$deployment->site_id] ?? 0) + $bytes;
        }

        return $totals;
    }
}
