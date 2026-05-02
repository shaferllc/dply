<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDeploySyncGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SiteDeploySyncGroupManager
{
    public function createGroup(Site $initial, string $name): SiteDeploySyncGroup
    {
        $name = trim($name);
        if ($name === '') {
            throw ValidationException::withMessages(['sync_group_name' => __('Enter a group name.')]);
        }

        $existing = $this->findGroupForSite($initial);
        if ($existing !== null) {
            throw ValidationException::withMessages(['sync_group_name' => __('This site is already in a sync group. Remove it first.')]);
        }

        return DB::transaction(function () use ($initial, $name): SiteDeploySyncGroup {
            $group = SiteDeploySyncGroup::query()->create([
                'organization_id' => $initial->organization_id,
                'name' => $name,
                'leader_site_id' => $initial->id,
            ]);
            $group->sites()->attach($initial->id, [
                'id' => (string) Str::ulid(),
                'sort_order' => 0,
            ]);

            return $group;
        });
    }

    public function addSite(SiteDeploySyncGroup $group, Site $site): void
    {
        if ((string) $site->organization_id !== (string) $group->organization_id) {
            throw ValidationException::withMessages(['sync_site' => __('Sites must belong to the same organization.')]);
        }

        $other = $this->findGroupForSite($site);
        if ($other !== null && $other->id !== $group->id) {
            throw ValidationException::withMessages(['sync_site' => __('That site is already in another sync group.')]);
        }

        if (! $group->sites()->where('sites.id', $site->id)->exists()) {
            $max = (int) $group->sites()->max('site_deploy_sync_group_sites.sort_order');
            $group->sites()->attach($site->id, [
                'id' => (string) Str::ulid(),
                'sort_order' => $max + 1,
            ]);
        }
    }

    public function removeSite(Site $site): void
    {
        $group = $this->findGroupForSite($site);
        if ($group === null) {
            return;
        }

        DB::transaction(function () use ($group, $site): void {
            $group->sites()->detach($site->id);
            if ((string) $group->leader_site_id === (string) $site->id) {
                $group->leader_site_id = null;
                $group->save();
            }
            if ($group->sites()->count() === 0) {
                $group->delete();
            }
        });
    }

    public function setLeader(SiteDeploySyncGroup $group, Site $leader): void
    {
        if ((string) $leader->organization_id !== (string) $group->organization_id) {
            throw ValidationException::withMessages(['leader' => __('Invalid leader site.')]);
        }

        if (! $group->sites()->where('sites.id', $leader->id)->exists()) {
            throw ValidationException::withMessages(['leader' => __('Leader must be a member of the group.')]);
        }

        $group->leader_site_id = $leader->id;
        $group->save();
    }

    public function findGroupForSite(Site $site): ?SiteDeploySyncGroup
    {
        return SiteDeploySyncGroup::query()
            ->whereHas('sites', fn ($q) => $q->where('sites.id', $site->id))
            ->first();
    }
}
