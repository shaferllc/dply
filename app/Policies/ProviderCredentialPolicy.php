<?php

namespace App\Policies;

use App\Models\ProviderCredential;
use App\Models\User;

class ProviderCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProviderCredential $providerCredential): bool
    {
        if ($providerCredential->user_id === $user->id) {
            return true;
        }
        if ($providerCredential->organization_id && $providerCredential->organization->hasMember($user)) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->currentOrganization() !== null;
    }

    public function delete(User $user, ProviderCredential $providerCredential): bool
    {
        return $this->view($user, $providerCredential);
    }
}
