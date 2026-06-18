<?php

namespace App\Modules\Backups\Policies;

use App\Models\BackupConfiguration;
use App\Models\User;

/**
 * Backup destinations are organization-scoped: any member of the org can view,
 * create, edit, or delete them. The destination row carries credentials the
 * whole team uses to push backups, so per-creator gating would block teammates
 * from rotating keys on a destination someone else added.
 */
class BackupConfigurationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentOrganization() !== null;
    }

    public function view(User $user, BackupConfiguration $backupConfiguration): bool
    {
        return $this->belongsToOrg($user, $backupConfiguration);
    }

    public function create(User $user): bool
    {
        return $user->currentOrganization() !== null;
    }

    public function update(User $user, BackupConfiguration $backupConfiguration): bool
    {
        return $this->belongsToOrg($user, $backupConfiguration);
    }

    public function delete(User $user, BackupConfiguration $backupConfiguration): bool
    {
        return $this->belongsToOrg($user, $backupConfiguration);
    }

    private function belongsToOrg(User $user, BackupConfiguration $backupConfiguration): bool
    {
        return $user->organizations()->whereKey($backupConfiguration->organization_id)->exists();
    }
}
