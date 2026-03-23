<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

class TeamPolicy
{
    public function viewAny(User $user, ?\App\Models\Organization $organization = null): bool
    {
        return $organization ? $organization->hasMember($user) : true;
    }

    public function view(User $user, Team $team): bool
    {
        return $team->organization->hasMember($user);
    }

    public function create(User $user, \App\Models\Organization $organization): bool
    {
        return $organization->hasAdminAccess($user);
    }

    public function update(User $user, Team $team): bool
    {
        return $team->organization->hasAdminAccess($user);
    }

    public function delete(User $user, Team $team): bool
    {
        return $team->organization->hasAdminAccess($user);
    }
}
