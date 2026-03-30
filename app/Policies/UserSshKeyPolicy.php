<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserSshKey;

class UserSshKeyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserSshKey $userSshKey): bool
    {
        return $user->id === $userSshKey->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, UserSshKey $userSshKey): bool
    {
        return $user->id === $userSshKey->user_id;
    }

    public function delete(User $user, UserSshKey $userSshKey): bool
    {
        return $user->id === $userSshKey->user_id;
    }
}
