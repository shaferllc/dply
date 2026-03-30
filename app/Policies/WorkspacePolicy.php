<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        $org = $user->currentOrganization();
        if (! $org || $workspace->organization_id !== $org->id) {
            return false;
        }

        return $org->hasMember($user);
    }

    public function create(User $user): bool
    {
        $org = $user->currentOrganization();
        if (! $org || $org->userIsDeployer($user)) {
            return false;
        }

        return true;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->view($user, $workspace);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        if (! $this->view($user, $workspace)) {
            return false;
        }

        return $workspace->organization->hasAdminAccess($user);
    }
}
