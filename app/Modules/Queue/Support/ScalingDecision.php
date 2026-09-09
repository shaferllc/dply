<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

/**
 * The autoscaler's answer, with the sentence that explains it.
 *
 * `reason` is not decoration. "Why is this fleet running four workers" is the
 * first question anyone asks of an autoscaler, and reconstructing it after the
 * fact from depth history is guesswork — so the tick records its own reasoning
 * as it goes.
 */
final readonly class ScalingDecision
{
    public function __construct(
        public int $desired,
        public int $current,
        public string $reason,
        /** Consecutive quiet ticks, carried forward to damp scale-down. */
        public int $quietTicks = 0,
    ) {}

    public function isChange(): bool
    {
        return $this->desired !== $this->current;
    }

    public function delta(): int
    {
        return $this->desired - $this->current;
    }
}
