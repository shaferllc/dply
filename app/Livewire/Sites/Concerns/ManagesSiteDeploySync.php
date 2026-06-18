<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\Site;
use App\Services\Sites\SiteDeploySyncGroupManager;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteDeploySync
{
    public string $sync_group_name_input = '';

    public string $sync_group_add_site_id = '';

    public string $sync_group_leader_site_id = '';

    protected function syncRepositorySyncUiState(): void
    {
        $manager = app(SiteDeploySyncGroupManager::class);
        $group = $manager->findGroupForSite($this->site);
        $this->sync_group_leader_site_id = $group?->leader_site_id ? (string) $group->leader_site_id : '';
    }

    public function createDeploySyncGroup(SiteDeploySyncGroupManager $manager): void
    {
        $this->authorize('update', $this->site);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage sync groups.'));

            return;
        }

        $this->validate(['sync_group_name_input' => 'required|string|max:120']);
        $manager->createGroup($this->site->fresh(), $this->sync_group_name_input);
        $this->sync_group_name_input = '';
        $this->toastSuccess(__('Synchronized deployment group created.'));
        $this->syncRepositorySyncUiState();
    }

    public function addSiteToDeploySyncGroup(SiteDeploySyncGroupManager $manager): void
    {
        $this->authorize('update', $this->site);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage sync groups.'));

            return;
        }

        $this->validate(['sync_group_add_site_id' => 'required|string']);
        $group = $manager->findGroupForSite($this->site);
        if ($group === null) {
            $this->addError('sync_group_add_site_id', __('Create a group first.'));

            return;
        }
        $other = Site::query()
            ->where('organization_id', $this->site->organization_id)
            ->findOrFail($this->sync_group_add_site_id);
        $manager->addSite($group, $other);
        $this->sync_group_add_site_id = '';
        $this->toastSuccess(__('Site added to the sync group.'));
        $this->syncRepositorySyncUiState();
    }

    public function setDeploySyncGroupLeader(SiteDeploySyncGroupManager $manager): void
    {
        $this->authorize('update', $this->site);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage sync groups.'));

            return;
        }

        $this->validate(['sync_group_leader_site_id' => 'required|string']);
        $group = $manager->findGroupForSite($this->site);
        if ($group === null) {
            return;
        }
        $leader = Site::query()
            ->where('organization_id', $this->site->organization_id)
            ->findOrFail($this->sync_group_leader_site_id);
        $manager->setLeader($group, $leader);
        $this->toastSuccess(__('Leader updated.'));
        $this->syncRepositorySyncUiState();
    }

    public function leaveDeploySyncGroup(SiteDeploySyncGroupManager $manager): void
    {
        $this->authorize('update', $this->site);
        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->dispatch('notify', message: __('Deployers cannot manage sync groups.'));

            return;
        }

        $manager->removeSite($this->site->fresh());
        $this->toastSuccess(__('Removed from sync group.'));
        $this->syncRepositorySyncUiState();
    }
}
