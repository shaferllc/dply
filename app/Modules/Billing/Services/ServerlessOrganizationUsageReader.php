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
            ])
            ->first();

        if ($row === null) {
            return self::$totalsMemo[$key] = new ServerlessUsageTotals;
        }

        return self::$totalsMemo[$key] = new ServerlessUsageTotals(
            invocations: (int) $row->invocations,
            gibSeconds: (int) $row->gib_seconds,
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
