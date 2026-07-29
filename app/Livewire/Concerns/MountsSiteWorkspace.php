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

        $user = request()->user();
        $currentOrganization = $user?->currentOrganization();
        if ($server->organization_id !== $currentOrganization?->id) {
            // The active org doesn't match the server's — but a member
            // deep-linking into another of their orgs' resources should switch
            // context, not hit a dead 404. This also self-heals a session whose
            // current org went stale (e.g. after an org-id reseed, where the
            // session points at an org id that no longer resolves). Only a
            // genuine non-member is denied; the authorize('view') below still
            // enforces the real access policy.
            $target = $user?->organizations()->whereKey($server->organization_id)->first();
            if ($target === null) {
                abort(404);
            }

            session(['current_organization_id' => $target->id]);
            $user->flushCurrentOrganizationCache();
            $user->rememberCurrentOrganization($target);
            $currentOrganization = $target;
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
