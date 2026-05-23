<?php

namespace App\Services\Billing;

use App\Enums\ServerTier;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use Throwable;

/**
 * Reconciles an organization's Stripe subscription line items against a
 * {@see DesiredBillingState}. Maintains one line item per per-server tier:
 * adds the price when a tier first appears in the fleet, updates quantity
 * when the count changes, removes the price when the tier empties out.
 *
 * Safe to invoke when Stripe is not configured — missing price IDs cause
 * the corresponding tier to be skipped silently. Safe to invoke against
 * an org without a subscription — returns immediately without error so
 * dply-trial orgs (no Stripe sub yet) flow through without special-casing.
 */
class StripeSubscriptionSyncer
{
    /**
     * @return array<int, array{tier: string, action: string, from: ?int, to: int}>
     *                                                                              Audit log of changes applied; empty when nothing changed.
     */
    public function reconcile(Organization $organization, DesiredBillingState $desired): array
    {
        $subscription = $organization->subscription('default');
        if (! $subscription || ! $subscription->valid()) {
            return [];
        }

        $tierPriceIds = $this->tierPriceIdsForSubscription($subscription);
        $changes = [];

        foreach (ServerTier::ordered() as $tier) {
            $priceId = $tierPriceIds[$tier->value] ?? null;
            if (! is_string($priceId) || $priceId === '') {
                continue;
            }

            $desiredQty = $desired->quantityFor($tier);
            $currentQty = $this->currentQuantity($subscription, $priceId);

            $change = $this->applyDelta($subscription, $priceId, $currentQty, $desiredQty);
            if ($change !== null) {
                $changes[] = ['tier' => $tier->value] + $change;
            }
        }

        // Serverless functions — flat per-function line item.
        $this->reconcileManagedProductLine($subscription, $desired, $changes, 'serverless', $desired->serverlessCount);

        // dply Cloud + Edge — flat per live site.
        $this->reconcileManagedProductLine($subscription, $desired, $changes, 'cloud', $desired->cloudCount);
        $this->reconcileManagedProductLine($subscription, $desired, $changes, 'edge', $desired->edgeCount);
        $this->reconcileEdgeUsageLine($subscription, $desired, $changes);

        if ($changes !== []) {
            Log::info('billing.stripe.subscription_synced', [
                'organization_id' => $organization->id,
                'changes' => $changes,
                'monthly_total_cents' => $desired->monthlyTotalCents,
            ]);
        }

        return $changes;
    }

    /**
     * @return array{action: string, from: ?int, to: int}|null
     */
    private function applyDelta(Subscription $subscription, string $priceId, ?int $currentQty, int $desiredQty): ?array
    {
        try {
            // `alwaysInvoice` => Stripe immediately bills the prorated amount
            // for the change rather than accumulating it for the next renewal.
            // Customers see "you added a server, here's the prorated charge"
            // same-day, which is especially important for yearly subscriptions
            // where renewals are far apart.
            if ($currentQty === null && $desiredQty > 0) {
                $subscription->alwaysInvoice()->addPrice($priceId, $desiredQty);

                return ['action' => 'add', 'from' => null, 'to' => $desiredQty];
            }

            if ($currentQty !== null && $desiredQty === 0) {
                $subscription->alwaysInvoice()->removePrice($priceId);

                return ['action' => 'remove', 'from' => $currentQty, 'to' => 0];
            }

            if ($currentQty !== null && $currentQty !== $desiredQty) {
                $subscription->alwaysInvoice()->updateQuantity($desiredQty, $priceId);

                return ['action' => 'update', 'from' => $currentQty, 'to' => $desiredQty];
            }
        } catch (Throwable $e) {
            Log::warning('billing.stripe.line_item_sync_failed', [
                'price_id' => $priceId,
                'error' => $e->getMessage(),
                'from' => $currentQty,
                'to' => $desiredQty,
            ]);
            throw $e;
        }

        return null;
    }

    private function currentQuantity(Subscription $subscription, string $priceId): ?int
    {
        if (! $subscription->hasPrice($priceId)) {
            return null;
        }

        $item = $subscription->items->firstWhere('stripe_price', $priceId);

        return $item ? (int) $item->quantity : null;
    }

    /**
     * Pick the monthly or yearly tier price set based on which base price the
     * subscription is on. Stripe Checkout requires all line items to share an
     * interval, so the tier set must match the base interval the subscription
     * was created with.
     *
     * @return array<string, string>
     */
    private function tierPriceIdsForSubscription(Subscription $subscription): array
    {
        return $this->isYearly($subscription)
            ? (array) config('subscription.standard.stripe.tiers_yearly', [])
            : (array) config('subscription.standard.stripe.tiers', []);
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function reconcileManagedProductLine(
        Subscription $subscription,
        DesiredBillingState $desired,
        array &$changes,
        string $product,
        int $desiredQty,
    ): void {
        $priceId = $this->managedProductPriceIdForSubscription($subscription, $product);
        if ($priceId === '') {
            return;
        }

        $current = $this->currentQuantity($subscription, $priceId);
        $change = $this->applyDelta($subscription, $priceId, $current, $desiredQty);
        if ($change !== null) {
            $changes[] = ['tier' => $product] + $change;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function reconcileEdgeUsageLine(
        Subscription $subscription,
        DesiredBillingState $desired,
        array &$changes,
    ): void {
        if ($this->isYearly($subscription)) {
            return;
        }

        $priceId = (string) (config('subscription.standard.stripe.edge_usage') ?? '');
        if ($priceId === '') {
            return;
        }

        $desiredQty = max(0, $desired->edgeUsageSubtotalCents);
        $current = $this->currentQuantity($subscription, $priceId);
        $change = $this->applyDelta($subscription, $priceId, $current, $desiredQty);
        if ($change !== null) {
            $changes[] = ['tier' => 'edge_usage'] + $change;
        }
    }

    private function managedProductPriceIdForSubscription(Subscription $subscription, string $product): string
    {
        $key = $this->isYearly($subscription) ? $product.'_yearly' : $product;

        return (string) (config('subscription.standard.stripe.'.$key) ?? '');
    }

    private function isYearly(Subscription $subscription): bool
    {
        $yearlyBase = (string) (config('subscription.standard.stripe.base_yearly') ?? '');

        return $yearlyBase !== '' && $subscription->hasPrice($yearlyBase);
    }
}
