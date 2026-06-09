<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Rolls up {@see EdgeUsageSnapshot} rows for an organization inside a billing
 * window (typically the current calendar month to date).
 */
class EdgeOrganizationUsageReader
{
    public function totalsForOrganization(
        Organization $organization,
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
    ): EdgeUsageTotals {
        $row = EdgeUsageSnapshot::query()
            ->where('organization_id', $organization->id)
            ->where('period_start', '>=', $periodStart->toDateString())
            ->where('period_start', '<=', $periodEnd->toDateString())
            ->select([
                DB::raw('COALESCE(SUM(requests), 0) as requests'),
                DB::raw('COALESCE(SUM(bytes_egress), 0) as bytes_egress'),
                DB::raw('COALESCE(MAX(r2_storage_bytes), 0) as r2_storage_bytes'),
                DB::raw('COALESCE(SUM(r2_class_a_ops), 0) as r2_class_a_ops'),
                DB::raw('COALESCE(SUM(r2_class_b_ops), 0) as r2_class_b_ops'),
            ])
            ->first();

        if ($row === null) {
            return new EdgeUsageTotals;
        }

        return new EdgeUsageTotals(
            requests: (int) $row->requests,
            bytesEgress: (int) $row->bytes_egress,
            r2StorageBytes: (int) $row->r2_storage_bytes,
            r2ClassAOps: (int) $row->r2_class_a_ops,
            r2ClassBOps: (int) $row->r2_class_b_ops,
        );
    }

    public function currentMonthWindow(): array
    {
        $start = now()->startOfMonth();
        $end = now()->endOfDay();

        return [$start, $end];
    }
}
