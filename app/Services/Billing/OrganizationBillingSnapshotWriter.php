<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\OrganizationBillingSnapshot;
use Carbon\CarbonInterface;

final class OrganizationBillingSnapshotWriter
{
    public function __construct(
        private readonly OrganizationBillingStateComputer $billingStateComputer,
    ) {}

    public function writeForOrganization(Organization $organization, ?CarbonInterface $snapshotDate = null): OrganizationBillingSnapshot
    {
        $state = $this->billingStateComputer->compute($organization);
        $date = ($snapshotDate ?? now())->toDateString();

        return OrganizationBillingSnapshot::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'snapshot_date' => $date,
            ],
            [
                'monthly_total_cents' => $state->monthlyTotalCents,
                'category_breakdown' => $this->categoryBreakdown($state),
                'fleet_counts' => [
                    'servers' => $state->serverCount(),
                    'serverless' => $state->serverlessCount,
                    'cloud' => $state->cloudCount,
                    'edge' => $state->edgeCount,
                    'realtime' => $state->realtimeCount,
                ],
                'edge_usage_cents' => $state->edgeUsageSubtotalCents,
                'subscription_interval' => $this->subscriptionInterval($organization),
            ],
        );
    }

    /**
     * @return array<string, int>
     */
    private function categoryBreakdown(DesiredBillingState $state): array
    {
        return [
            'plan_cents' => $state->planPriceCents,
            'serverless_cents' => $state->serverlessSubtotalCents,
            'serverless_usage_cents' => $state->serverlessUsageSubtotalCents,
            'managed_server_cents' => $state->managedServerSubtotalCents,
            'cloud_cents' => $state->cloudSubtotalCents,
            'cloud_resource_cents' => $state->cloudResourceSubtotalCents,
            'edge_cents' => $state->edgeSubtotalCents,
            'edge_usage_cents' => $state->edgeUsageSubtotalCents,
            'realtime_cents' => $state->realtimeSubtotalCents,
        ];
    }

    private function subscriptionInterval(Organization $organization): ?string
    {
        $subscription = $organization->subscription('default');
        if ($subscription === null || ! $subscription->valid()) {
            return null;
        }

        // Any yearly plan or managed-product price means the subscription is
        // billed annually. Mirrors BillingAnalytics::subscriptionInterval.
        $yearlyIds = array_merge(
            array_values((array) config('subscription.standard.stripe.plans_yearly', [])),
            array_values((array) config('subscription.standard.stripe.realtime_tiers_yearly', [])),
            [
                (string) (config('subscription.standard.stripe.serverless_yearly') ?? ''),
                (string) (config('subscription.standard.stripe.cloud_yearly') ?? ''),
                (string) (config('subscription.standard.stripe.edge_yearly') ?? ''),
                (string) (config('subscription.standard.stripe.realtime_yearly') ?? ''),
            ],
        );

        foreach ($yearlyIds as $priceId) {
            $priceId = (string) $priceId;
            if ($priceId !== '' && $subscription->hasPrice($priceId)) {
                return 'year';
            }
        }

        return 'month';
    }
}
