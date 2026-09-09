<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

/**
 * Depth of a namespace's queue, split the way Laravel's Queue contract asks
 * for it (`pendingSize` / `delayedSize` / `reservedSize`).
 *
 * One honest imprecision, inherited from the single-`visible_at` model and
 * shared with SQS: a job whose lease has expired but which nothing has
 * re-claimed yet still counts as `reserved`, even though the next claim will
 * take it. Reporting it as pending would require the same non-indexable
 * disjunction the model exists to avoid, and the number is only ever an
 * observation — nothing branches on it.
 */
final readonly class QueueDepth
{
    public function __construct(
        /** Claimable right now. */
        public int $pending = 0,
        /** Pushed with a delay that has not elapsed. */
        public int $delayed = 0,
        /** Held under a live reservation. */
        public int $reserved = 0,
    ) {}

    public function total(): int
    {
        return $this->pending + $this->delayed + $this->reserved;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'pending' => $this->pending,
            'delayed' => $this->delayed,
            'reserved' => $this->reserved,
            'total' => $this->total(),
        ];
    }
}
