<?php

namespace App\Services\Deploy;

use App\Jobs\RunSiteDeploymentJob;

/**
 * Instructs the atomic deployer to re-attach to an already-staged release
 * directory and continue from a given phase, instead of minting a fresh
 * release and running the full pipeline. Built by {@see RunSiteDeploymentJob}
 * from a prior failed deployment when the operator chooses "Retry from {phase}".
 *
 * Only failures BEFORE the cutover (build / release phases) are resumable: the
 * staged release was never made live, so re-running it touches nothing that's
 * serving. The deployer verifies the folder still exists before reusing it.
 */
final class DeployResumePlan
{
    /**
     * The canonical phase order the atomic deployer runs. A resume executes
     * every phase from {@see $startFromPhase} onward and skips the earlier ones
     * (their results are carried forward onto the new deployment row).
     *
     * @var list<string>
     */
    public const PHASE_ORDER = ['clone', 'env', 'manifest', 'build', 'logging', 'release', 'activate', 'restart'];

    /**
     * Phases a deploy may be resumed FROM:
     *   build / release — PRE-cutover (Tier 1): the staged release was never
     *     made live, so re-running it can't disturb what's serving.
     *   restart — POST-cutover (Tier 2): the symlink already flipped and the new
     *     release is live, but a finishing step failed (the post-deploy command
     *     or a worker restart). Resume re-runs only that post-cutover tail —
     *     it does NOT re-clone, re-build, re-migrate, or re-flip.
     *
     * @var list<string>
     */
    public const RESUMABLE_PHASES = ['build', 'release', 'restart'];

    public function __construct(
        public readonly string $releaseFolder,
        public readonly string $startFromPhase,
    ) {}

    /** True for phases at or after the resume point — i.e. phases that should run. */
    public function shouldRun(string $phase): bool
    {
        $start = array_search($this->startFromPhase, self::PHASE_ORDER, true);
        $index = array_search($phase, self::PHASE_ORDER, true);

        // Unknown phases (not in the order list) always run — they sit past the
        // cutover (prune, dply.yaml sync, …) and must complete on every deploy.
        if ($index === false) {
            return true;
        }

        return $start === false || $index >= $start;
    }
}
