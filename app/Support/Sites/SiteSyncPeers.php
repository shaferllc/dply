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
        $canonical = self::canonicalRepo((string) $site->git_repository_url);

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
                    $tail = self::repoTail($canonical);
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
                || self::canonicalRepo((string) $s->git_repository_url) === $canonical)
            ->values();
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
    private static function repoTail(string $canonical): string
    {
        $parts = array_values(array_filter(explode('/', $canonical), static fn ($p): bool => $p !== ''));
        $count = count($parts);
        if ($count < 2) {
            return '';
        }

        return $parts[$count - 2].'/'.$parts[$count - 1];
    }
}
