<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Server $server): bool
    {
        if ($server->user_id === $user->id) {
            return true;
        }
        if ($server->organization_id && $server->organization->hasMember($user)) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->currentOrganization() !== null;
    }

    public function update(User $user, Server $server): bool
    {
        return $this->view($user, $server);
    }

    public function delete(User $user, Server $server): bool
    {
        return $this->view($user, $server);
    }
}
