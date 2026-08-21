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
    /**
     * Request-scoped totals keyed by org + period.
     *
     * @var array<string, ServerlessUsageTotals>
     */
    private static array $totalsMemo = [];

    public function totalsForOrganization(
        Organization $organization,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): ServerlessUsageTotals {
        $key = (string) $organization->id
            .'|'.$periodStart->toDateString()
            .'|'.$periodEnd->toDateString();

        if (isset(self::$totalsMemo[$key])) {
            return self::$totalsMemo[$key];
        }

        $row = ServerlessUsageSnapshot::query()
            ->where('organization_id', $organization->id)
            ->where('period_start', '>=', $periodStart->toDateString())
            ->where('period_start', '<=', $periodEnd->toDateString())
            ->select([
                DB::raw('COALESCE(SUM(invocations), 0) as invocations'),
                DB::raw('COALESCE(SUM(gib_seconds), 0) as gib_seconds'),
                // Storage is a level, not a flow. Rows are one per site per
                // day, so a plain SUM would multiply the org's stored bytes by
                // the number of days in the window. Dividing by the number of
                // distinct days gives the average daily total across all sites
                // — which is precisely what a per-GiB-MONTH rate prices, and
                // it prorates correctly for a site added or removed mid-month.
                DB::raw('COALESCE(SUM(asset_storage_bytes), 0) / GREATEST(COUNT(DISTINCT period_start), 1) as asset_storage_bytes'),
                DB::raw('COALESCE(SUM(asset_bytes_egress), 0) as asset_bytes_egress'),
                DB::raw('COALESCE(SUM(asset_requests), 0) as asset_requests'),
            ])
            ->first();

        if ($row === null) {
            return self::$totalsMemo[$key] = new ServerlessUsageTotals;
        }

        return self::$totalsMemo[$key] = new ServerlessUsageTotals(
            invocations: (int) $row->invocations,
            gibSeconds: (int) $row->gib_seconds,
            assetStorageBytes: (int) $row->asset_storage_bytes,
            assetBytesEgress: (int) $row->asset_bytes_egress,
            assetRequests: (int) $row->asset_requests,
        );
    }

    public static function flushMemo(?string $organizationId = null): void
    {
        if ($organizationId === null) {
            self::$totalsMemo = [];

            return;
        }

        foreach (array_keys(self::$totalsMemo) as $key) {
            if (str_starts_with($key, $organizationId.'|')) {
                unset(self::$totalsMemo[$key]);
            }
        }
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public function currentMonthWindow(): array
    {
        return [now()->startOfMonth(), now()->endOfDay()];
    }
}
