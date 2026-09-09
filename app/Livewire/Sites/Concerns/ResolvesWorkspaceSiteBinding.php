<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\Server;
use App\Models\Site;

/**
 * Resolve the site/server in scope for breadcrumb-mounted controls.
 *
 * Lazy parents (Deployments, Repository) remount nested Livewire children on
 * a subsequent request whose route is the Livewire endpoint — not the page
 * route — so `request()->route('site')` is empty. An explicit `:site` prop
 * from the parent must win; the route is only a fallback for first paint.
 *
 * @property-read Site|null $site
 * @property-read Server|null $server
 */
trait ResolvesWorkspaceSiteBinding
{
    protected function resolveBoundSite(?Site $site): ?Site
    {
        if ($this->site instanceof Site) {
            return $this->site;
        }

        if ($site instanceof Site) {
            return $site;
        }

        $routeSite = request()->route('site');

        return $routeSite instanceof Site ? $routeSite : null;
    }

    protected function resolveBoundServer(?Server $server): ?Server
    {
        if ($this->server instanceof Server) {
            return $this->server;
        }

        if ($server instanceof Server) {
            return $server;
        }

        $routeServer = request()->route('server');

        return $routeServer instanceof Server ? $routeServer : $this->site?->server;
    }
}
