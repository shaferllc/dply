<?php

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Modules\Billing\Models\Subscription;
use InvalidArgumentException;
use RuntimeException;

/**
 * Provisions a fresh Standard Stripe subscription for an organization,
 * seeded with line items derived from the org's current fleet.
 *
 * Line items under the plan model:
 * - One **flat plan** price (Starter / Pro / Business), chosen by billable
 *   server count. The Free plan has no Stripe price, so a Free-plan org never
 *   contributes a plan line.
 * - One line per **managed product** in use (serverless / Cloud / Edge), each
 *   billed a la carte per unit on top of the plan — including for Free orgs.
 * - A metered **Edge usage** line (monthly only).
 *
 * Stripe Checkout requires every line item in a subscription to share a
 * billing interval, so each priced item has both a monthly and a yearly
 * Stripe Price. The creator picks the right set based on the chosen interval.
 */
class StandardSubscriptionCreator
{
    public const INTERVAL_MONTH = 'month';

    public const INTERVAL_YEAR = 'year';

    public function __construct(
        private OrganizationBillingStateComputer $computer,
        private SubscriptionPlanResolver $planResolver,
    ) {}

    /**
     * @return array<int, array{price: string, quantity: int}>
     *
     * @throws RuntimeException when a paid plan's Stripe price is not configured.
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, int<1, max>|string>>
     */
    public function buildPriceList(DesiredBillingState $desired, string $interval = self::INTERVAL_MONTH): array
    {
        $items = [];

        // Flat plan line. The Free plan ($0) carries no Stripe price, so it
        // contributes no line — managed-product lines below keep the
        // subscription non-empty when a Free org still owes for managed units.
        if ($desired->planPriceCents > 0) {
            $planPriceId = $this->planResolver->stripePriceId($desired->planKey, $interval);
            if ($planPriceId === '') {
                throw new RuntimeException(
                    "Standard plan '{$desired->planKey}' price for interval '{$interval}' is not configured."
                );
            }

            $items[] = ['price' => $planPriceId, 'quantity' => 1];
        }

        if ($desired->serverlessCount > 0) {
            $serverlessPriceId = $this->managedProductPriceIdForInterval('serverless', $interval);
            if ($serverlessPriceId !== '') {
                $items[] = ['price' => $serverlessPriceId, 'quantity' => $desired->serverlessCount];
            }
        }

        if ($desired->cloudCount > 0) {
            $cloudPriceId = $this->managedProductPriceIdForInterval('cloud', $interval);
            if ($cloudPriceId !== '') {
                $items[] = ['price' => $cloudPriceId, 'quantity' => $desired->cloudCount];
            }
        }

        if ($desired->edgeCount > 0) {
            $edgePriceId = $this->managedProductPriceIdForInterval('edge', $interval);
            if ($edgePriceId !== '') {
                $items[] = ['price' => $edgePriceId, 'quantity' => $desired->edgeCount];
            }
        }

        // Managed Realtime — one line per connection-tier in use.
        foreach ($desired->realtimeTierQuantities as $tier => $quantity) {
            if ($quantity <= 0) {
                continue;
            }
            $realtimePriceId = $this->realtimeTierPriceIdForInterval((string) $tier, $interval);
            if ($realtimePriceId !== '') {
                $items[] = ['price' => $realtimePriceId, 'quantity' => $quantity];
            }
        }

        if ($interval === self::INTERVAL_MONTH && $desired->cloudResourceSubtotalCents > 0) {
            $cloudUsagePriceId = $this->cloudUsagePriceId();
            if ($cloudUsagePriceId !== '') {
                $items[] = ['price' => $cloudUsagePriceId, 'quantity' => $desired->cloudResourceSubtotalCents];
            }
        }

        if ($interval === self::INTERVAL_MONTH && $desired->serverlessUsageSubtotalCents > 0) {
            $serverlessUsagePriceId = $this->serverlessUsagePriceId();
            if ($serverlessUsagePriceId !== '') {
                $items[] = ['price' => $serverlessUsagePriceId, 'quantity' => $desired->serverlessUsageSubtotalCents];
            }
        }

        if ($interval === self::INTERVAL_MONTH && $desired->managedServerSubtotalCents > 0) {
            $managedServerPriceId = $this->managedServerPriceId();
            if ($managedServerPriceId !== '') {
                $items[] = ['price' => $managedServerPriceId, 'quantity' => $desired->managedServerSubtotalCents];
            }
        }

        if ($interval === self::INTERVAL_MONTH && $desired->edgeUsageSubtotalCents > 0) {
            $usagePriceId = $this->edgeUsagePriceId();
            if ($usagePriceId !== '') {
                $items[] = ['price' => $usagePriceId, 'quantity' => $desired->edgeUsageSubtotalCents];
            }
        }

        return $items;
    }

    public function serverlessPriceIdForInterval(string $interval): string
    {
        return $this->managedProductPriceIdForInterval('serverless', $interval);
    }

    public function cloudPriceIdForInterval(string $interval): string
    {
        return $this->managedProductPriceIdForInterval('cloud', $interval);
    }

    public function edgePriceIdForInterval(string $interval): string
    {
        return $this->managedProductPriceIdForInterval('edge', $interval);
    }

    public function realtimePriceIdForInterval(string $interval): string
    {
        return $this->managedProductPriceIdForInterval('realtime', $interval);
    }

    public function realtimeTierPriceIdForInterval(string $tier, string $interval): string
    {
        $bucket = match ($interval) {
            self::INTERVAL_MONTH => 'realtime_tiers',
            self::INTERVAL_YEAR => 'realtime_tiers_yearly',
            default => throw new InvalidArgumentException("Unknown billing interval: {$interval}"),
        };

        return (string) (config('subscription.standard.stripe.'.$bucket.'.'.$tier) ?? '');
    }

    public function edgeUsagePriceId(): string
    {
        return (string) (config('subscription.standard.stripe.edge_usage') ?? '');
    }

    public function cloudUsagePriceId(): string
    {
        return (string) (config('subscription.standard.stripe.cloud_usage') ?? '');
    }

    public function serverlessUsagePriceId(): string
    {
        return (string) (config('subscription.standard.stripe.serverless_usage') ?? '');
    }

    public function managedServerPriceId(): string
    {
        return (string) (config('subscription.standard.stripe.managed_server') ?? '');
    }

    private function managedProductPriceIdForInterval(string $product, string $interval): string
    {
        return (string) match ($interval) {
            self::INTERVAL_MONTH => config('subscription.standard.stripe.'.$product) ?? '',
            self::INTERVAL_YEAR => config('subscription.standard.stripe.'.$product.'_yearly') ?? '',
            default => throw new InvalidArgumentException("Unknown billing interval: {$interval}"),
        };
    }

    /**
     * Create the Cashier subscription. The org must not already have a 'default'
     * subscription — call {@see Organization::subscription('default')} to gate
     * upstream and decide whether to update vs create.
     *
     * @throws InvalidArgumentException when the org already has a subscription.
     */
    public function create(Organization $organization, string $paymentMethodId, string $interval = self::INTERVAL_MONTH): Subscription
    {
        if ($organization->subscription('default') !== null) {
            throw new InvalidArgumentException(
                "Organization {$organization->id} already has a 'default' subscription; update it instead of creating."
            );
        }

        $desired = $this->computer->compute($organization);
        $items = $this->buildPriceList($desired, $interval);

        if ($items === []) {
            // A Free-plan org with no managed products owes nothing — Stripe
            // rejects empty subscriptions, so there is nothing to create.
            throw new RuntimeException(
                "Organization {$organization->id} has no billable units; no subscription to create."
            );
        }

        $builder = $organization->newSubscription('default');
        foreach ($items as $item) {
            $builder->price($item['price'], $item['quantity']);
        }

        /** @var Subscription $subscription */
        $subscription = $builder->create($paymentMethodId);

        return $subscription;
    }
}
