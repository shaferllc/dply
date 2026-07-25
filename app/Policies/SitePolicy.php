<?php

namespace App\Policies;

use App\Models\EdgeSiteMember;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Workspaces\WorkspaceRegistry;

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

        // Edge per-site members elevate — never restrict — org access.
        return $this->edgeMemberRank($user, $site) >= EdgeSiteMember::rankFor(EdgeSiteMember::ROLE_VIEWER);
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
        $workspace = app(WorkspaceRegistry::class)->for($site);
        if ($workspace !== null) {
            if (! $workspace->userCanView($user)) {
                return false;
            }

            if ($workspace->userCanUpdate($user)) {
                return true;
            }

            return $this->edgeMemberRank($user, $site) >= EdgeSiteMember::rankFor(EdgeSiteMember::ROLE_DEPLOYER);
        }

        $server = $this->resolveServer($site);

        if ($server !== null && $user->can('update', $server)) {
            return true;
        }

        return $this->edgeMemberRank($user, $site) >= EdgeSiteMember::rankFor(EdgeSiteMember::ROLE_DEPLOYER);
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
            return $this->resolveOrganization($user, $site)?->hasAdminAccess($user) ?? false;
        }

        return $site->user_id === $user->id;
    }

    /**
     * Manage per-site Edge members (Wave E P12). Org admins always can;
     * Edge site admins elevate to the same gate.
     */
    public function manageMembers(User $user, Site $site): bool
    {
        if ($site->organization_id === null) {
            return false;
        }

        if ($this->resolveOrganization($user, $site)?->hasAdminAccess($user) ?? false) {
            return true;
        }

        return $this->edgeMemberRank($user, $site) >= EdgeSiteMember::rankFor(EdgeSiteMember::ROLE_ADMIN);
    }

    private function edgeMemberRank(User $user, Site $site): int
    {
        if (! $site->usesEdgeRuntime()) {
            return 0;
        }

        $role = $site->edgeSiteMembers()
            ->where('user_id', $user->id)
            ->value('role');

        return is_string($role) ? EdgeSiteMember::rankFor($role) : 0;
    }

    /**
     * Resolve a site's organization for an admin check, preferring the user's
     * already-memoized {@see User::currentOrganization()} when it's the same org
     * (the common case) so authorizing several site instances in one render
     * doesn't reload the same `organizations` row each time. Falls back to the
     * relation for the rare cross-org check.
     */
    private function resolveOrganization(User $user, Site $site): ?Organization
    {
        if ($site->organization_id === null) {
            return null;
        }

        $current = $user->currentOrganization();
        if ($current !== null && (string) $current->id === (string) $site->organization_id) {
            return $current;
        }

        return $site->organization;
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
