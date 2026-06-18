<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\OrganizationSshKey;
use App\Models\TeamSshKey;
use App\Services\Servers\OrganizationTeamSshKeyServerDeployer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait DeploysSharedKeys
{


    public function deployOrganizationKey(OrganizationTeamSshKeyServerDeployer $deployer): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfSyncBusy()) {
            return;
        }

        $this->validate([
            'deploy_org_key_id' => ['required', 'string', 'exists:organization_ssh_keys,id'],
            'deploy_target_linux_user' => ['required', 'string', 'max:64', Rule::in($this->system_users)],
        ]);

        $key = OrganizationSshKey::query()->whereKey($this->deploy_org_key_id)->firstOrFail();
        $selected = trim($this->deploy_target_linux_user);
        $stored = $selected === (string) $this->server->ssh_user ? '' : $selected;

        $result = $deployer->deployOrganizationKey(Auth::user(), $key, $this->server->fresh(), $stored);
        if ($result['ok']) {
            $this->toastSuccess($result['message']);
        } else {
            $this->toastError($result['message']);
        }
    }

    public function deployTeamKey(OrganizationTeamSshKeyServerDeployer $deployer): void
    {
        $this->authorize('update', $this->server);

        if ($this->rejectIfSyncBusy()) {
            return;
        }

        $this->validate([
            'deploy_team_key_id' => ['required', 'string', 'exists:team_ssh_keys,id'],
            'deploy_target_linux_user' => ['required', 'string', 'max:64', Rule::in($this->system_users)],
        ]);

        $key = TeamSshKey::query()->whereKey($this->deploy_team_key_id)->firstOrFail();
        $selected = trim($this->deploy_target_linux_user);
        $stored = $selected === (string) $this->server->ssh_user ? '' : $selected;

        $result = $deployer->deployTeamKey(Auth::user(), $key, $this->server->fresh(), $stored);
        if ($result['ok']) {
            $this->toastSuccess($result['message']);
        } else {
            $this->toastError($result['message']);
        }
    }
}
