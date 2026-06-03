<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeploySyncGroup;
use App\Services\Sites\SiteDeploySyncCoordinator;
use App\Services\Sites\SiteDeploySyncGroupManager;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Org-wide management of deploy sync groups: see every group and its member
 * sites in one place, create groups, add/remove sites (across servers and
 * projects), set the leader, and fan out a deploy to a whole group. The
 * per-site Settings → Repository panel manages a single site's membership; this
 * is the central view across the organization.
 */
#[Layout('layouts.app')]
class DeploySyncGroups extends Component
{
    use DispatchesToastNotifications;

    public string $new_group_name = '';

    public string $new_group_site_id = '';

    /** groupId => siteId selected in that group's "add site" dropdown. */
    public array $add_site_for_group = [];

    private function org()
    {
        return auth()->user()?->currentOrganization();
    }

    private function canManage(): bool
    {
        $user = auth()->user();
        $org = $this->org();

        // Mirror the per-site guard: deployers can't manage sync groups.
        return $org !== null && ! (bool) $org->userIsDeployer($user);
    }

    private function orgSite(string $siteId): ?Site
    {
        $org = $this->org();
        if ($org === null) {
            return null;
        }

        return Site::query()->where('organization_id', $org->id)->find($siteId);
    }

    private function orgGroup(string $groupId): ?SiteDeploySyncGroup
    {
        $org = $this->org();
        if ($org === null) {
            return null;
        }

        return SiteDeploySyncGroup::query()->where('organization_id', $org->id)->with('sites')->find($groupId);
    }

    public function createGroup(SiteDeploySyncGroupManager $manager): void
    {
        if (! $this->canManage()) {
            $this->toastError(__('Deployers cannot manage sync groups.'));

            return;
        }
        $this->validate([
            'new_group_name' => 'required|string|max:120',
            'new_group_site_id' => 'required|string',
        ]);

        $site = $this->orgSite($this->new_group_site_id);
        if (! $site instanceof Site) {
            $this->addError('new_group_site_id', __('Pick a site in your organization.'));

            return;
        }

        try {
            $manager->createGroup($site, $this->new_group_name);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->reset('new_group_name', 'new_group_site_id');
        $this->toastSuccess(__('Sync group created.'));
    }

    public function addSite(string $groupId, SiteDeploySyncGroupManager $manager): void
    {
        if (! $this->canManage()) {
            $this->toastError(__('Deployers cannot manage sync groups.'));

            return;
        }
        $group = $this->orgGroup($groupId);
        $site = $this->orgSite((string) ($this->add_site_for_group[$groupId] ?? ''));
        if ($group === null || ! $site instanceof Site) {
            $this->toastError(__('Pick a site to add.'));

            return;
        }

        try {
            $manager->addSite($group, $site);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        unset($this->add_site_for_group[$groupId]);
        $this->toastSuccess(__('Site added to the group.'));
    }

    public function removeSite(string $siteId, SiteDeploySyncGroupManager $manager): void
    {
        if (! $this->canManage()) {
            $this->toastError(__('Deployers cannot manage sync groups.'));

            return;
        }
        $site = $this->orgSite($siteId);
        if (! $site instanceof Site) {
            return;
        }
        $manager->removeSite($site);
        $this->toastSuccess(__('Site removed from its group.'));
    }

    public function setLeader(string $groupId, string $siteId, SiteDeploySyncGroupManager $manager): void
    {
        if (! $this->canManage()) {
            $this->toastError(__('Deployers cannot manage sync groups.'));

            return;
        }
        $group = $this->orgGroup($groupId);
        $leader = $this->orgSite($siteId);
        if ($group === null || ! $leader instanceof Site) {
            return;
        }
        try {
            $manager->setLeader($group, $leader);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }
        $this->toastSuccess(__('Leader updated.'));
    }

    public function setRolloutMode(string $groupId, string $mode): void
    {
        if (! $this->canManage()) {
            $this->toastError(__('Deployers cannot manage sync groups.'));

            return;
        }
        $mode = in_array($mode, [SiteDeploySyncGroup::ROLLOUT_PARALLEL, SiteDeploySyncGroup::ROLLOUT_SEQUENTIAL], true)
            ? $mode
            : SiteDeploySyncGroup::ROLLOUT_PARALLEL;
        $group = $this->orgGroup($groupId);
        if ($group === null) {
            return;
        }
        $group->forceFill(['rollout_mode' => $mode])->save();
        $this->toastSuccess($mode === SiteDeploySyncGroup::ROLLOUT_SEQUENTIAL
            ? __('Rollout set to sequential (ordered, stops on failure).')
            : __('Rollout set to parallel (all at once).'));
    }

    public function deployGroup(string $groupId, SiteDeploySyncCoordinator $coordinator): void
    {
        if (! $this->canManage()) {
            $this->toastError(__('Deployers cannot manage sync groups.'));

            return;
        }
        $group = $this->orgGroup($groupId);
        if ($group === null) {
            return;
        }
        $n = $coordinator->dispatchGroup($group, SiteDeployment::TRIGGER_MANUAL);
        $mode = $group->isSequential() ? __('sequentially') : __('in parallel');
        $this->toastSuccess(__('Queued a deploy for :n site(s) :mode.', ['n' => $n, 'mode' => $mode]));
    }

    public function deleteGroup(string $groupId): void
    {
        if (! $this->canManage()) {
            $this->toastError(__('Deployers cannot manage sync groups.'));

            return;
        }
        $group = $this->orgGroup($groupId);
        if ($group === null) {
            return;
        }
        $group->sites()->detach();
        $group->delete();
        $this->toastSuccess(__('Sync group deleted.'));
    }

    public function render(): View
    {
        $org = $this->org();
        $groups = $org === null ? collect() : SiteDeploySyncGroup::query()
            ->where('organization_id', $org->id)
            ->with(['sites.server', 'leader'])
            ->orderBy('name')
            ->get();

        $orgSites = $org === null ? collect() : Site::query()
            ->where('organization_id', $org->id)
            ->with('server')
            ->orderBy('name')
            ->get(['id', 'name', 'server_id']);

        return view('livewire.sites.deploy-sync-groups', [
            'groups' => $groups,
            'orgSites' => $orgSites,
            'canManage' => $this->canManage(),
        ]);
    }
}
