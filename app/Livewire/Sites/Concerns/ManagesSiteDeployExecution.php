<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\SiteRelease;
use App\Services\Sites\SiteDeploySyncCoordinator;
use App\Services\Sites\SiteReleaseRollback;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Server $server
 * @property Site $site
 *
 * Requires {@see ConfirmsActionWithModal} and {@see DispatchesToastNotifications} on the component.
 */
trait ManagesSiteDeployExecution
{
    public function deployNow(): void
    {
        $this->authorize('update', $this->site);

        // Queue the deploy on a worker instead of running it inline. SSH must
        // never block a Livewire/HTTP request (PHP max_execution_time), so the
        // job runs the clone/build/release steps off-request. Seed the active
        // marker now so the panel immediately reads "Deploying…" and starts
        // polling; the job overwrites it with the real deployment id once it
        // creates the running record.
        Cache::put('site-deploy-active:'.$this->site->id, [
            'started_at' => now()->toIso8601String(),
            'deployment_id' => null,
        ], 600);

        RunSiteDeploymentJob::dispatch($this->site, SiteDeployment::TRIGGER_MANUAL);

        $this->toastSuccess(__('Deployment queued. Watch the phase timeline below.'));
    }

    public function queueDeploy(SiteDeploySyncCoordinator $coordinator): void
    {
        $this->authorize('update', $this->site);
        $coordinator->dispatchManualForGroup($this->site);
        $base = __('Deployment queued. If another run is in progress, the new one may be recorded as skipped. Refresh deployments below.');
        $this->toastSuccess(config('insights.queue_after_deploy', true)
            ? $base.' '.__('After a successful deploy, server and site insight runs are queued automatically.')
            : $base);
    }

    /**
     * Number of *other* sites a manual "deploy group" would fan out to. Zero when
     * the site is solo or peer fan-out is disabled — in that case "Queue deploy"
     * does exactly what "Deploy now" does, so the second button is hidden.
     */
    public function getDeploySyncPeerCountProperty(): int
    {
        $coordinator = app(SiteDeploySyncCoordinator::class);

        if (! $coordinator->shouldIncludePeersOnManual($this->site)) {
            return 0;
        }

        $group = $coordinator->findGroupForSite($this->site);

        if ($group === null) {
            return 0;
        }

        return max(0, $group->sites()->count() - 1);
    }

    /**
     * @return array{deployment_id?: string}|null
     */
    public function getDeployLockInfoProperty(): ?array
    {
        return Cache::get('site-deploy-active:'.$this->site->id);
    }

    public function releaseDeployLock(): void
    {
        $this->authorize('update', $this->site);
        Cache::lock('site-deploy:'.$this->site->id)->forceRelease();
        Cache::forget('site-deploy-active:'.$this->site->id);
        $this->toastSuccess(__('Deploy lock cleared. If a worker is still running, stop it on the queue host; otherwise you can deploy again.'));
    }

    public function confirmRollbackRelease(int|string $releaseId): void
    {
        $this->authorize('update', $this->site);

        $this->openConfirmActionModal(
            'rollbackRelease',
            [(string) $releaseId],
            __('Rollback release'),
            __('Point current symlink at this release?'),
            __('Rollback'),
            true,
        );
    }

    public function rollbackRelease(int|string $releaseId, SiteReleaseRollback $rollback): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsReleaseRollback()) {
            $this->toastError(__('This host runtime does not support release rollback via server symlinks.'));

            return;
        }

        try {
            $release = SiteRelease::query()->where('site_id', $this->site->id)->findOrFail($releaseId);
            $rollback->rollbackTo($this->site, $release);
            $this->site->refresh();
            $this->toastSuccess(__('Rolled back active release symlink. Re-install Nginx if document root changed.'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }
}
