<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Cache\Models\ManagedCache;

/**
 * A cache is org-owned. There are no global scopes in this codebase, so this
 * policy is where tenancy is actually enforced for operator-facing access — the
 * HTTP data plane authenticates by credential grant instead and never consults
 * this.
 *
 * Mirrors {@see QueueNamespacePolicy}, with one deliberate difference: `create`
 * is not restricted from deployers the way a queue namespace's is. That
 * restriction exists because a namespace is billable infrastructure; a shared
 * cache is free (docs/adr/dply-cache.md, decision 7), so the reasoning does not
 * transfer. Deleting and credential handling stay admin-only regardless — those
 * are destructive and security-sensitive, not billable.
 */
class ManagedCachePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ManagedCache $cache): bool
    {
        $org = $user->currentOrganization();

        return $org && $cache->organization_id === $org->id && $org->hasMember($user);
    }

    public function create(User $user): bool
    {
        return $user->currentOrganization() !== null;
    }

    public function update(User $user, ManagedCache $cache): bool
    {
        return $this->view($user, $cache) && ! $cache->organization->userIsDeployer($user);
    }

    /**
     * Flushing is destructive in a way the rest of `update` is not: it drops
     * every key in one click, including whatever a running app is mid-way
     * through relying on. Grouped with the admin operations for that reason.
     */
    public function flush(User $user, ManagedCache $cache): bool
    {
        return $this->view($user, $cache) && $cache->organization->hasAdminAccess($user);
    }

    /**
     * A leaked cache credential lets someone read and poison another app's
     * cache — including its locks. Admin access, same as the queue's.
     */
    public function manageCredentials(User $user, ManagedCache $cache): bool
    {
        return $this->view($user, $cache) && $cache->organization->hasAdminAccess($user);
    }

    public function delete(User $user, ManagedCache $cache): bool
    {
        return $this->view($user, $cache) && $cache->organization->hasAdminAccess($user);
    }
}
