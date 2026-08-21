<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

/**
 * A site's standing against its monthly asset allowances.
 *
 *   ok    – under the warn threshold on both meters
 *   warn  – at or past the warn threshold, still under the allowance
 *   over  – at or past the allowance on either meter
 *
 * Advisory for egress by design. Storage is bounded at deploy time — a build
 * over the hard cap is refused before it uploads — but delivery is never cut
 * off: rate-limiting a paying customer's stylesheet breaks their site in front
 * of their users, which is a far worse outcome than an overage line on an
 * invoice. `over` therefore means "bill the overage and make it visible", not
 * "stop serving".
 *
 * Mirrors {@see \App\Modules\Edge\Services\EdgeGuardrailStatus}.
 */
final class ServerlessAssetGuardrailStatus
{
    public const STATE_OK = 'ok';

    public const STATE_WARN = 'warn';

    public const STATE_OVER = 'over';

    public function __construct(
        public readonly string $state,
        public readonly int $storageBytes,
        public readonly int $bytesEgress,
        public readonly int $storageBytesCap,
        public readonly int $bytesEgressCap,
        public readonly int $warnAtPercent,
        public readonly \DateTimeImmutable $evaluatedAt,
        public readonly \DateTimeImmutable $periodStart,
        public readonly \DateTimeImmutable $periodEnd,
    ) {}

    public function storagePercent(): int
    {
        return $this->storageBytesCap > 0
            ? (int) min(999, round(($this->storageBytes / $this->storageBytesCap) * 100))
            : 0;
    }

    public function egressPercent(): int
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
     * Shape for stashing on the site so the workspace and transition detection
     * can read it back without recomputing.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'state' => $this->state,
            'storage_bytes' => $this->storageBytes,
            'bytes_egress' => $this->bytesEgress,
            'storage_bytes_cap' => $this->storageBytesCap,
            'bytes_egress_cap' => $this->bytesEgressCap,
            'storage_percent' => $this->storagePercent(),
            'egress_percent' => $this->egressPercent(),
            'warn_at_percent' => $this->warnAtPercent,
            'evaluated_at' => $this->evaluatedAt->format(\DateTimeInterface::ATOM),
            'period_start' => $this->periodStart->format('Y-m-d'),
            'period_end' => $this->periodEnd->format('Y-m-d'),
        ];
    }
}
