<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use Carbon\Carbon;
use Cron\CronExpression;

/**
 * Pure-function health evaluator for a single scheduler heartbeat.
 *
 * Q4 contract:
 *  - Cron-aware threshold: stale once now > expected_next_fire + grace (2× cadence, 90s minimum)
 *  - Single missed window → AMBER
 *  - Three consecutive missed windows → RED
 *  - "waiting for first tick" grace: first WAITING_GRACE_SECONDS after first_seen_at (or after unpause)
 *  - Paused (cron entry exists with enabled=false) → PAUSED, no findings
 *  - No heartbeat row yet → NEVER_SEEN (caller usually doesn't construct this — the absence of a row is the signal)
 *
 * The runner uses the same evaluator on every pass to update
 * consecutive_misses on the heartbeat row; the page uses it inline to render
 * status chips without depending on the runner's most-recent run. Two callers,
 * one truth.
 */
final class SchedulerHealthEvaluator
{
    public const STATE_WAITING = 'waiting';

    public const STATE_HEALTHY = 'healthy';

    public const STATE_AMBER = 'amber';

    public const STATE_RED = 'red';

    public const STATE_PAUSED = 'paused';

    /** Grace window for "waiting for first tick" after Enable / Resume. */
    public const WAITING_GRACE_SECONDS = 180;

    /** Floor on the per-cadence grace for fast-cadence schedulers. */
    public const MIN_GRACE_SECONDS = 90;

    /** Q4 (d): red opens at N consecutive missed windows. */
    public const RED_THRESHOLD_MISSES = 3;

    /**
     * @return self::STATE_*
     */
    public function evaluate(
        ServerSchedulerHeartbeat $heartbeat,
        ?ServerCronJob $cron = null,
        ?Carbon $now = null,
    ): string {
        $now = $now ?? Carbon::now();

        // Paused intent (Q20 (a1)) — cron row's `enabled=false` is the
        // canonical signal. Suppresses all staleness states.
        if ($cron !== null && ! $cron->enabled) {
            return self::STATE_PAUSED;
        }

        // Waiting-for-first-tick grace (Q4 (e)). Applies before any heartbeat
        // has landed, and is re-armed on Resume (the un-pause flow resets
        // first_seen_at).
        $firstSeenAt = $heartbeat->first_seen_at;
        if ($firstSeenAt !== null
            && $heartbeat->last_tick_at === null
            && $firstSeenAt->diffInSeconds($now) < self::WAITING_GRACE_SECONDS
        ) {
            return self::STATE_WAITING;
        }

        $misses = $this->computeMisses($heartbeat, $now);

        // Persisted consecutive_misses gets nudged by the runner; the
        // page's inline evaluation supplements that by also looking at the
        // live time gap so a stale ingest doesn't make a dying scheduler
        // look healthy between runner passes.
        $effectiveMisses = max($heartbeat->consecutive_misses ?? 0, $misses);

        if ($effectiveMisses >= self::RED_THRESHOLD_MISSES) {
            return self::STATE_RED;
        }
        if ($effectiveMisses >= 1) {
            return self::STATE_AMBER;
        }

        return self::STATE_HEALTHY;
    }

    /**
     * Number of full cron-cadence windows that have elapsed since the last
     * recorded tick, without a tick landing. Zero means the scheduler is
     * on-time. >=1 means at least one window has passed.
     *
     * Pure computation against the heartbeat's stored cadence — does not
     * mutate the heartbeat row. Callers (runner, page) decide what to do
     * with the result.
     */
    public function computeMisses(ServerSchedulerHeartbeat $heartbeat, ?Carbon $now = null): int
    {
        $now = $now ?? Carbon::now();

        // No tick ever recorded — the missed-windows logic doesn't apply
        // (it's the waiting/never-seen path). Return 0 here so callers that
        // didn't pre-filter for waiting don't accidentally cascade into AMBER.
        if ($heartbeat->last_tick_at === null) {
            return 0;
        }

        $expression = trim((string) $heartbeat->cron_expression);
        if ($expression === '' || ! CronExpression::isValidExpression($expression)) {
            // Bogus cadence — bail to 0 rather than throw. The runner will
            // see this as healthy; the operator's bad cron expression
            // surfaces elsewhere (e.g. cron-sync errors).
            return 0;
        }

        $cron = new CronExpression($expression);
        $lastTickAt = $heartbeat->last_tick_at->copy();

        // Count expected-fire moments strictly after last_tick_at but no
        // later than (now - grace). If `now - grace` < first expected fire,
        // we're inside the cadence's normal window — no misses.
        $cadenceSeconds = $this->cadenceSeconds($cron, $lastTickAt);
        $graceSeconds = max(self::MIN_GRACE_SECONDS, (int) ($cadenceSeconds * 2));
        $deadline = $now->copy()->subSeconds($graceSeconds);

        if ($deadline->lessThanOrEqualTo($lastTickAt)) {
            return 0;
        }

        // Iterate expected fires after last_tick_at up to deadline. Capped to
        // avoid pathological loops on weird expressions; 100 is well past
        // anything that would fire any sane action (`* * * * *` over ~1.5h).
        $missed = 0;
        $cursor = $lastTickAt;
        for ($i = 0; $i < 100; $i++) {
            try {
                $next = Carbon::instance($cron->getNextRunDate($cursor));
            } catch (\Throwable) {
                break;
            }
            if ($next->greaterThan($deadline)) {
                break;
            }
            $missed++;
            $cursor = $next;
        }

        return $missed;
    }

    /**
     * Estimate the cadence (seconds between consecutive fires) by sampling
     * the next two fire times from $reference. Used to size the grace window:
     * a `* * * * *` scheduler gets a 90-second grace floor; a `0 * * * *`
     * scheduler gets a 2-hour grace.
     */
    private function cadenceSeconds(CronExpression $cron, Carbon $reference): int
    {
        try {
            $first = Carbon::instance($cron->getNextRunDate($reference));
            $second = Carbon::instance($cron->getNextRunDate($first));
        } catch (\Throwable) {
            return self::MIN_GRACE_SECONDS;
        }

        $delta = $second->getTimestamp() - $first->getTimestamp();

        return $delta > 0 ? $delta : self::MIN_GRACE_SECONDS;
    }
}
