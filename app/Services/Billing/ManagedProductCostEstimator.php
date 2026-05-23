<?php

declare(strict_types=1);

namespace App\Services\Billing;

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

    public function edgeFee(): float
    {
        return ((int) config('subscription.standard.edge_cents', 0)) / 100;
    }

    /**
     * Customer-facing unit rates for Edge delivery usage (monthly).
     *
     * @return array<string, float|int>
     */
    public function edgeUsageRates(): array
    {
        return [
            'requests_per_million' => ((int) config('dply.edge.usage_billing.requests_cents_per_million', 0)) / 100,
            'egress_per_gb' => ((int) config('dply.edge.usage_billing.egress_cents_per_gb', 0)) / 100,
            'included_requests_per_site' => (int) config('dply.edge.usage_billing.included_requests_per_site', 0),
            'included_egress_gb_per_site' => (int) config('dply.edge.usage_billing.included_egress_gb_per_site', 0),
        ];
    }

    public function edgeUsageBillingEnabled(): bool
    {
        return (bool) config('dply.edge.usage_billing.enabled', false);
    }
}
