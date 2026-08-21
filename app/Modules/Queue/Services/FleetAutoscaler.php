<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Support\FleetSignal;
use App\Modules\Queue\Support\ScalingDecision;

/**
 * Decides how many workers a fleet should be running.
 *
 * Pure: signal in, decision out, no IO. Everything that makes autoscaling
 * hard to trust — flapping, over-provisioning a one-job burst, tearing a
 * worker down two seconds before the next job arrives — is a property of this
 * arithmetic, and arithmetic can be tested exhaustively in milliseconds.
 * {@see FleetReconciler} does the IO.
 *
 * The rule, from docs/adr/managed-queue-workers.md decision 4:
 *
 *     desired = clamp(max(reserved, ceil(pending * avg / target_drain)), min, max)
 *
 * Read it as: run enough workers to clear what is waiting inside
 * `target_drain` seconds, and never fewer than the number of jobs already in
 * flight, because each of those is holding a worker right now.
 */
class FleetAutoscaler
{
    /**
     * How quickly a visible backlog should be absorbed. Lower means more
     * workers sooner and a bigger bill; higher means jobs wait. Twenty
     * seconds keeps "dispatched a job, watched it run" true for a human.
     */
    private const DEFAULT_TARGET_DRAIN_SECONDS = 20;

    /**
     * Used until this queue has run enough jobs to have measured itself. Half
     * a second is the shape of a typical Laravel job (an email, a webhook),
     * and being wrong here is self-correcting after the first real sample.
     */
    private const DEFAULT_JOB_SECONDS = 0.5;

    /**
     * Quiet ticks required before dropping the last workers.
     *
     * Scale-up is immediate; scale-down is not. A worker torn down during a
     * lull is re-created seconds later and both transitions are billed, so
     * the asymmetry is deliberate: being slightly too big costs pennies,
     * being too small costs latency on every job in the backlog.
     */
    private const QUIET_TICKS_BEFORE_SLEEP = 2;

    public function decide(ManagedQueueFleet $fleet, FleetSignal $signal): ScalingDecision
    {
        $floor = $fleet->scalingFloor();
        $ceiling = $fleet->scalingCeiling();
        $current = $signal->liveWorkers;

        if ($fleet->status === ManagedQueueFleet::STATUS_PAUSED) {
            return new ScalingDecision(0, $current, 'fleet is paused — winding workers down to zero');
        }

        // Quiet: everything drained. Hold the floor, but only step down to it
        // after the queue has stayed empty for long enough that this is a lull
        // rather than the gap between two jobs.
        if ($signal->isQuiet()) {
            $quiet = $signal->liveWorkers > $floor
                ? min(self::QUIET_TICKS_BEFORE_SLEEP, $fleet->quiet_ticks + 1)
                : 0;

            if ($quiet < self::QUIET_TICKS_BEFORE_SLEEP && $current > $floor) {
                return new ScalingDecision(
                    $current,
                    $current,
                    sprintf('queue is empty, holding %d worker(s) for %d more tick(s)', $current, self::QUIET_TICKS_BEFORE_SLEEP - $quiet),
                    $quiet,
                );
            }

            return new ScalingDecision(
                $floor,
                $current,
                $floor === 0 ? 'queue is empty — sleeping at zero' : sprintf('queue is empty — holding the floor of %d', $floor),
                $quiet,
            );
        }

        $jobSeconds = $signal->avgJobSeconds > 0 ? $signal->avgJobSeconds : self::DEFAULT_JOB_SECONDS;
        $target = max(1, self::targetDrainSeconds());

        // The backlog expressed as work, not as a job count: a thousand jobs
        // that take a millisecond each need one worker, not a thousand.
        $forBacklog = (int) ceil(($signal->pending * $jobSeconds) / $target);

        // In-flight jobs are already holding workers. Sizing below this would
        // mean deciding to stop a worker mid-job every tick.
        $desired = max($signal->reserved, $forBacklog);
        $desired = max($floor, min($ceiling, $desired));

        // A queue with work but no worker is the case that must never persist,
        // and it is reachable whenever the arithmetic rounds to zero.
        if ($desired === 0 && $signal->pending > 0) {
            $desired = min(1, $ceiling);
        }

        return new ScalingDecision($desired, $current, $this->explain($signal, $desired, $forBacklog, $jobSeconds, $target, $floor, $ceiling));
    }

    /**
     * Whether a push should start a worker immediately instead of waiting for
     * the next tick.
     *
     * The tick is a one-minute cron; an idle flex fleet would otherwise make
     * the first job of the day wait up to a minute, which is the entire
     * difference between "scales to zero" and "is asleep".
     */
    public function shouldWake(ManagedQueueFleet $fleet, int $liveWorkers): bool
    {
        return $fleet->status === ManagedQueueFleet::STATUS_ACTIVE
            && $liveWorkers === 0
            && $fleet->scalingCeiling() > 0;
    }

    private static function targetDrainSeconds(): int
    {
        return (int) config('queue_service.fleets.target_drain_seconds', self::DEFAULT_TARGET_DRAIN_SECONDS);
    }

    private function explain(FleetSignal $signal, int $desired, int $forBacklog, float $jobSeconds, int $target, int $floor, int $ceiling): string
    {
        if ($desired === $ceiling && $forBacklog > $ceiling) {
            return sprintf(
                '%d pending at ~%.2fs each needs %d workers to drain in %ds — capped at the maximum of %d',
                $signal->pending, $jobSeconds, $forBacklog, $target, $ceiling,
            );
        }

        if ($desired === $floor && $forBacklog < $floor) {
            return sprintf('%d pending needs %d worker(s), holding the floor of %d', $signal->pending, $forBacklog, $floor);
        }

        if ($signal->reserved > $forBacklog) {
            return sprintf('%d job(s) in flight, each holding a worker', $signal->reserved);
        }

        return sprintf(
            '%d pending at ~%.2fs each — %d worker(s) drains it in %ds',
            $signal->pending, $jobSeconds, $desired, $target,
        );
    }
}
