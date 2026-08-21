<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\ServerlessUsageSnapshot;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Evaluates one site's asset usage against its monthly allowances.
 *
 * The caps are the billed allowances themselves, so `warn` means "you are
 * approaching the point where this starts costing something" and `over` means
 * "it now does". Reading the allowance rather than a separate cap setting
 * keeps the warning and the invoice from ever disagreeing.
 *
 * Storage is compared as a LEVEL: rows are one per day, so the window's
 * average is the meaningful figure, matching how
 * {@see \App\Modules\Billing\Services\ServerlessOrganizationUsageReader}
 * aggregates it for billing. Egress is a flow and simply sums.
 *
 * Pure read — persistence is the caller's job, so the cron can spot a
 * transition before overwriting the previous state.
 */
final class ServerlessAssetGuardrail
{
    /**
     * @param  array<string, mixed>|null  $configOverride  optional override for tests
     */
    public function __construct(private readonly ?array $configOverride = null) {}

    public function evaluate(Site $site, ?Carbon $now = null): ServerlessAssetGuardrailStatus
    {
        $config = $this->configOverride ?? (array) config('dply.serverless.usage_billing', []);

        $storageCap = max(0, (int) ($config['included_asset_storage_gb_per_function'] ?? 0)) * 1024 ** 3;
        $egressCap = max(0, (int) ($config['included_asset_egress_gb_per_function'] ?? 0)) * 1024 ** 3;
        $warnAt = max(1, min(99, (int) config('serverless.assets.warn_at_percent', 80)));

        $now ??= now();
        $periodStart = $now->copy()->utc()->startOfMonth();
        $periodEnd = $now->copy()->utc()->endOfMonth();

        $totals = ServerlessUsageSnapshot::query()
            ->where('site_id', $site->id)
            ->where('period_start', '>=', $periodStart->toDateString())
            ->where('period_start', '<=', $periodEnd->toDateString())
            ->selectRaw(
                'COALESCE(SUM(asset_storage_bytes), 0) / GREATEST(COUNT(DISTINCT period_start), 1) AS storage_bytes,'
                .' COALESCE(SUM(asset_bytes_egress), 0) AS bytes_egress'
            )
            ->first();

        $storageBytes = (int) ($totals->storage_bytes ?? 0);
        $bytesEgress = (int) ($totals->bytes_egress ?? 0);

        return new ServerlessAssetGuardrailStatus(
            state: $this->resolveState($storageBytes, $storageCap, $bytesEgress, $egressCap, $warnAt),
            storageBytes: $storageBytes,
            bytesEgress: $bytesEgress,
            storageBytesCap: $storageCap,
            bytesEgressCap: $egressCap,
            warnAtPercent: $warnAt,
            evaluatedAt: new \DateTimeImmutable($now->copy()->utc()->toIso8601String()),
            periodStart: new \DateTimeImmutable($periodStart->toDateString()),
            periodEnd: new \DateTimeImmutable($periodEnd->toDateString()),
        );
    }

    private function resolveState(
        int $storageBytes,
        int $storageCap,
        int $bytesEgress,
        int $egressCap,
        int $warnAt,
    ): string {
        $storagePct = $storageCap > 0 ? ($storageBytes / $storageCap) * 100 : 0;
        $egressPct = $egressCap > 0 ? ($bytesEgress / $egressCap) * 100 : 0;
        $worst = max($storagePct, $egressPct);

        if ($worst >= 100) {
            return ServerlessAssetGuardrailStatus::STATE_OVER;
        }

        if ($worst >= $warnAt) {
            return ServerlessAssetGuardrailStatus::STATE_WARN;
        }

        return ServerlessAssetGuardrailStatus::STATE_OK;
    }
}
