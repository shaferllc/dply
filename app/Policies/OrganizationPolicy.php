<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $organization->hasMember($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $organization->hasAdminAccess($user);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $organization->users()->where('user_id', $user->id)->wherePivot('role', 'owner')->exists();
    }
}
