<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteDeploySyncGroup;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;

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
        $fleet = $this->fleetReplicas($triggeredSite);

        if ($group === null && $fleet->isEmpty()) {
            return;
        }

        if ($group !== null) {
            if ($group->leader_site_id !== null && (string) $triggeredSite->id !== (string) $group->leader_site_id) {
                return;
            }

            $group->loadMissing('sites');
            $peers = $group->sites->reject(fn (Site $p): bool => (string) $p->id === (string) $triggeredSite->id);
            $this->rollOut($group, $peers->concat($fleet)->unique('id')->values(), SiteDeployment::TRIGGER_SYNC_PEER);

            return;
        }

        foreach ($fleet as $replica) {
            RunSiteDeploymentJob::dispatch($replica->fresh(), SiteDeployment::TRIGGER_SYNC_PEER);
        }
    }

    /**
     * Manual deploy: optionally fan out to all sites in the same sync group.
     */
    public function dispatchManualForGroup(Site $site): void
    {
        $group = $this->findGroupForSite($site);
        $fleet = $this->fleetReplicas($site);

        if ($group === null || ! $this->shouldIncludePeersOnManual($site)) {
            if ($fleet->isEmpty()) {
                RunSiteDeploymentJob::dispatch($site->fresh(), SiteDeployment::TRIGGER_MANUAL);

                return;
            }

            Bus::chain(
                collect([$site])->concat($fleet)->map(
                    fn (Site $s) => new RunSiteDeploymentJob($s->fresh(), SiteDeployment::TRIGGER_MANUAL)
                )->all()
            )->dispatch();

            return;
        }

        $group->loadMissing('sites');
        $members = $group->sites->concat($fleet)->unique('id')->values();
        $this->rollOut($group, $members, SiteDeployment::TRIGGER_MANUAL);
    }

    public function dispatchManualSingle(Site $site): void
    {
        RunSiteDeploymentJob::dispatch($site->fresh(), SiteDeployment::TRIGGER_MANUAL);
    }

    /**
     * Deploy a whole group now, honouring its rollout mode (parallel/sequential).
     * Returns the number of members queued.
     */
    public function dispatchGroup(SiteDeploySyncGroup $group, string $trigger = SiteDeployment::TRIGGER_MANUAL): int
    {
        $group->loadMissing('sites');
        $this->rollOut($group, $group->sites, $trigger);

        return $group->sites->count();
    }

    /**
     * Hidden worker-fleet replicas of this site (empty when the site is itself a replica).
     *
     * @return Collection<int, Site>
     */
    public function fleetReplicas(Site $site): Collection
    {
        return $site->fleetReplicaSites();
    }

    /**
     * Fan out a deploy to a set of group members per the group's rollout mode:
     *  - parallel:   dispatch all at once (independent).
     *  - sequential: a Bus chain in the group's site order — each member deploys
     *    only after the previous succeeds; a failed deploy (RunSiteDeploymentJob
     *    throws) halts the chain, so it's ordered + stop-on-failure.
     *
     * @param  Collection<int, Site>  $members
     */
    private function rollOut(SiteDeploySyncGroup $group, $members, string $trigger): void
    {
        $members = $members->values();
        if ($members->isEmpty()) {
            return;
        }

        if ($group->isSequential()) {
            Bus::chain(
                $members->map(fn (Site $s) => new RunSiteDeploymentJob($s->fresh(), $trigger))->all()
            )->dispatch();

            return;
        }

        foreach ($members as $member) {
            RunSiteDeploymentJob::dispatch($member->fresh(), $trigger);
        }
    }
}
