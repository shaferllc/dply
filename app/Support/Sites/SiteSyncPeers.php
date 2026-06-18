<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Resolve the set of sites that deploy together with a given site — the "Sync"
 * group: this site plus any sharing its Git repository (or the same server when
 * no repo is set). One source of truth shared by the per-site deploy sidebar
 * ({@see \App\Livewire\Sites\DeployControl}) and the fleet Sync button
 * ({@see \App\Livewire\Servers\Index}), so both always agree on the peer set.
 */
class SiteSyncPeers
{
    /**
     * @return Collection<int, Site>
     */
    public static function forSite(Site $site): Collection
    {
        $repo = trim((string) $site->git_repository_url);

        return Site::query()
            ->where('organization_id', $site->organization_id)
            ->where(function ($w) use ($repo, $site): void {
                $w->where('id', $site->id);
                if ($repo !== '') {
                    $w->orWhere('git_repository_url', $repo);
                } else {
                    $w->orWhere('server_id', $site->server_id);
                }
            })
            ->with('server')
            ->orderBy('name')
            ->get();
    }
}
