<?php

namespace App\Livewire\Concerns;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Support\Sites\DeployConsoleRows;
use App\Support\Sites\SiteSyncPeers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * Deploy-console plumbing shared by any list surface that wants the fleet-style
 * "Deploy / Sync" buttons plus the live slide-over console (servers index, the
 * per-server sites workspace, …). Seeds the same optimistic deploy lock and
 * dispatches the same job as the per-site deploy sidebar so every surface shares
 * one "is a deploy running" source of truth, and points the console at the sites
 * just launched via the `deploy-console-open` window event.
 *
 * Requires the host component to also use {@see GuardsBilledDeploys} (for
 * blockedByDeployPause) and {@see DispatchesToastNotifications}.
 */
trait WatchesSiteDeploys
{
    /** Site ids launched from this surface, driving the deploy console. */
    public array $watchedSiteIds = [];

    /**
     * Deploy a single site — the fleet twin of the deploy sidebar's "Deploy"
     * button. Seeds the same optimistic deploy lock and dispatches the same job
     * so both surfaces share one "is a deploy running" source of truth.
     */
    public function deploySite(string $siteId): void
    {
        $site = Site::query()->with('server')->find($siteId);
        if ($site === null || ! $this->siteIsDeployable($site)) {
            return;
        }
        if ($this->blockedByDeployPause($site)) {
            return;
        }
        Gate::authorize('update', $site);

        $this->queueSiteDeploy($site);
        $this->watchDeploys([(string) $site->id]);
        $this->toastSuccess(__('Deployment queued for :name.', ['name' => $site->name]));
    }

    /**
     * Deploy a site together with its synced peers — the fleet twin of the
     * sidebar's "Sync" button. Peers are this site plus any sharing its Git
     * repository (or the same server when no repo is set), resolved by the same
     * {@see SiteSyncPeers} the sidebar uses.
     */
    public function deploySyncedSites(string $siteId): void
    {
        $site = Site::query()->with('server')->find($siteId);
        if ($site === null) {
            return;
        }
        if ($this->blockedByDeployPause($site)) {
            return;
        }

        [$queuedIds, $skipped] = $this->queueDeploys(SiteSyncPeers::forSite($site));
        $this->watchDeploys($queuedIds);
        $this->reportBatchDeploy(count($queuedIds), $skipped);
    }

    /** Deploy every deployable site on one server (multi-site card "Deploy all"). */
    public function deployServerSites(string $serverId): void
    {
        $server = Server::query()->with('sites')->find($serverId);
        if ($server === null) {
            return;
        }

        $deployable = $server->sites->filter(function (Site $site) use ($server): bool {
            $site->setRelation('server', $server);

            return $this->siteIsDeployable($site);
        });
        if ($deployable->isEmpty()) {
            return;
        }
        if ($this->blockedByDeployPause($deployable->first())) {
            return;
        }

        [$queuedIds, $skipped] = $this->queueDeploys($deployable);
        $this->watchDeploys($queuedIds);
        $this->reportBatchDeploy(count($queuedIds), $skipped);
    }

    /**
     * Queue deploys for an authorised, deployable subset of the given sites.
     *
     * @param  Collection<int, Site>  $sites
     * @return array{0:list<string>,1:int} [queuedSiteIds, skipped]
     */
    protected function queueDeploys(Collection $sites): array
    {
        $queuedIds = [];
        $skipped = 0;
        foreach ($sites as $site) {
            if (! $this->siteIsDeployable($site)) {
                $skipped++;

                continue;
            }
            $this->queueSiteDeploy($site);
            $queuedIds[] = (string) $site->id;
        }

        return [$queuedIds, $skipped];
    }

    /** Seed the optimistic deploy lock and dispatch the deployment job. */
    protected function queueSiteDeploy(Site $site): void
    {
        Cache::put('site-deploy-active:'.$site->id, [
            'started_at' => now()->toIso8601String(),
            'deployment_id' => null,
        ], 600);
        RunSiteDeploymentJob::dispatch($site->fresh(), SiteDeployment::TRIGGER_MANUAL);
    }

    /**
     * Point the deploy console at the sites just launched and open it, so a
     * deploy kicked off from a list row can be watched live without leaving the
     * page. Mirrors DeployControl's `deploy-console-open` event wiring.
     *
     * @param  list<string>  $siteIds
     */
    protected function watchDeploys(array $siteIds): void
    {
        if ($siteIds === []) {
            return;
        }

        $this->watchedSiteIds = array_values(array_map('strval', $siteIds));
        unset($this->watchedRows, $this->watchedInProgress);
        $this->dispatch('deploy-console-open');
    }

    protected function reportBatchDeploy(int $queued, int $skipped): void
    {
        if ($queued === 0) {
            $this->toastError(__('No deployable sites to queue.'));

            return;
        }

        $msg = trans_choice('{1}:count deployment queued.|[2,*]:count deployments queued.', $queued, ['count' => $queued]);
        if ($skipped > 0) {
            $msg .= ' '.__(':n skipped.', ['n' => $skipped]);
        }
        $this->toastSuccess($msg);
    }

    /**
     * Whether a site can be VM-deployed by the current user. Mirrors
     * {@see \App\Livewire\Sites\DeployControl::canDeploy()} — VM host, not a
     * functions/edge runtime, and the user may update it. Expects the site's
     * `server` relation to be loaded.
     */
    protected function siteIsDeployable(Site $site): bool
    {
        $server = $site->server;

        return $server !== null
            && $server->isVmHost()
            && ! $site->usesFunctionsRuntime()
            && ! $site->usesEdgeRuntime()
            && Gate::allows('update', $site);
    }

    /**
     * Live per-site rows for the deploy console — the sites launched from this
     * surface, with their phase timelines and in-flight state.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function watchedRows(): array
    {
        return DeployConsoleRows::forSiteIds($this->watchedSiteIds);
    }

    #[Computed]
    public function watchedInProgress(): bool
    {
        return DeployConsoleRows::anyInProgress($this->watchedRows);
    }
}
