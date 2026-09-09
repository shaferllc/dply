<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Modules\Queue\Models\QueueUsageDaily;
use Illuminate\Support\Carbon;

/**
 * Month-to-date managed queue usage for an organization.
 *
 * Reads the daily rollup rows rather than the live counters, for the same
 * reason {@see EdgeOrganizationUsageReader} does: an invoice must be able to
 * be recomputed tomorrow and get the same answer, and a Redis counter that
 * expires in 45 days cannot promise that.
 *
 * @see \App\Modules\Queue\Services\FleetUsageMeter for how the rows are written
 */
class QueueFleetUsageReader
{
    /** @return array{0: Carbon, 1: Carbon} */
    public function currentMonthWindow(): array
    {
        $now = now()->utc();

        return [$now->copy()->startOfMonth(), $now];
    }

    /**
     * @return array{flex_mib_seconds: int, pro_mib_seconds: int, operations: int}
     */
    public function totalsForOrganization(Organization $organization, Carbon $from, Carbon $to): array
    {
        $row = QueueUsageDaily::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('
                COALESCE(SUM(flex_mib_seconds), 0) AS flex_mib_seconds,
                COALESCE(SUM(pro_mib_seconds), 0)  AS pro_mib_seconds,
                COALESCE(SUM(operations), 0)       AS operations
            ')
            ->first();

        return [
            'flex_mib_seconds' => (int) ($row->flex_mib_seconds ?? 0),
            'pro_mib_seconds' => (int) ($row->pro_mib_seconds ?? 0),
            'operations' => (int) ($row->operations ?? 0),
        ];
    }
}
