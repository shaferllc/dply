<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\OrganizationBillingSnapshot;
use Carbon\CarbonInterface;

final class BillingForecastCalculator
{
    /**
     * @return array<string, int|null|string>
     */
    public function calculate(
        DesiredBillingState $state,
        ?string $subscriptionInterval = null,
        ?OrganizationBillingSnapshot $snapshotThirtyDaysAgo = null,
        ?CarbonInterface $asOf = null,
    ): array {
        $asOfDate = $asOf ?? now();
        $monthlyTotalCents = $state->monthlyTotalCents;
        $edgeUsageCents = max(0, $state->edgeUsageSubtotalCents);
        $fixedCents = max(0, $monthlyTotalCents - $edgeUsageCents);

        $daysInMonth = max(1, $asOfDate->daysInMonth);
        $dayOfMonth = max(1, $asOfDate->day);
        $projectedEdgeUsageCents = (int) round(($edgeUsageCents / $dayOfMonth) * $daysInMonth);
        $projectedMonthEndCents = $fixedCents + $projectedEdgeUsageCents;

        $normalizedMrrCents = $this->normalizedMrr($monthlyTotalCents, $subscriptionInterval);
        $arrCents = $normalizedMrrCents * 12;

        $baselineCents = $snapshotThirtyDaysAgo?->monthly_total_cents;
        $deltaVsThirtyDaysCents = is_int($baselineCents)
            ? $monthlyTotalCents - $baselineCents
            : null;

        return [
            'subscription_interval' => $subscriptionInterval,
            'mrr_cents' => $normalizedMrrCents,
            'arr_cents' => $arrCents,
            'fixed_cents' => $fixedCents,
            'edge_usage_mtd_cents' => $edgeUsageCents,
            'projected_edge_usage_cents' => $projectedEdgeUsageCents,
            'projected_month_end_cents' => $projectedMonthEndCents,
            'thirty_day_baseline_cents' => $baselineCents,
            'delta_vs_thirty_days_cents' => $deltaVsThirtyDaysCents,
        ];
    }

    private function normalizedMrr(int $monthlyTotalCents, ?string $subscriptionInterval): int
    {
        if ($subscriptionInterval !== 'year') {
            return $monthlyTotalCents;
        }

        $annualDiscountPct = (int) config('subscription.standard.annual_discount_pct', 20);
        $annualCents = (int) round($monthlyTotalCents * 12 * (100 - $annualDiscountPct) / 100);

        return (int) round($annualCents / 12);
    }
}
