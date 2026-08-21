<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

/**
 * What the autoscaler gets to look at.
 *
 * Deliberately not CPU or memory. dply owns the store, so it can read the
 * work itself — how much is waiting, how much is in flight, and how long a
 * job actually takes. Sizing from queue pressure is why there is nothing to
 * tune here: CPU-based autoscaling has to be tuned precisely because CPU is a
 * proxy for the thing you actually care about.
 */
final readonly class FleetSignal
{
    public function __construct(
        /** Claimable right now — the backlog that needs workers. */
        public int $pending,
        /** Held under a live reservation: jobs already occupying a worker. */
        public int $reserved,
        /** Workers that exist as far as cost and capacity go. */
        public int $liveWorkers,
        /** Mean seconds a job on this queue takes, as last measured. */
        public float $avgJobSeconds,
    ) {}

    /** Nothing waiting and nothing running: the fleet may sleep. */
    public function isQuiet(): bool
    {
        return $this->pending === 0 && $this->reserved === 0;
    }
}
