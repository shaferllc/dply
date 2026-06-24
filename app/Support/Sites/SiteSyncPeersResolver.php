<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * Request-scoped memo for the {@see SiteSyncPeers} group of a site.
 *
 * A single Deploy-tab render resolves the same site's peer set up to three
 * times — the deploy sidebar's `syncPeers` computed, plus the sidebar's and the
 * Deploy page's `status()` snapshots (each reaching {@see SiteDeployCoordinator::selectedPeerIds()}).
 * Without a shared instance, each fires the sites + servers SELECT pair afresh.
 * Bound `scoped` (request-singleton, Octane/queue-safe) so the memo never
 * outlives the request or job that built it.
 */
final class SiteSyncPeersResolver
{
    /** @var array<string, Collection<int, Site>> */
    private array $cache = [];

    /**
     * @return Collection<int, Site>
     */
    public function forSite(Site $site): Collection
    {
        $key = (string) $site->id;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->resolve($site);
    }

    public function forget(Site $site): void
    {
        unset($this->cache[(string) $site->id]);
    }

    /**
     * @return Collection<int, Site>
     */
    private function resolve(Site $site): Collection
    {
        $canonical = SiteSyncPeers::canonicalRepo((string) $site->git_repository_url);

        return Site::query()
            ->where('organization_id', $site->organization_id)
            ->where(function ($w) use ($canonical, $site): void {
                $w->where('id', $site->id);
                if ($canonical !== '') {
                    // Match repo peers by CANONICAL identity (host/owner/repo), so
                    // the same repository registered under different URL shapes —
                    // git@github.com:o/r.git vs https://github.com/o/r — still syncs
                    // together. Two sites of one app split across servers (e.g. an
                    // app box + a worker box) were silently NOT grouped whenever
                    // their URLs differed by protocol or a trailing .git, which hid
                    // the "Sync / deploy both" button. The DB narrows on the
                    // owner/repo tail (case-insensitive); canonicalRepo() in the
                    // filter below is the exact gate, so the LIKE only ever
                    // over-includes (never drops a true peer).
                    $tail = SiteSyncPeers::repoTail($canonical);
                    if ($tail !== '') {
                        $w->orWhereRaw('lower(git_repository_url) like ?', ['%'.$tail.'%']);
                    }
                } else {
                    // No repo set → fall back to server-mates (legacy behaviour).
                    $w->orWhere('server_id', $site->server_id);
                }
            })
            ->with('server')
            ->orderBy('name')
            ->get()
            ->filter(fn (Site $s): bool => $s->id === $site->id
                || $canonical === ''
                || SiteSyncPeers::canonicalRepo((string) $s->git_repository_url) === $canonical)
            ->values();
    }
}
