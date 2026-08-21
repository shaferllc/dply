<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

/**
 * Converts measured dply-managed serverless usage into customer-facing cents,
 * applying a per-function included allowance and configurable unit rates
 * (rates embed platform margin). Mirrors {@see EdgeUsageCostCalculator}.
 *
 * Two meters, priced separately because they are separate costs:
 *
 *  - `gib_seconds` — provider compute, DigitalOcean's actual billing unit.
 *    DO has no per-function compute API, so {@see ServerlessUsageCollector}
 *    derives it from dply's own invocation log (duration x action memory).
 *  - `invocations` — dply's per-request log-ingest and storage cost, which the
 *    provider does not charge for and GiB-seconds do not capture.
 *
 * Plus two asset meters, for the front-end build dply stores and delivers on
 * the function's behalf. DigitalOcean Spaces bills exactly two things, so
 * there are exactly two: stored GiB and outbound GiB. Spaces charges no
 * per-operation fee, so unlike {@see EdgeUsageCostCalculator} there is no ops
 * meter — `asset_requests` is recorded by the collector but never priced.
 *
 * The asset allowances are deliberately far above a real Vite bundle (single
 * -digit MB), so an honest site never sees a cent. They exist to bound the
 * tail — a large binary committed under `public/` — not to sell a tier.
 */
class ServerlessUsageCostCalculator
{
    public function isEnabled(): bool
    {
        return (bool) config('dply.serverless.usage_billing.enabled', false);
    }

    /**
     * @return array{
     *     subtotal_cents: int,
     *     billable_invocations: int,
     *     billable_gib_seconds: int,
     *     billable_asset_storage_bytes: int,
     *     billable_asset_bytes_egress: int,
     *     included_invocations: int,
     *     included_gib_seconds: int,
     *     included_asset_storage_bytes: int,
     *     included_asset_bytes_egress: int,
     * }
     */
    public function estimate(ServerlessUsageTotals $usage, int $functionCount): array
    {
        if (! $this->isEnabled() || $functionCount <= 0) {
            return $this->emptyEstimate();
        }

        $includedInvocations = $functionCount * max(0, (int) config('dply.serverless.usage_billing.included_invocations_per_function', 0));
        $includedGibSeconds = $functionCount * max(0, (int) config('dply.serverless.usage_billing.included_gib_seconds_per_function', 0));
        $includedAssetStorage = $functionCount * $this->includedAssetStorageBytesPerFunction();
        $includedAssetEgress = $functionCount * $this->includedAssetEgressBytesPerFunction();

        $billableInvocations = max(0, $usage->invocations - $includedInvocations);
        $billableGibSeconds = max(0, $usage->gibSeconds - $includedGibSeconds);
        $billableAssetStorage = max(0, $usage->assetStorageBytes - $includedAssetStorage);
        $billableAssetEgress = max(0, $usage->assetBytesEgress - $includedAssetEgress);

        $subtotal = $this->invocationsCents($billableInvocations)
            + $this->gibSecondsCents($billableGibSeconds)
            + $this->assetStorageCents($billableAssetStorage)
            + $this->assetEgressCents($billableAssetEgress);
        $subtotal = $this->applyMarkup($subtotal);

        return [
            'subtotal_cents' => max(0, $subtotal),
            'billable_invocations' => $billableInvocations,
            'billable_gib_seconds' => $billableGibSeconds,
            'billable_asset_storage_bytes' => $billableAssetStorage,
            'billable_asset_bytes_egress' => $billableAssetEgress,
            'included_invocations' => $includedInvocations,
            'included_gib_seconds' => $includedGibSeconds,
            'included_asset_storage_bytes' => $includedAssetStorage,
            'included_asset_bytes_egress' => $includedAssetEgress,
        ];
    }

    /**
     * Stored bytes priced per GiB-month. `$billableBytes` is already the
     * window's average level, so this is a straight rate multiply.
     */
    private function assetStorageCents(int $billableBytes): int
    {
        if ($billableBytes <= 0) {
            return 0;
        }

        $rate = max(0, (int) config('dply.serverless.usage_billing.asset_storage_cents_per_gb_month', 0));

        return (int) ceil($billableBytes / 1024 ** 3 * $rate);
    }

    private function assetEgressCents(int $billableBytes): int
    {
        if ($billableBytes <= 0) {
            return 0;
        }

        $rate = max(0, (int) config('dply.serverless.usage_billing.asset_egress_cents_per_gb', 0));

        return (int) ceil($billableBytes / 1024 ** 3 * $rate);
    }

    private function includedAssetStorageBytesPerFunction(): int
    {
        return max(0, (int) config('dply.serverless.usage_billing.included_asset_storage_gb_per_function', 0)) * 1024 ** 3;
    }

    private function includedAssetEgressBytesPerFunction(): int
    {
        return max(0, (int) config('dply.serverless.usage_billing.included_asset_egress_gb_per_function', 0)) * 1024 ** 3;
    }

    private function invocationsCents(int $billableInvocations): int
    {
        if ($billableInvocations <= 0) {
            return 0;
        }

        $rate = max(0, (int) config('dply.serverless.usage_billing.invocations_cents_per_million', 0));

        return (int) ceil($billableInvocations / 1_000_000 * $rate);
    }

    private function gibSecondsCents(int $billableGibSeconds): int
    {
        if ($billableGibSeconds <= 0) {
            return 0;
        }

        $rate = max(0, (int) config('dply.serverless.usage_billing.gib_seconds_cents_per_100k', 0));

        return (int) ceil($billableGibSeconds / 100_000 * $rate);
    }

    private function applyMarkup(int $subtotalCents): int
    {
        if ($subtotalCents <= 0) {
            return 0;
        }

        $markup = max(0, (int) config('dply.serverless.usage_billing.markup_percent', 0));

        return (int) ceil($subtotalCents * (100 + $markup) / 100);
    }

    /**
     * @return array{
     *     subtotal_cents: int,
     *     billable_invocations: int,
     *     billable_gib_seconds: int,
     *     billable_asset_storage_bytes: int,
     *     billable_asset_bytes_egress: int,
     *     included_invocations: int,
     *     included_gib_seconds: int,
     *     included_asset_storage_bytes: int,
     *     included_asset_bytes_egress: int,
     * }
     */
    private function emptyEstimate(): array
    {
        return [
            'subtotal_cents' => 0,
            'billable_invocations' => 0,
            'billable_gib_seconds' => 0,
            'billable_asset_storage_bytes' => 0,
            'billable_asset_bytes_egress' => 0,
            'included_invocations' => 0,
            'included_gib_seconds' => 0,
            'included_asset_storage_bytes' => 0,
            'included_asset_bytes_egress' => 0,
        ];
    }
}
