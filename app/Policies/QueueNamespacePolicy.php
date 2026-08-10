<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Queue\Models\QueueNamespace;

/**
 * A queue namespace is org-owned. There are no global scopes in this
 * codebase, so this policy is where tenancy is actually enforced for
 * operator-facing access — the HTTP data plane authenticates by credential
 * instead and never consults this.
 */
class QueueNamespacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, QueueNamespace $namespace): bool
    {
        $org = $user->currentOrganization();

        return $org && $namespace->organization_id === $org->id && $org->hasMember($user);
    }

    public function create(User $user): bool
    {
        $org = $user->currentOrganization();

        // A deployer can push jobs from a deployed app but should not be able
        // to mint new billable infrastructure, matching StatusPagePolicy.
        return $org && ! $org->userIsDeployer($user);
    }

    public function update(User $user, QueueNamespace $namespace): bool
    {
        return $this->view($user, $namespace) && ! $namespace->organization->userIsDeployer($user);
    }

    /**
     * Minting or revoking a credential is the security-sensitive operation on
     * this resource — a leaked credential can drain a production queue — so it
     * needs admin access rather than plain membership.
     */
    public function manageCredentials(User $user, QueueNamespace $namespace): bool
    {
        return $this->view($user, $namespace) && $namespace->organization->hasAdminAccess($user);
    }

    public function delete(User $user, QueueNamespace $namespace): bool
    {
        return $this->view($user, $namespace) && $namespace->organization->hasAdminAccess($user);
    }
}
