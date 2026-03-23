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
        return $user->currentOrganization() !== null;
    }

    public function update(User $user, Site $site): bool
    {
        return $user->can('update', $site->server);
    }

    public function delete(User $user, Site $site): bool
    {
        return $user->can('delete', $site->server);
    }
}
