<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\GitProviderToken;
use App\Models\User;

/**
 * Two kinds of row, two rules.
 *
 * A personal token is that person's GitHub access outside dply as much as
 * inside it, so no org admin gets to see or touch it — deliberately unlike
 * ProviderCredential, where every row is org property.
 *
 * An org-owned token is the machine-user credential: admins and owners manage
 * it, every member may use it (the resolver checks membership, not this
 * policy), and nobody sees its value after it is stored.
 *
 * See docs/adr/org-owned-git-credentials.md, decision 5.
 */
class GitProviderTokenPolicy
{
    public function view(User $user, GitProviderToken $token): bool
    {
        return $this->manages($user, $token);
    }

    public function update(User $user, GitProviderToken $token): bool
    {
        return $this->manages($user, $token);
    }

    public function delete(User $user, GitProviderToken $token): bool
    {
        return $this->manages($user, $token);
    }

    private function manages(User $user, GitProviderToken $token): bool
    {
        if ($token->isOrganizationOwned()) {
            $organization = $token->organization;

            return $organization !== null && $organization->hasAdminAccess($user);
        }

        return $token->user_id !== null && (string) $token->user_id === (string) $user->getKey();
    }
}
