<?php

namespace App\Policies;

use App\Models\EdgeSiteMember;
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
        if ($server !== null && $user->can('view', $server)) {
            return true;
        }

        return $this->edgeMemberRank($site, $user) >= EdgeSiteMember::rankFor(EdgeSiteMember::ROLE_VIEWER);
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
        if ($server !== null && $user->can('update', $server)) {
            return true;
        }

        return $this->edgeMemberRank($site, $user) >= EdgeSiteMember::rankFor(EdgeSiteMember::ROLE_DEPLOYER);
    }

    public function clone(User $user, Site $site): bool
    {
        return $this->update($user, $site) && $this->create($user);
    }

    public function delete(User $user, Site $site): bool
    {
        $server = $this->resolveServer($site);
        if ($server !== null && $user->can('view', $server)) {
            if ($site->organization_id !== null) {
                if ($site->organization->hasAdminAccess($user)) {
                    return true;
                }
            } elseif ($site->user_id === $user->id) {
                return true;
            }
        }

        return $this->edgeMemberRank($site, $user) >= EdgeSiteMember::rankFor(EdgeSiteMember::ROLE_ADMIN);
    }

    /**
     * Manage per-site team members on an Edge site. Org admin or
     * site-level admin grant. Used by the Members workspace tab.
     */
    public function manageMembers(User $user, Site $site): bool
    {
        $server = $this->resolveServer($site);
        if ($server !== null
            && $user->can('view', $server)
            && $site->organization_id !== null
            && $site->organization->hasAdminAccess($user)) {
            return true;
        }

        return $this->edgeMemberRank($site, $user) >= EdgeSiteMember::rankFor(EdgeSiteMember::ROLE_ADMIN);
    }

    private function edgeMemberRank(Site $site, User $user): int
    {
        $role = $site->edgeMemberRoleFor($user);

        return $role === null ? 0 : EdgeSiteMember::rankFor($role);
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
