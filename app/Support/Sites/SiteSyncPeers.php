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
     * Resolved through the request-scoped {@see SiteSyncPeersResolver} so the
     * sites + servers query pair runs once per request, no matter how many
     * surfaces ask for the same site's peer set in a single render.
     *
     * @return Collection<int, Site>
     */
    public static function forSite(Site $site): Collection
    {
        return app(SiteSyncPeersResolver::class)->forSite($site);
    }

    /**
     * Canonical, comparable identity for a Git remote: lower-cased
     * host/owner/repo with the protocol, any userinfo, and a trailing .git or
     * slash stripped. Both git@host:owner/repo.git and https://host/owner/repo
     * collapse to "host/owner/repo" so URL shape never fragments a sync group.
     *
     * Public so "Sync N" peer-count surfaces (the fleet servers index, the
     * per-server site directory) group repos by the SAME identity this matcher
     * uses — otherwise a badge count could disagree with what actually deploys.
     */
    public static function canonicalRepo(string $url): string
    {
        $u = strtolower(trim($url));
        if ($u === '') {
            return '';
        }

        $u = preg_replace('~^git@([^:]+):~', '$1/', $u);   // scp-style → host/path
        $u = preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $u); // strip scheme
        $u = preg_replace('~^[^@/]+@~', '', $u);            // strip userinfo
        $u = preg_replace('~\.git$~', '', (string) $u);     // strip .git
        $u = rtrim((string) $u, '/');

        return (string) $u;
    }

    /** Last two path segments (owner/repo) of a canonical repo, for a DB narrow. */
    public static function repoTail(string $canonical): string
    {
        $parts = array_values(array_filter(explode('/', $canonical), static fn ($p): bool => $p !== ''));
        $count = count($parts);
        if ($count < 2) {
            return '';
        }

        return $parts[$count - 2].'/'.$parts[$count - 1];
    }
}
