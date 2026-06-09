<?php

declare(strict_types=1);

namespace App\Services\Edge;

/**
 * Snapshot of a site's standing against the monthly request + egress caps.
 *
 *   ok    – under the warn threshold on both metrics
 *   warn  – ≥ warn threshold but < 100% on either metric
 *   over  – ≥ 100% on either metric
 *
 * The dominant `state` is the worst of the two metrics. `meta()` returns an
 * array shape suitable for stashing on Site::edgeMeta['guardrail'] so the
 * UI + transition detection can read it without recomputing.
 */
final class EdgeGuardrailStatus
{
    public const STATE_OK = 'ok';

    public const STATE_WARN = 'warn';

    public const STATE_OVER = 'over';

    public function __construct(
        public readonly string $state,
        public readonly int $requests,
        public readonly int $bytesEgress,
        public readonly int $requestsCap,
        public readonly int $bytesEgressCap,
        public readonly int $warnAtPercent,
        public readonly \DateTimeImmutable $evaluatedAt,
        public readonly \DateTimeImmutable $periodStart,
        public readonly \DateTimeImmutable $periodEnd,
    ) {}

    public function requestsPercent(): int
    {
        return $this->requestsCap > 0
            ? (int) min(999, round(($this->requests / $this->requestsCap) * 100))
            : 0;
    }

    public function bytesPercent(): int
    {
        return $this->bytesEgressCap > 0
            ? (int) min(999, round(($this->bytesEgress / $this->bytesEgressCap) * 100))
            : 0;
    }

    public function isOk(): bool
    {
        return $this->state === self::STATE_OK;
    }

    public function isOver(): bool
    {
        return $this->state === self::STATE_OVER;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'state' => $this->state,
            'requests' => $this->requests,
            'bytes_egress' => $this->bytesEgress,
            'requests_cap' => $this->requestsCap,
            'bytes_egress_cap' => $this->bytesEgressCap,
            'requests_percent' => $this->requestsPercent(),
            'bytes_percent' => $this->bytesPercent(),
            'warn_at_percent' => $this->warnAtPercent,
            'evaluated_at' => $this->evaluatedAt->format(\DateTimeImmutable::ATOM),
            'period_start' => $this->periodStart->format('Y-m-d'),
            'period_end' => $this->periodEnd->format('Y-m-d'),
        ];
    }
}
