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

        if ($server !== null && $user->can('view', $server)) {
            return true;
        }

        // Edge per-site members used to elevate — never restrict — org access
        // here. Edge is removed (remove-cloud-edge-serverless), so server
        // access is the only grant left.
        return false;
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

        // Machine-site ceiling: every authorize('create', Site::class) caller
        // is a VM / container create path. Edge, Cloud and function creates
        // have their own ceilings and gate at their own create components.
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

            return false;
        }

        $server = $this->resolveServer($site);

        if ($server !== null && $user->can('update', $server)) {
            return true;
        }

        return false;
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

        return false;
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
