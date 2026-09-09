<?php

namespace App\Policies;

use App\Models\CloudDatabase;
use App\Models\User;

/**
 * A managed database is org-owned. There are no global scopes in this
 * codebase, so this policy is where tenancy and role are actually enforced
 * for the operator-facing surface.
 *
 * Deliberately shaped like {@see QueueNamespacePolicy}: both are billable,
 * org-scoped infrastructure reached from a session, and an operator should
 * not have to learn two different rules for "who may destroy this".
 */
class CloudDatabasePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CloudDatabase $database): bool
    {
        $org = $user->currentOrganization();

        return $org && $database->organization_id === $org->id && $org->hasMember($user);
    }

    public function create(User $user): bool
    {
        $org = $user->currentOrganization();

        // A deployer ships code against an existing cluster but should not be
        // able to mint new billable infrastructure, matching QueueNamespacePolicy.
        return $org && ! $org->userIsDeployer($user);
    }

    /**
     * Day-two operations on a live cluster — users, resize, trusted sources,
     * restore, detach. A deployer is read-only here.
     */
    public function update(User $user, CloudDatabase $database): bool
    {
        return $this->view($user, $database) && ! $database->organization->userIsDeployer($user);
    }

    /**
     * Tear-down deletes the provider cluster and every byte in it, detaches
     * attached apps, and drops the control-plane row. It is unrecoverable, so
     * it needs admin access rather than plain membership — the same bar
     * {@see QueueNamespacePolicy::delete()} sets for destroying a namespace.
     */
    public function delete(User $user, CloudDatabase $database): bool
    {
        return $this->view($user, $database) && $database->organization->hasAdminAccess($user);
    }
}
