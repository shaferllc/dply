<?php

declare(strict_types=1);

namespace App\Modules\Imports\Policies;

use App\Models\ImportServerMigration;
use App\Models\User;

/**
 * Authorisation for the migration surface (Q18: owners + admins only by
 * default, not customisable in v1). All actions require admin-or-owner
 * role in the migration's organization; viewing is also gated on the same
 * since migration progress pages can surface secrets in step logs.
 */
class ImportServerMigrationPolicy
{
    /**
     * Generic "can start a migration in the current org" check — used at
     * the wizard's kickoff path before a migration row exists.
     */
    public function start(User $user): bool
    {
        $org = $user->currentOrganization();

        return $org !== null && $org->hasAdminAccess($user);
    }

    public function viewAny(User $user): bool
    {
        return $this->start($user);
    }

    public function view(User $user, ImportServerMigration $migration): bool
    {
        $org = $migration->organization;

        return $org !== null && $org->hasAdminAccess($user);
    }

    public function operate(User $user, ImportServerMigration $migration): bool
    {
        return $this->view($user, $migration);
    }
}
