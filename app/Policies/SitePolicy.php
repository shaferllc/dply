<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentOrganization() !== null;
    }

    public function view(User $user, Site $site): bool
    {
        $server = $this->resolveServer($site);

        return $server !== null && $user->can('view', $server);
    }

    public function create(User $user): bool
    {
        $org = $user->currentOrganization();

        if ($org === null) {
            return false;
        }

        if ($org->userIsDeployer($user)) {
            return false;
        }

        return $org->canCreateSite();
    }

    public function update(User $user, Site $site): bool
    {
        if ($site->workspace_id && $site->workspace) {
            if (! $site->workspace->userCanView($user)) {
                return false;
            }

            return $site->workspace->userCanUpdate($user);
        }

        $server = $this->resolveServer($site);

        return $server !== null && $user->can('update', $server);
    }

    public function clone(User $user, Site $site): bool
    {
        return $this->update($user, $site) && $this->create($user);
    }

    public function delete(User $user, Site $site): bool
    {
        $server = $this->resolveServer($site);
        if ($server === null || ! $user->can('view', $server)) {
            return false;
        }

        if ($site->organization_id !== null) {
            return $site->organization->hasAdminAccess($user);
        }

        return $site->user_id === $user->id;
    }

    /**
     * Manage per-site team members on an Edge site. Org admin only.
     * The per-site members feature (edge_site_members) was deprecated
     * along with the Members workspace tab — kept as a method for
     * backwards-compat with any lingering Gate checks.
     */
    public function manageMembers(User $user, Site $site): bool
    {
        $server = $this->resolveServer($site);

        return $server !== null
            && $user->can('view', $server)
            && $site->organization_id !== null
            && $site->organization->hasAdminAccess($user);
    }

    private function resolveServer(Site $site): ?Server
    {
        if ($site->relationLoaded('server')) {
            return $site->server;
        }

        $routeServer = request()->route('server');
        if ($routeServer instanceof Server && (string) $routeServer->getKey() === (string) $site->server_id) {
            $site->setRelation('server', $routeServer);

            return $routeServer;
        }

        $site->loadMissing('server');

        return $site->server;
    }
}
