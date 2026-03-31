<?php

namespace App\Policies;

use App\Models\BackupConfiguration;
use App\Models\User;

class BackupConfigurationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, BackupConfiguration $backupConfiguration): bool
    {
        return $user->id === $backupConfiguration->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, BackupConfiguration $backupConfiguration): bool
    {
        return $user->id === $backupConfiguration->user_id;
    }

    public function delete(User $user, BackupConfiguration $backupConfiguration): bool
    {
        return $user->id === $backupConfiguration->user_id;
    }
}
