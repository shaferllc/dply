<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Collection;

/**
 * Request-scoped loader for a server's sites with every relation the
 * webserver tooling reads. Both {@see WebserverConfigDriftDetector} and
 * {@see WebserverSwitchPreflight} run on the same render of the webserver
 * workspace page — without a shared loader each one independently issued
 * its own `sites` + `site_webserver_config_profiles` + `site_certificates`
 * selects, showing up as duplicate queries in the debug bar.
 *
 * The loaded set is a superset: it carries the heavy relations the per-site
 * config builders need (domains, redirects, basic-auth, …) plus the lighter
 * profile + certificate relations the switch preflight reads. The owning
 * Server is hydrated from the instance we already hold, so consumers never
 * re-fetch the server row.
 *
 * Registered as a scoped singleton in {@see AppServiceProvider}
 * so every call site within one request shares the same memoized collection.
 */
final class ServerWebserverSitesProvider
{
    /**
     * @var array<string, Collection<int, Site>>
     */
    private array $cache = [];

    /**
     * Non-deleted sites for the server with all webserver-related relations
     * eager-loaded, memoized per server id for the lifetime of the request.
     *
     * @return Collection<int, Site>
     */
    public function for(Server $server): Collection
    {
        if (isset($this->cache[$server->id])) {
            return $this->cache[$server->id];
        }

        $sites = Site::query()
            ->where('server_id', $server->id)
            ->where('status', '!=', 'deleted')
            ->with([
                'domains',
                'domainAliases',
                'tenantDomains',
                'redirects',
                'basicAuthUsers',
                'webserverConfigProfile',
                'certificates',
            ])
            ->orderBy('name')
            ->get();

        // We already hold the Server instance — hydrate the relation instead of
        // letting the per-site config builders lazy-load it one row at a time.
        $sites->each(fn (Site $site) => $site->setRelation('server', $server));

        return $this->cache[$server->id] = $sites;
    }
}
