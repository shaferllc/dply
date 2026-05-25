<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Server;
use App\Models\Site;

trait MountsSiteWorkspace
{
    protected function mountSiteWorkspace(Server $server, Site $site): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }

        $currentOrganization = request()->user()?->currentOrganization();
        if ($server->organization_id !== $currentOrganization?->id) {
            abort(404);
        }

        $site->setRelation('server', $server);
        if ($currentOrganization !== null && $site->organization_id === $currentOrganization->id) {
            $server->setRelation('organization', $currentOrganization);
            $site->setRelation('organization', $currentOrganization);
        }

        $this->authorize('view', $site);
        $this->server = $server;
        $this->site = $site;
    }
}
