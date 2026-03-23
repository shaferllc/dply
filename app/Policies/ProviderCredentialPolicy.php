<?php

namespace App\Policies;

use App\Models\ProviderCredential;
use App\Models\User;

class ProviderCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        $org = $user->currentOrganization();
        if ($org && $org->userIsDeployer($user)) {
            return false;
        }

        return true;
    }

    public function view(User $user, ProviderCredential $providerCredential): bool
    {
        if ($providerCredential->user_id === $user->id) {
            return true;
        }
        if ($providerCredential->organization_id && $providerCredential->organization->hasMember($user)) {
            return ! $providerCredential->organization->userIsDeployer($user);
        }

        return false;
    }

    public function create(User $user): bool
    {
        $org = $user->currentOrganization();
        if (! $org) {
            return false;
        }
        if ($org->userIsDeployer($user)) {
            return false;
        }

        return true;
    }

    public function delete(User $user, ProviderCredential $providerCredential): bool
    {
        return $this->view($user, $providerCredential);
    }
}
