<?php

namespace App\Services\Sites;

use App\Jobs\RunSiteDeploymentJob;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeploySyncGroup;

class SiteDeploySyncCoordinator
{
    public function findGroupForSite(Site $site): ?SiteDeploySyncGroup
    {
        return SiteDeploySyncGroup::query()
            ->whereHas('sites', fn ($q) => $q->where('sites.id', $site->id))
            ->first();
    }

    public function shouldIncludePeersOnManual(Site $site): bool
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $repo = is_array($meta['repository'] ?? null) ? $meta['repository'] : [];

        return (bool) ($repo['deploy_sync_include_peers_on_manual'] ?? true);
    }

    /**
     * After a webhook succeeds for the receiving site, queue deploys for other group members.
     */
    public function queuePeerDeploysFromWebhook(Site $triggeredSite): void
    {
        $group = $this->findGroupForSite($triggeredSite);
        if ($group === null) {
            return;
        }

        if ($group->leader_site_id !== null && (string) $triggeredSite->id !== (string) $group->leader_site_id) {
            return;
        }

        $group->loadMissing('sites');
        foreach ($group->sites as $peer) {
            if ((string) $peer->id === (string) $triggeredSite->id) {
                continue;
            }
            RunSiteDeploymentJob::dispatch($peer->fresh(), SiteDeployment::TRIGGER_SYNC_PEER);
        }
    }

    /**
     * Manual deploy: optionally fan out to all sites in the same sync group.
     */
    public function dispatchManualForGroup(Site $site): void
    {
        $group = $this->findGroupForSite($site);
        if ($group === null || ! $this->shouldIncludePeersOnManual($site)) {
            RunSiteDeploymentJob::dispatch($site->fresh(), SiteDeployment::TRIGGER_MANUAL);

            return;
        }

        $group->loadMissing('sites');
        foreach ($group->sites as $member) {
            RunSiteDeploymentJob::dispatch($member->fresh(), SiteDeployment::TRIGGER_MANUAL);
        }
    }

    public function dispatchManualSingle(Site $site): void
    {
        RunSiteDeploymentJob::dispatch($site->fresh(), SiteDeployment::TRIGGER_MANUAL);
    }
}
