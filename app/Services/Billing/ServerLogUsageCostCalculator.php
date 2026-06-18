<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Modules\Logs\Services\ServerLogEntitlement;

/**
 * Converts an org's metered dply Logs ingest volume into customer-facing cents:
 * bytes over the plan's included allowance, billed per GB at the plan's overage
 * rate. The included allowance + rate come from the org's
 * {@see ServerLogEntitlement} (resolved per plan), so pricing lives in one place.
 *
 * Dark by default: `estimate()` returns 0 unless `server_logs.billing.enabled` is
 * on AND the plan carries a non-zero `overage_per_gb_cents` — both are off/zero
 * until pricing is calibrated (docs/SERVER_LOGS_BILLING.md §1.3 / "Open quantities").
 */
class ServerLogUsageCostCalculator
{
    private const BYTES_PER_GB = 1073741824; // 1024^3

    public function isEnabled(): bool
    {
        return (bool) config('server_logs.billing.enabled', false);
    }

    /**
     * @return array{
     *     subtotal_cents: int,
     *     used_bytes: int,
     *     included_bytes: int,
     *     billable_bytes: int,
     *     overage_per_gb_cents: int,
     * }
     */
    public function estimate(ServerLogEntitlement $entitlement, int $usedBytes): array
    {
        $usedBytes = max(0, $usedBytes);
        $includedBytes = $entitlement->includedBytes();

        if (! $this->isEnabled()) {
            return $this->emptyEstimate($usedBytes, $includedBytes);
        }

        $billableBytes = max(0, $usedBytes - $includedBytes);
        $rate = max(0, $entitlement->overagePerGbCents);

        $subtotal = ($billableBytes > 0 && $rate > 0)
            ? (int) ceil($billableBytes / self::BYTES_PER_GB * $rate)
            : 0;

        return [
            'subtotal_cents' => $subtotal,
            'used_bytes' => $usedBytes,
            'included_bytes' => $includedBytes,
            'billable_bytes' => $billableBytes,
            'overage_per_gb_cents' => $rate,
        ];
    }

    /**
     * @return array{
     *     subtotal_cents: int,
     *     used_bytes: int,
     *     included_bytes: int,
     *     billable_bytes: int,
     *     overage_per_gb_cents: int,
     * }
     */
    private function emptyEstimate(int $usedBytes, int $includedBytes): array
    {
        return [
            'subtotal_cents' => 0,
            'used_bytes' => $usedBytes,
            'included_bytes' => $includedBytes,
            'billable_bytes' => 0,
            'overage_per_gb_cents' => 0,
        ];
    }
}
