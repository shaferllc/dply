<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Models\ServerlessUsageSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Rolls up {@see ServerlessUsageSnapshot} rows for an organization inside a
 * billing window (typically the current calendar month to date). Mirrors
 * {@see EdgeOrganizationUsageReader}.
 */
class ServerlessOrganizationUsageReader
{
    public function totalsForOrganization(
        Organization $organization,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): ServerlessUsageTotals {
        $row = ServerlessUsageSnapshot::query()
            ->where('organization_id', $organization->id)
            ->where('period_start', '>=', $periodStart->toDateString())
            ->where('period_start', '<=', $periodEnd->toDateString())
            ->select([
                DB::raw('COALESCE(SUM(invocations), 0) as invocations'),
                DB::raw('COALESCE(SUM(gib_seconds), 0) as gib_seconds'),
            ])
            ->first();

        if ($row === null) {
            return new ServerlessUsageTotals;
        }

        return new ServerlessUsageTotals(
            invocations: (int) $row->invocations,
            gibSeconds: (int) $row->gib_seconds,
        );
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public function currentMonthWindow(): array
    {
        return [now()->startOfMonth(), now()->endOfDay()];
    }
}
