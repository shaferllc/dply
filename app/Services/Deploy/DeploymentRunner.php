<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Models\SiteDeployment;
use App\Models\SiteDeployStep;
use Closure;

/**
 * Drives a {@see SiteDeployment} through all four phases via
 * {@see DeployPhaseRunner}, persisting each phase's results onto the
 * deployment row as it completes.
 *
 * Orchestration order matches the strategy memo's pipeline:
 *   build → swap → release → restart
 *
 * Each phase runs in turn. A failed phase aborts the pipeline (no
 * subsequent phase runs); the deployment's status flips to FAILED
 * with the partial phase_results captured. On full success, status
 * goes to SUCCESS. The deployment's `finished_at` is stamped at the
 * end either way.
 *
 * The runner is fire-and-forget at the orchestrator layer — it
 * doesn't catch exceptions thrown by the underlying RemoteShell.
 * Callers (jobs / CLIs) handle that. Per-step exceptions inside a
 * phase land as ok=false in the result list, not exceptions —
 * that's DeployPhaseRunner's contract.
 */
class DeploymentRunner
{
    public function __construct(
        private DeployPhaseRunner $phaseRunner,
    ) {}

    /**
     * Run a deployment to completion (or first-phase failure).
     *
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory  test seam
     * @return array{ok: bool, phases: array<string, list<array<string, mixed>>>, total_duration_ms: int}
     */
    public function run(SiteDeployment $deployment, string $releaseDir, ?Closure $shellFactory = null): array
    {
        $site = $deployment->site;
        if ($site === null) {
            throw new \RuntimeException('Deployment must belong to a site.');
        }

        $deployment->update(['status' => SiteDeployment::STATUS_RUNNING]);

        $aggregate = ['ok' => true, 'phases' => [], 'total_duration_ms' => 0];

        // Build phase: dependency installs, asset builds, scaffolding.
        $build = $this->phaseRunner->runBuild($site, $releaseDir, $shellFactory);
        $aggregate['phases'][SiteDeployStep::PHASE_BUILD] = $build;
        $deployment->recordPhaseResults(SiteDeployStep::PHASE_BUILD, $build);
        if (! $this->phaseOk($build)) {
            return $this->finalize($deployment, $aggregate, ok: false);
        }

        // Swap phase: atomic symlink flip (no-op for simple deploys).
        $swap = $this->phaseRunner->runSwap($site, $releaseDir, $shellFactory);
        $aggregate['phases'][SiteDeployStep::PHASE_SWAP] = $swap;
        if ($swap !== []) {
            $deployment->recordPhaseResults(SiteDeployStep::PHASE_SWAP, $swap);
            if (! $this->phaseOk($swap)) {
                return $this->finalize($deployment, $aggregate, ok: false);
            }
        }

        // Release phase: post-swap migrations / cache priming.
        $release = $this->phaseRunner->runRelease($site, $releaseDir, $shellFactory);
        $aggregate['phases'][SiteDeployStep::PHASE_RELEASE] = $release;
        $deployment->recordPhaseResults(SiteDeployStep::PHASE_RELEASE, $release);
        if (! $this->phaseOk($release)) {
            return $this->finalize($deployment, $aggregate, ok: false);
        }

        // Restart phase: FPM reload / systemd restart.
        $restart = $this->phaseRunner->runRestart($site, $shellFactory);
        $aggregate['phases'][SiteDeployStep::PHASE_RESTART] = $restart;
        if ($restart !== []) {
            $deployment->recordPhaseResults(SiteDeployStep::PHASE_RESTART, $restart);
            if (! $this->phaseOk($restart)) {
                return $this->finalize($deployment, $aggregate, ok: false);
            }
        }

        return $this->finalize($deployment, $aggregate, ok: true);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function phaseOk(array $steps): bool
    {
        if ($steps === []) {
            return true;
        }
        foreach ($steps as $step) {
            if (($step['ok'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{ok: bool, phases: array<string, list<array<string, mixed>>>, total_duration_ms: int}  $aggregate
     * @return array{ok: bool, phases: array<string, list<array<string, mixed>>>, total_duration_ms: int}
     */
    private function finalize(SiteDeployment $deployment, array $aggregate, bool $ok): array
    {
        $aggregate['ok'] = $ok;

        $total = 0;
        foreach ($aggregate['phases'] as $steps) {
            foreach ($steps as $step) {
                $total += (int) ($step['duration_ms'] ?? 0);
            }
        }
        $aggregate['total_duration_ms'] = $total;

        $deployment->update([
            'status' => $ok ? SiteDeployment::STATUS_SUCCESS : SiteDeployment::STATUS_FAILED,
            'finished_at' => now(),
        ]);

        return $aggregate;
    }
}
