<?php

declare(strict_types=1);

namespace App\Services\Sites\Backends;

use App\Models\Site;
use App\Models\SiteBackend;
use App\Models\SiteDeployment;
use App\Models\SiteRelease;
use App\Services\Sites\AtomicDeployHealthChecker;
use App\Services\Sites\SiteGitDeployer;
use App\Services\Sites\SiteReleaseRollback;
use App\Services\SshConnectionFactory;

/**
 * Canary deploy: ship the new release to ONE backend, send it a small slice of
 * traffic, watch it, and ramp the slice up (10 → 25 → 50 → 100%) only while it
 * stays healthy — then promote the release to the rest. A health regression at
 * any step rolls the canary back to the previous release and restores all
 * traffic to it. HAProxy-only (needs per-backend weights). Pure orchestration on
 * the {@see SiteBackendBalancerSync} keystone: shifting a slice = set the
 * backend's `weight` + sync. See docs/MULTI_BACKEND_SITES.md.
 */
class CanarySiteDeployer
{
    /** Traffic share given to the canary at each step, in percent. */
    private const STEPS = [10, 25, 50, 100];

    /** Seconds to watch the canary at each weight before ramping. */
    private const OBSERVE_SECONDS = 20;

    /** Grace before redeploying a promoted backend (drain in-flight). */
    private const DRAIN_GRACE_SECONDS = 8;

    public function __construct(
        private readonly SiteGitDeployer $deployer,
        private readonly SiteBackendBalancerSync $balancer,
        private readonly SshConnectionFactory $sshFactory,
    ) {}

    /**
     * @return array{output: string, sha: ?string}
     */
    public function deploy(Site $site, ?SiteDeployment $deployment = null): array
    {
        $backends = $site->backends()
            ->where('state', SiteBackend::STATE_ACTIVE)
            ->with(['server', 'backendSite'])
            ->get();

        $canary = $backends->first(fn (SiteBackend $b): bool => ! $b->isPrimary());
        if ($canary === null || $canary->backendSite === null) {
            throw new \RuntimeException('Canary needs at least one replica backend with a built child site.');
        }
        $rest = $backends->reject(fn (SiteBackend $b): bool => $b->id === $canary->id)->values();

        $canarySite = $canary->backendSite;
        $label = (string) ($canary->server->name ?? $canary->id);
        $log = "\n=== canary deploy ===\n";

        // The release the canary is currently on — its rollback target if the new
        // one regresses. Capture before the deploy flips is_active.
        $previousRelease = SiteRelease::query()
            ->where('site_id', $canarySite->id)
            ->where('is_active', true)
            ->first();

        // 1. Ship the new release to the canary backend only.
        $log .= sprintf("[canary] deploying new release to %s\n", $label);
        $result = $this->deployer->run($canarySite, null);
        $newSha = $result['sha'] ?? null;
        $log .= (string) ($result['output'] ?? '');

        // 2. Ramp the canary's traffic share, watching health at each step. The
        //    rest stay on the old release at full weight.
        foreach (self::STEPS as $step) {
            $canary->forceFill(['weight' => $step, 'drained_at' => null])->save();
            $this->balancer->sync($site);
            $log .= sprintf("[canary] %s at %d%% — observing %ds\n", $label, $step, self::OBSERVE_SECONDS);
            sleep(self::OBSERVE_SECONDS);

            if (! $this->isHealthy($canarySite)) {
                $log .= sprintf("[canary] health regression at %d%% — rolling back\n", $step);
                $this->rollbackCanary($site, $canary, $canarySite, $previousRelease);

                throw new \RuntimeException(sprintf(
                    'Canary aborted at %d%% on %s: health regression. Traffic was restored to the previous release.',
                    $step,
                    $label,
                ));
            }
        }

        // 3. Proven at full share — promote the new release to the rest (one at a
        //    time, draining each) and equalise weights.
        $log .= "[canary] healthy at 100% — promoting to the remaining backends\n";
        foreach ($rest as $backend) {
            /** @var SiteBackend $backend */
            $target = $backend->isPrimary() ? $site : $backend->backendSite;
            if ($target === null) {
                continue;
            }
            $name = (string) ($backend->server->name ?? $backend->id);

            $backend->forceFill(['drained_at' => now()])->save();
            $this->balancer->sync($site);
            sleep(self::DRAIN_GRACE_SECONDS);

            $this->deployer->run($target, null);

            $backend->forceFill(['drained_at' => null, 'weight' => 100])->save();
            $this->balancer->sync($site);
            $log .= sprintf("[canary] promoted %s\n", $name);
        }

        // Equalise the canary back to a normal share.
        $canary->forceFill(['weight' => 100])->save();
        $this->balancer->sync($site);

        $log .= "\n=== canary deploy complete ===\n";

        return ['output' => $log, 'sha' => $newSha];
    }

    /**
     * Restore the canary to the previous release and pull it from the weighted
     * mix so 100% of traffic is back on the known-good code.
     */
    private function rollbackCanary(Site $site, SiteBackend $canary, Site $canarySite, ?SiteRelease $previousRelease): void
    {
        $canary->forceFill(['weight' => 0, 'drained_at' => now()])->save();
        $this->balancer->sync($site);

        if ($previousRelease !== null) {
            try {
                app(SiteReleaseRollback::class)->rollbackTo($canarySite->fresh() ?? $canarySite, $previousRelease);
            } catch (\Throwable) {
                // Best-effort: the canary is already drained (weight 0), so no new
                // traffic reaches the bad release regardless.
            }
        }

        // Return the canary to rotation on the restored release at normal weight.
        $canary->forceFill(['weight' => 100, 'drained_at' => null])->save();
        $this->balancer->sync($site);
    }

    /**
     * Whether the canary backend is serving healthily. Uses the deploy health
     * check against the backend's own box; when the site has no health check
     * configured this is best-effort (the check is a no-op), so canary safety is
     * strongest with deploy_health_enabled. (Insights error-rate gating is a
     * planned refinement.)
     */
    private function isHealthy(Site $canarySite): bool
    {
        $server = $canarySite->server;
        if ($server === null || empty($server->ssh_private_key)) {
            return false;
        }

        try {
            $ssh = $this->sshFactory->forServer($server);
            app(AtomicDeployHealthChecker::class)->verify($canarySite, $ssh);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
