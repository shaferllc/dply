<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Models\Server;
use App\Models\Site;
use Livewire\Attributes\Locked;

/**
 * @property Site $site
 */
trait MountsEdgeWorkspaceSection
{
    #[Locked]
    public Server $server;

    #[Locked]
    public Site $site;

    public function mountEdgeWorkspaceSection(Server $server, Site $site): void
    {
        if ($site->server_id !== $server->id) {
            abort(404);
        }

        if (! $site->usesEdgeRuntime()) {
            abort(404);
        }

        $currentOrganization = request()->user()?->currentOrganization();
        if ($server->organization_id !== $currentOrganization?->id) {
            abort(404);
        }

        $this->authorize('view', $site);

        $site->setRelation('server', $server);
        if ($currentOrganization !== null && $site->organization_id === $currentOrganization->id) {
            $server->setRelation('organization', $currentOrganization);
            $site->setRelation('organization', $currentOrganization);
        }

        $this->server = $server;
        $this->site = $site;
    }
}
