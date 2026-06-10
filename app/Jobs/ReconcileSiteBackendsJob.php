<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteBackend;
use App\Models\SiteDeployment;
use App\Services\Sites\Backends\SiteBackendBalancerSync;
use App\Services\Sites\Backends\SiteBackendReplicator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Drives a multi-backend site's {@see SiteBackend} rows to `active`, re-entrant
 * and idempotent like ReconcileWorkerPoolJob but standalone and web-shaped:
 *
 *   provisioning ──(backend server ready)──▶ deploying ──(child provisioned)──▶ active
 *
 * Each tick advances whatever it can and re-dispatches itself ~30s later until
 * every backend is `active` (or wedged → `errored`). The first deploy of each
 * backend's child site is owned here so it fires only once the box and the site
 * are actually ready (ProvisionSiteJob self-polls and isn't chainable).
 * See docs/MULTI_BACKEND_SITES.md.
 */
class ReconcileSiteBackendsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const MAX_ATTEMPTS = 240;   // ~2h at 30s spacing — a hard backstop.

    private const RETRY_SECONDS = 30;

    private const STUCK_MINUTES = 20;   // mark a backend errored if it stalls this long in one state.

    public function __construct(
        public string $siteId,
        public int $attempt = 1,
    ) {}

    public function handle(SiteBackendReplicator $replicator): void
    {
        $site = Site::query()->with(['backends.server'])->find($this->siteId);
        if (! $site instanceof Site) {
            return;
        }

        $inFlight = false;
        $newlyActive = false;

        foreach ($site->backends as $backend) {
            /** @var SiteBackend $backend */
            if (in_array($backend->state, [SiteBackend::STATE_ACTIVE, SiteBackend::STATE_ERRORED, SiteBackend::STATE_DRAINING], true)) {
                continue;
            }

            $wasActive = $backend->state === SiteBackend::STATE_ACTIVE;

            try {
                $advanced = $this->advance($site, $backend, $replicator);
            } catch (\Throwable $e) {
                Log::warning('site-backend reconcile: advance failed', [
                    'site_id' => $this->siteId,
                    'backend_id' => $backend->id,
                    'error' => $e->getMessage(),
                ]);
                $advanced = false;
            }

            if (! $advanced && $this->isStuck($backend)) {
                $this->markState($backend, SiteBackend::STATE_ERRORED);

                continue;
            }

            $nowActive = $backend->fresh()?->state === SiteBackend::STATE_ACTIVE;
            $newlyActive = $newlyActive || (! $wasActive && $nowActive);
            $inFlight = $inFlight || ! $nowActive;
        }

        // A backend just started serving — register it with the balancer so it
        // begins taking traffic.
        if ($newlyActive) {
            app(SiteBackendBalancerSync::class)->sync($site->fresh());
        }

        if ($inFlight && $this->attempt < self::MAX_ATTEMPTS) {
            self::dispatch($this->siteId, $this->attempt + 1)
                ->delay(now()->addSeconds(self::RETRY_SECONDS));
        }
    }

    /**
     * Advance one backend by at most one state. Returns true if it made progress
     * this tick (which resets the stuck timer).
     */
    private function advance(Site $site, SiteBackend $backend, SiteBackendReplicator $replicator): bool
    {
        $server = $backend->server;
        if ($server === null) {
            $this->markState($backend, SiteBackend::STATE_ERRORED);

            return true;
        }

        return match ($backend->state) {
            SiteBackend::STATE_PROVISIONING => $this->onProvisioning($site, $backend, $replicator),
            SiteBackend::STATE_REPLAYING, SiteBackend::STATE_DEPLOYING => $this->onDeploying($backend),
            default => false,
        };
    }

    /**
     * Backend server still coming up. Once it's provisioned, replicate the site's
     * code onto it as the child Site and move to `deploying`.
     */
    private function onProvisioning(Site $site, SiteBackend $backend, SiteBackendReplicator $replicator): bool
    {
        if (! $backend->server?->isProvisioningComplete()) {
            return false;
        }

        $child = $replicator->replicate($site, $backend->server);
        $backend->forceFill(['backend_site_id' => $child->id])->save();
        $this->markState($backend, SiteBackend::STATE_DEPLOYING);

        return true;
    }

    /**
     * Child site exists; once it reports provisioning complete, fire its first
     * deploy and mark the backend `active` (mirrors the worker reconciler, which
     * activates when the deploy is queued, not when it finishes).
     */
    private function onDeploying(SiteBackend $backend): bool
    {
        $child = $backend->backendSite;
        if ($child === null) {
            // Lost the child reference — drop back to provisioning to rebuild it.
            $this->markState($backend, SiteBackend::STATE_PROVISIONING);

            return false;
        }

        if (($child->meta['provisioning']['state'] ?? null) !== 'ready') {
            return false;
        }

        RunSiteDeploymentJob::dispatch($child, SiteDeployment::TRIGGER_MANUAL);
        $this->markState($backend, SiteBackend::STATE_ACTIVE);

        return true;
    }

    private function isStuck(SiteBackend $backend): bool
    {
        $since = $backend->meta['state_since'] ?? null;
        if (! is_string($since) || $since === '') {
            return false;
        }

        return Carbon::parse($since)->lt(now()->subMinutes(self::STUCK_MINUTES));
    }

    private function markState(SiteBackend $backend, string $state): void
    {
        $meta = is_array($backend->meta) ? $backend->meta : [];
        if (($backend->state ?? null) !== $state) {
            $meta['state_since'] = now()->toIso8601String();
        }
        $backend->forceFill(['state' => $state, 'meta' => $meta])->save();
    }
}
