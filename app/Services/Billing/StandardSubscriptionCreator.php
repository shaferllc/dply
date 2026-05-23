<?php

namespace App\Services\Billing;

use App\Enums\ServerTier;
use App\Models\Organization;
use App\Models\Subscription;
use InvalidArgumentException;
use RuntimeException;

/**
 * Provisions a fresh Standard Stripe subscription for an organization,
 * seeded with line items derived from the org's current server fleet.
 *
 * Stripe Checkout requires every line item in a subscription to share a
 * billing interval, so each per-server tier has both a monthly and a yearly
 * Stripe Price. The creator picks the right set based on the chosen interval.
 */
class StandardSubscriptionCreator
{
    public const INTERVAL_MONTH = 'month';

    public const INTERVAL_YEAR = 'year';

    public function __construct(
        private OrganizationBillingStateComputer $computer,
    ) {}

    /**
     * @return array<int, array{price: string, quantity: int}>
     *
     * @throws RuntimeException when the base price for the requested interval is not configured.
     */
    public function buildPriceList(DesiredBillingState $desired, string $interval = self::INTERVAL_MONTH): array
    {
        $basePriceId = $this->basePriceIdForInterval($interval);
        if ($basePriceId === '') {
            throw new RuntimeException("Standard base price for interval '{$interval}' is not configured.");
        }

        $items = [['price' => $basePriceId, 'quantity' => 1]];

        $tierPriceIds = $this->tierPriceIdsForInterval($interval);
        foreach (ServerTier::ordered() as $tier) {
            $qty = $desired->quantityFor($tier);
            if ($qty <= 0) {
                continue;
            }

            $priceId = $tierPriceIds[$tier->value] ?? null;
            if (! is_string($priceId) || $priceId === '') {
                // Tier not yet wired up in Stripe for this interval; skip
                // silently so a half-configured Stripe state doesn't blow up
                // subscription creation.
                continue;
            }

            $items[] = ['price' => $priceId, 'quantity' => $qty];
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

    public function edgeUsagePriceId(): string
    {
        return (string) (config('subscription.standard.stripe.edge_usage') ?? '');
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

        $builder = $organization->newSubscription('default');
        foreach ($items as $item) {
            $builder->price($item['price'], $item['quantity']);
        }

        /** @var Subscription $subscription */
        $subscription = $builder->create($paymentMethodId);

        return $subscription;
    }

    /**
     * @return array<string, string>
     */
    public function tierPriceIdsForInterval(string $interval): array
    {
        return match ($interval) {
            self::INTERVAL_MONTH => (array) config('subscription.standard.stripe.tiers', []),
            self::INTERVAL_YEAR => (array) config('subscription.standard.stripe.tiers_yearly', []),
            default => throw new InvalidArgumentException("Unknown billing interval: {$interval}"),
        };
    }

    private function basePriceIdForInterval(string $interval): string
    {
        return (string) match ($interval) {
            self::INTERVAL_MONTH => config('subscription.standard.stripe.base_monthly') ?? '',
            self::INTERVAL_YEAR => config('subscription.standard.stripe.base_yearly') ?? '',
            default => throw new InvalidArgumentException("Unknown billing interval: {$interval}"),
        };
    }
}
