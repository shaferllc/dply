<?php

declare(strict_types=1);

namespace App\Services\Sites\Backends;

use App\Models\Site;
use App\Models\SiteBackend;
use App\Models\SiteDeployment;
use App\Services\Sites\SiteGitDeployer;

/**
 * Rolling deploy across a multi-backend site: one backend at a time, drain it
 * from the balancer → deploy the new release to it → return it to rotation, then
 * the next. Capacity stays up (only one backend leaves rotation at a time) and a
 * bad release is caught before it spreads — if any backend's deploy fails, the
 * roll ABORTS with the remaining backends still serving the previous release.
 *
 * The per-backend deploy reuses {@see SiteGitDeployer} (a normal atomic deploy on
 * that box — its built-in health check + auto-rollback gate each backend), so
 * rolling is pure orchestration on top of the balancer {@see SiteBackendBalancerSync}
 * keystone: drain = set `drained_at` + sync; re-add = clear it + sync. See
 * docs/MULTI_BACKEND_SITES.md.
 */
class RollingSiteDeployer
{
    /** Seconds to let in-flight requests finish after a backend leaves rotation. */
    private const DRAIN_GRACE_SECONDS = 8;

    public function __construct(
        private readonly SiteGitDeployer $deployer,
        private readonly SiteBackendBalancerSync $balancer,
    ) {}

    /**
     * @return array{output: string, sha: ?string}
     */
    public function deploy(Site $site, ?SiteDeployment $deployment = null): array
    {
        // Replicas first, primary last — validate the new release on a replica
        // before the primary box changes.
        $backends = $site->backends()
            ->where('state', SiteBackend::STATE_ACTIVE)
            ->with(['server', 'backendSite'])
            ->get()
            ->sortBy(fn (SiteBackend $b): int => $b->isPrimary() ? 1 : 0)
            ->values();

        $total = $backends->count();
        $log = sprintf("\n=== rolling deploy across %d backend(s) ===\n", $total);
        $lastSha = null;

        foreach ($backends as $i => $backend) {
            /** @var SiteBackend $backend */
            // The primary backend's code IS the logical site; replicas deploy
            // their derived child site.
            $target = $backend->isPrimary() ? $site : $backend->backendSite;
            $label = (string) ($backend->server->name ?? $backend->id);

            if ($target === null) {
                $log .= sprintf("[rolling] backend %d/%d (%s): no child site yet — skipped\n", $i + 1, $total, $label);

                continue;
            }

            $log .= sprintf("\n[rolling] ── backend %d/%d: %s ──\n", $i + 1, $total, $label);

            // Drain: pull from rotation so new requests stop hitting it, let
            // in-flight ones finish.
            $this->setDrain($backend, true);
            $this->balancer->sync($site);
            $log .= sprintf("[rolling] drained from balancer; waiting %ds for in-flight requests\n", self::DRAIN_GRACE_SECONDS);
            sleep(self::DRAIN_GRACE_SECONDS);

            try {
                $result = $this->deployer->run($target, null);
                $lastSha = $result['sha'] ?? $lastSha;
                $log .= (string) ($result['output'] ?? '');
            } catch (\Throwable $e) {
                // SiteGitDeployer's atomic path already auto-rolled-back this
                // backend's release (when health auto-rollback is on), so it's
                // serving the prior good code again — return it to rotation, then
                // abort the roll so the remaining backends stay on old code too.
                $this->setDrain($backend, false);
                $this->balancer->sync($site);

                throw new \RuntimeException(
                    sprintf(
                        'Rolling deploy aborted on backend %s: %s. The remaining backends were left on the previous release.',
                        $label,
                        $e->getMessage(),
                    ),
                    0,
                    $e,
                );
            }

            // Healthy on the new release — return to rotation before the next.
            $this->setDrain($backend, false);
            $this->balancer->sync($site);
            $log .= sprintf("[rolling] backend %s healthy; returned to rotation\n", $label);
        }

        $log .= "\n=== rolling deploy complete ===\n";

        return ['output' => $log, 'sha' => $lastSha];
    }

    private function setDrain(SiteBackend $backend, bool $drained): void
    {
        $backend->forceFill(['drained_at' => $drained ? now() : null])->save();
    }
}
