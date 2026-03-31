<?php

namespace App\Policies;

use App\Models\Script;
use App\Models\User;

class ScriptPolicy
{
    public function viewAny(User $user): bool
    {
        $org = $user->currentOrganization();
        if ($org && $org->userIsDeployer($user)) {
            return false;
        }

        return $org !== null;
    }

    public function view(User $user, Script $script): bool
    {
        if (! $this->inCurrentOrganization($user, $script)) {
            return false;
        }

        $org = $user->currentOrganization();
        if ($org && $org->userIsDeployer($user)) {
            return false;
        }

        return true;
    }

    public function create(User $user): bool
    {
        $org = $user->currentOrganization();

        return $org !== null && ! $org->userIsDeployer($user);
    }

    public function update(User $user, Script $script): bool
    {
        return $this->inCurrentOrganization($user, $script) && $this->create($user);
    }

    public function delete(User $user, Script $script): bool
    {
        return $this->update($user, $script);
    }

    public function runOnServers(User $user, Script $script): bool
    {
        return $this->view($user, $script);
    }

    protected function inCurrentOrganization(User $user, Script $script): bool
    {
        $org = $user->currentOrganization();

        return $org !== null && (string) $script->organization_id === (string) $org->id;
    }
}
