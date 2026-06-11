<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\RunSiteDeploymentJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Actions\Sites\ScheduleSiteDeploy;
use App\Models\ScheduledDeploy;
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

    /**
     * Schedule a one-off deploy for a future moment. `$when` is either a number
     * of minutes from now (preset buttons) or an absolute datetime string (the
     * custom picker). Replaces any existing pending delayed deploy for the site
     * — one queued deploy at a time. The RunDueScheduledDeploysCommand tick
     * fires it when due.
     */
    public function scheduleDeploy(string $when): void
    {
        $this->authorize('update', $this->site);

        $scheduled = app(ScheduleSiteDeploy::class)->schedule($this->site, $when, auth()->id());
        if ($scheduled === null) {
            $this->toastError(__('Pick a time in the future to schedule the deploy.'));

            return;
        }

        unset($this->pendingScheduledDeploy);
        $this->toastSuccess(__('Deploy scheduled :when.', ['when' => $scheduled->run_at->diffForHumans()]));
    }

    public function cancelScheduledDeploy(): void
    {
        $this->authorize('update', $this->site);

        app(ScheduleSiteDeploy::class)->cancelPending($this->site);

        unset($this->pendingScheduledDeploy);
        $this->toastSuccess(__('Scheduled deploy canceled.'));
    }

    /** The site's pending one-off delayed deploy, if any. */
    public function getPendingScheduledDeployProperty(): ?ScheduledDeploy
    {
        return app(ScheduleSiteDeploy::class)->pendingFor($this->site);
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

    /**
     * Whether the deploy button should show the spinning "Deploying…" state.
     *
     * True only while a run is genuinely live: the latest deployment is RUNNING,
     * or the optimistic deploy lock is still held AND no terminal run has landed
     * since it was taken. The lock is a 600s marker set on click (deployNow); a
     * self-deploy can kill the worker that runs RunSiteDeploymentJob's
     * lock-cleanup `finally` — it bounces its own Horizon/queue workers
     * mid-deploy — leaving the lock set for the full TTL. Keying the button off
     * raw lock presence then spins "Deploying…" for ~10 minutes after the deploy
     * has already succeeded or failed. So we stop as soon as THIS run lands a
     * terminal status, and otherwise honour the lock only within the brief
     * queue-pickup window (before the worker records the running deployment).
     */
    public function deployIsInProgress(?SiteDeployment $latest): bool
    {
        if ($latest !== null && $latest->status === SiteDeployment::STATUS_RUNNING) {
            return true;
        }

        $lock = $this->deployLockInfo;
        $startedAt = isset($lock['started_at']) ? \Illuminate\Support\Carbon::parse($lock['started_at']) : null;
        if ($startedAt === null) {
            return false;
        }

        $terminalSinceLock = $latest !== null
            && in_array($latest->status, [
                SiteDeployment::STATUS_FAILED,
                SiteDeployment::STATUS_SUCCESS,
                SiteDeployment::STATUS_SKIPPED,
            ], true)
            && $latest->created_at?->greaterThanOrEqualTo($startedAt->subSeconds(2));

        return ! $terminalSinceLock && $startedAt->greaterThan(now()->subSeconds(90));
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

    /**
     * Open the confirm modal for resuming a failed deploy from the phase it
     * died at. The release phase re-runs migrations, which may have partially
     * applied on the failed attempt, so it gets a sharper warning than build.
     */
    public function confirmResumeDeployment(string $deploymentId): void
    {
        $this->authorize('update', $this->site);

        $deployment = SiteDeployment::query()
            ->where('site_id', $this->site->id)
            ->find($deploymentId);

        $phase = $deployment?->resumeStartPhase();
        if ($deployment === null || $phase === null) {
            $this->toastError(__('This deployment can no longer be resumed. Run a full deploy instead.'));

            return;
        }

        $body = $phase === 'release'
            ? __('Re-run the deploy from the release phase, reusing the build that already succeeded? This re-runs migrations — if the failed attempt partially applied a migration, run it manually or roll back the database first.')
            : __('Re-run the deploy from the build phase, reusing the release that was already cloned? The live site is untouched until the new build passes.');

        $this->openConfirmActionModal(
            'resumeDeployment',
            [$deploymentId],
            __('Resume from :phase phase', ['phase' => $phase]),
            $body,
            __('Resume deploy'),
            $phase === 'release',
        );
    }

    public function resumeDeployment(string $deploymentId): void
    {
        $this->authorize('update', $this->site);

        $deployment = SiteDeployment::query()
            ->where('site_id', $this->site->id)
            ->find($deploymentId);

        if ($deployment === null || ! $deployment->isResumable()) {
            $this->toastError(__('This deployment can no longer be resumed. Run a full deploy instead.'));

            return;
        }

        // Same optimistic marker as deployNow so the panel immediately reads
        // "Deploying…" and starts polling.
        Cache::put('site-deploy-active:'.$this->site->id, [
            'started_at' => now()->toIso8601String(),
            'deployment_id' => null,
        ], 600);

        RunSiteDeploymentJob::dispatch(
            $this->site,
            SiteDeployment::TRIGGER_RESUME,
            null,
            auth()->id(),
            $deployment->id,
        );

        $this->toastSuccess(__('Resuming from the :phase phase. Watch the timeline below.', ['phase' => $deployment->resumeStartPhase()]));
    }

    public function rollbackRelease(int|string $releaseId, SiteReleaseRollback $rollback): void
    {
        $this->authorize('update', $this->site);

        try {
            $release = SiteRelease::query()->where('site_id', $this->site->id)->findOrFail($releaseId);

            // Image-method (VM Docker) sites roll back by re-running the prior
            // tagged image, not by flipping the atomic `current` symlink.
            if ($this->site->usesVmDockerRuntime()) {
                app(\App\Services\Deploy\DockerImageReleaseRollback::class)->rollbackTo($this->site, $release);
                $this->site->refresh();
                $this->toastSuccess(__('Rolled back to the previous container image.'));

                return;
            }

            if (! $this->server->hostCapabilities()->supportsReleaseRollback()) {
                $this->toastError(__('This host runtime does not support release rollback via server symlinks.'));

                return;
            }

            $rollback->rollbackTo($this->site, $release);
            $this->site->refresh();
            $this->toastSuccess(__('Rolled back active release symlink. Re-install Nginx if document root changed.'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }
}
