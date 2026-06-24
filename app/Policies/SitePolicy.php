<?php

namespace App\Policies;

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
        $workspace = app(WorkspaceRegistry::class)->for($site);
        if ($workspace !== null) {
            if (! $workspace->userCanView($user)) {
                return false;
            }

            return $workspace->userCanUpdate($user);
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
            return $this->resolveOrganization($user, $site)?->hasAdminAccess($user) ?? false;
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
            && ($this->resolveOrganization($user, $site)?->hasAdminAccess($user) ?? false);
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
