<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

/**
 * Surfaces dply's flat monthly fees for first-party managed products
 * (Cloud, Edge) in create flows — mirrors ServerlessCostEstimator.
 */
class ManagedProductCostEstimator
{
    public function cloudFee(): float
    {
        return ((int) config('subscription.standard.cloud_cents', 0)) / 100;
    }

    /**
     * Customer-facing Cloud pricing terms, derived from the same config the
     * biller reads so the empty-index splash can never quote a price the
     * invoice contradicts. Container and database rates already have
     * `cloud_markup_percent` applied.
     *
     * @return array{
     *     flat_cents: int,
     *     markup_percent: int,
     *     small_container_cents: int,
     *     small_database_cents: int,
     * }
     */
    public function cloudPricingSummary(): array
    {
        $containerRates = (array) config('subscription.standard.cloud_container_cents', []);
        $databaseRates = (array) config('subscription.standard.cloud_database_cents', []);

        return [
            'flat_cents' => (int) config('subscription.standard.cloud_cents', 0),
            'markup_percent' => max(0, (int) config('subscription.standard.cloud_markup_percent', 0)),
            'small_container_cents' => $this->withCloudMarkup((int) ($containerRates['small'] ?? 0)),
            'small_database_cents' => $this->withCloudMarkup((int) ($databaseRates['small'] ?? 0)),
        ];
    }

    /**
     * Customer-facing (marked-up) monthly price in USD for a Cloud container
     * size tier, per instance. Used to preview the metered resource cost in
     * the create flow next to the flat platform fee.
     */
    public function cloudContainerPrice(string $sizeTier): float
    {
        $rates = (array) config('subscription.standard.cloud_container_cents', []);
        $raw = (int) ($rates[$sizeTier] ?? $rates['small'] ?? 0);

        return $this->withCloudMarkup($raw) / 100;
    }

    /**
     * Estimated monthly AWS App Runner compute (USD) for a size tier ×
     * instance count. Customer pays AWS directly — not dply-metered.
     *
     * Floor assumes always-on provisioned compute for the instance count
     * (use autoscaling min when the form has autoscaling enabled).
     *
     * Tier → vCPU/GB mirrors AwsAppRunnerBackend::computeForSizeTier
     * (kept local so Billing does not depend on the Cloud module).
     */
    public function appRunnerMonthlyUsd(string $sizeTier, int $instances = 1): float
    {
        [$vcpu, $memoryGb] = match ($sizeTier) {
            'medium', 'medium-pro' => [0.5, 1.0],
            'large', 'large-pro' => [1.0, 2.0],
            'xlarge', 'xlarge-pro' => [2.0, 4.0],
            default => [0.25, 0.5],
        };
        $hours = max(1, (int) config('subscription.standard.app_runner_hours_per_month', 730));
        $vcpuRate = max(0.0, (float) config('subscription.standard.app_runner_vcpu_usd_per_hour', 0.064));
        $memoryRate = max(0.0, (float) config('subscription.standard.app_runner_memory_gb_usd_per_hour', 0.007));
        $perInstance = ($vcpu * $vcpuRate + $memoryGb * $memoryRate) * $hours;

        return round($perInstance * max(1, $instances), 2);
    }

    /**
     * Customer-facing (marked-up) monthly price in USD for a Cloud managed
     * database size tier.
     */
    public function cloudDatabasePrice(string $sizeTier): float
    {
        $rates = (array) config('subscription.standard.cloud_database_cents', []);
        $raw = (int) ($rates[$sizeTier] ?? $rates['small'] ?? 0);

        return $this->withCloudMarkup($raw) / 100;
    }

    public function cloudBucketPrice(): float
    {
        return $this->withCloudMarkup((int) config('subscription.standard.cloud_bucket_cents', 0)) / 100;
    }

    private function withCloudMarkup(int $rawCents): int
    {
        $markup = max(0, (int) config('subscription.standard.cloud_markup_percent', 0));

        return (int) round($rawCents * (100 + $markup) / 100);
    }

    public function edgeFee(): float
    {
        return ((int) config('subscription.standard.edge_cents', 0)) / 100;
    }

    /** Monthly platform fee (dollars) for Worker-native SSR Edge sites. */
    public function edgeSsrFee(): float
    {
        return ((int) config('subscription.standard.edge_ssr_cents', 0)) / 100;
    }

    /**
     * Platform fee for an Edge runtime mode (static/hybrid → edgeFee, ssr → edgeSsrFee).
     */
    public function edgeFeeForRuntimeMode(string $runtimeMode): float
    {
        return strtolower($runtimeMode) === 'ssr'
            ? $this->edgeSsrFee()
            : $this->edgeFee();
    }

    /**
     * Monthly fee (dollars) for a managed Realtime app on the default tier.
     */
    public function realtimeFee(): float
    {
        return $this->realtimeTierFee((string) config('realtime.default_tier', 'starter'));
    }

    /**
     * Monthly fee (dollars) for a managed Realtime app on a specific tier.
     */
    public function realtimeTierFee(string $tier): float
    {
        $tiers = (array) config('realtime.tiers', []);

        return ((int) ($tiers[$tier]['price_cents'] ?? config('subscription.standard.realtime_cents', 0))) / 100;
    }

    /**
     * Customer-facing Edge usage rates (monthly), with markup baked into
     * the displayed unit prices so create/billing copy matches the invoice.
     *
     * @return array{
     *     requests_per_million: float,
     *     egress_per_gb: float,
     *     storage_per_gb: float,
     *     markup_percent: int,
     *     included_requests_per_site: int,
     *     included_egress_gb_per_site: int,
     *     included_r2_storage_gb_per_site: int,
     * }
     */
    public function edgeUsageRates(): array
    {
        $markup = max(0, (int) config('dply.edge.usage_billing.markup_percent', 0));
        $multiplier = (100 + $markup) / 100;

        return [
            'requests_per_million' => round(((int) config('dply.edge.usage_billing.requests_cents_per_million', 0)) / 100 * $multiplier, 2),
            'egress_per_gb' => round(((int) config('dply.edge.usage_billing.egress_cents_per_gb', 0)) / 100 * $multiplier, 2),
            'storage_per_gb' => round(((int) config('dply.edge.usage_billing.r2_storage_cents_per_gb_month', 0)) / 100 * $multiplier, 2),
            'markup_percent' => $markup,
            'included_requests_per_site' => (int) config('dply.edge.usage_billing.included_requests_per_site', 0),
            'included_egress_gb_per_site' => (int) config('dply.edge.usage_billing.included_egress_gb_per_site', 0),
            'included_r2_storage_gb_per_site' => (int) config('dply.edge.usage_billing.included_r2_storage_gb_per_site', 0),
        ];
    }

    public function edgeUsageBillingEnabled(): bool
    {
        return (bool) config('dply.edge.usage_billing.enabled', false);
    }
}
