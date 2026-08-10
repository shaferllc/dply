<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Queue\Support\QueueEntitlement;

/**
 * Converts an org's metered dply Queue volume into customer-facing cents: jobs
 * pushed over the plan's included allowance, billed per million at the plan's
 * overage rate (docs/adr/dply-queue.md, decision 9).
 *
 * The allowance and the rate both come from the org's {@see QueueEntitlement},
 * resolved per plan, so pricing lives in one place — the same shape as
 * {@see ServerLogUsageCostCalculator}, whose staging this follows.
 *
 * Dark by default: `estimate()` returns 0 unless `queue_service.billing.enabled`
 * is on AND the plan carries a non-zero overage rate. Both are off/zero until
 * pricing is calibrated against real metered volume.
 */
class QueueUsageCostCalculator
{
    private const JOBS_PER_UNIT = 1_000_000;

    public function isEnabled(): bool
    {
        return (bool) config('queue_service.billing.enabled', false);
    }

    /**
     * @return array{
     *     subtotal_cents: int,
     *     used_jobs: int,
     *     included_jobs: int,
     *     billable_jobs: int,
     *     overage_per_million_jobs_cents: int,
     * }
     */
    public function estimate(QueueEntitlement $entitlement, int $usedJobs): array
    {
        $usedJobs = max(0, $usedJobs);
        $includedJobs = $entitlement->monthlyIncludedJobs;

        if (! $this->isEnabled()) {
            return $this->emptyEstimate($usedJobs, $includedJobs);
        }

        $billableJobs = max(0, $usedJobs - $includedJobs);
        $rate = max(0, $entitlement->overagePerMillionJobsCents);

        // Rounded up: a partial million of overage still costs us the pushes
        // that made it, and rounding down would bill nothing for anything
        // under the unit.
        $subtotal = ($billableJobs > 0 && $rate > 0)
            ? (int) ceil($billableJobs / self::JOBS_PER_UNIT * $rate)
            : 0;

        return [
            'subtotal_cents' => $subtotal,
            'used_jobs' => $usedJobs,
            'included_jobs' => $includedJobs,
            'billable_jobs' => $billableJobs,
            'overage_per_million_jobs_cents' => $rate,
        ];
    }

    /**
     * Whether the org has burned through its included allowance — what the UI
     * warns on, independent of whether billing is live.
     */
    public function isOverIncluded(QueueEntitlement $entitlement, int $usedJobs): bool
    {
        return $entitlement->monthlyIncludedJobs > 0
            && $usedJobs > $entitlement->monthlyIncludedJobs;
    }

    /**
     * @return array{
     *     subtotal_cents: int,
     *     used_jobs: int,
     *     included_jobs: int,
     *     billable_jobs: int,
     *     overage_per_million_jobs_cents: int,
     * }
     */
    private function emptyEstimate(int $usedJobs, int $includedJobs): array
    {
        return [
            'subtotal_cents' => 0,
            'used_jobs' => $usedJobs,
            'included_jobs' => $includedJobs,
            'billable_jobs' => 0,
            'overage_per_million_jobs_cents' => 0,
        ];
    }
}
