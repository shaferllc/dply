<?php

namespace App\Policies;

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
        return $user->can('view', $site->server);
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
        return $user->can('update', $site->server);
    }

    public function delete(User $user, Site $site): bool
    {
        if (! $user->can('view', $site->server)) {
            return false;
        }

        if ($site->organization_id !== null) {
            return $site->organization->hasAdminAccess($user);
        }

        return $site->user_id === $user->id;
    }
}
