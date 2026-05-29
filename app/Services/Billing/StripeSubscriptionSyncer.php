<?php

namespace App\Services\Billing;

use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Subscription;
use Throwable;

/**
 * Reconciles an organization's Stripe subscription line items against a
 * {@see DesiredBillingState} under the flat-plan model:
 *
 * - Exactly one **plan** line (Starter / Pro / Business) at quantity 1. A plan
 *   change (e.g. fleet grows past a ceiling) swaps the line: the new plan price
 *   is added and any other plan price removed in the same pass. A move to the
 *   Free plan removes all plan lines.
 * - One line per **managed product** (serverless / Cloud / Edge), quantity =
 *   live unit count.
 * - A metered **Edge usage** line (monthly only).
 *
 * Safe to invoke when Stripe is not configured — missing price IDs cause the
 * corresponding line to be skipped silently. Safe to invoke against an org
 * without a subscription — returns immediately so trial/free orgs (no Stripe
 * sub yet) flow through without special-casing.
 */
class StripeSubscriptionSyncer
{
    public function __construct(
        private SubscriptionPlanResolver $planResolver,
    ) {}

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

        $changes = [];

        // Reconcile the plan line first. Adding the new plan price before
        // removing the previous one guarantees the subscription is never
        // momentarily empty during a plan swap.
        $this->reconcilePlanLine($subscription, $desired, $changes);

        // Serverless functions — flat per-function line item.
        $this->reconcileManagedProductLine($subscription, $desired, $changes, 'serverless', $desired->serverlessCount);

        // dply Cloud + Edge — flat per live site.
        $this->reconcileManagedProductLine($subscription, $desired, $changes, 'cloud', $desired->cloudCount);
        $this->reconcileManagedProductLine($subscription, $desired, $changes, 'edge', $desired->edgeCount);
        $this->reconcileCloudResourceLine($subscription, $desired, $changes);
        $this->reconcileServerlessUsageLine($subscription, $desired, $changes);
        $this->reconcileManagedServerLine($subscription, $desired, $changes);
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
     * The interval ('month'|'year') the subscription is billed on.
     */
    private function intervalFor(Subscription $subscription): string
    {
        return $this->isYearly($subscription)
            ? SubscriptionPlanResolver::INTERVAL_YEAR
            : SubscriptionPlanResolver::INTERVAL_MONTH;
    }

    /**
     * Reconcile the single flat-plan line. Adds the desired plan price (if the
     * org is on a paid plan and it isn't already present), then removes any
     * other configured plan price still on the subscription — so a plan swap
     * or a downgrade to Free settles to exactly the right plan line. Adding
     * before removing keeps the subscription non-empty mid-swap.
     *
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function reconcilePlanLine(Subscription $subscription, DesiredBillingState $desired, array &$changes): void
    {
        $interval = $this->intervalFor($subscription);

        $desiredPriceId = $desired->planPriceCents > 0
            ? $this->planResolver->stripePriceId($desired->planKey, $interval)
            : '';

        if ($desiredPriceId !== '') {
            $current = $this->currentQuantity($subscription, $desiredPriceId);
            $change = $this->applyDelta($subscription, $desiredPriceId, $current, 1);
            if ($change !== null) {
                $changes[] = ['tier' => 'plan:'.$desired->planKey] + $change;
            }
        }

        foreach ($this->allPlanPriceIds($interval) as $planKey => $priceId) {
            if ($priceId === '' || $priceId === $desiredPriceId) {
                continue;
            }

            $current = $this->currentQuantity($subscription, $priceId);
            if ($current === null) {
                continue;
            }

            $change = $this->applyDelta($subscription, $priceId, $current, 0);
            if ($change !== null) {
                $changes[] = ['tier' => 'plan:'.$planKey] + $change;
            }
        }
    }

    /**
     * Configured Stripe price IDs for every paid plan at the given interval,
     * keyed by plan key. Used to find stale plan lines during a swap.
     *
     * @return array<string, string>
     */
    private function allPlanPriceIds(string $interval): array
    {
        $bucket = $interval === SubscriptionPlanResolver::INTERVAL_YEAR ? 'plans_yearly' : 'plans';
        $ids = [];
        foreach ((array) config("subscription.standard.stripe.{$bucket}", []) as $planKey => $priceId) {
            $ids[(string) $planKey] = (string) ($priceId ?? '');
        }

        return $ids;
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
     * Metered dply Cloud resource line — the marked-up cost of the DigitalOcean
     * containers, workers, databases, and buckets backing the org's Cloud apps.
     * Uses the per-cent unit price (quantity = cents), same mechanism as the
     * Edge delivery-usage line. Monthly only — a yearly subscription can't carry
     * a monthly-metered price.
     *
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function reconcileCloudResourceLine(
        Subscription $subscription,
        DesiredBillingState $desired,
        array &$changes,
    ): void {
        if ($this->isYearly($subscription)) {
            return;
        }

        $priceId = (string) (config('subscription.standard.stripe.cloud_usage') ?? '');
        if ($priceId === '') {
            return;
        }

        $desiredQty = max(0, $desired->cloudResourceSubtotalCents);
        $current = $this->currentQuantity($subscription, $priceId);
        $change = $this->applyDelta($subscription, $priceId, $current, $desiredQty);
        if ($change !== null) {
            $changes[] = ['tier' => 'cloud_resources'] + $change;
        }
    }

    /**
     * Metered managed-serverless usage + resources line (per-cent quantity),
     * monthly only — mirrors the Cloud-resource and Edge-usage lines.
     *
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function reconcileServerlessUsageLine(
        Subscription $subscription,
        DesiredBillingState $desired,
        array &$changes,
    ): void {
        if ($this->isYearly($subscription)) {
            return;
        }

        $priceId = (string) (config('subscription.standard.stripe.serverless_usage') ?? '');
        if ($priceId === '') {
            return;
        }

        $desiredQty = max(0, $desired->serverlessUsageSubtotalCents);
        $current = $this->currentQuantity($subscription, $priceId);
        $change = $this->applyDelta($subscription, $priceId, $current, $desiredQty);
        if ($change !== null) {
            $changes[] = ['tier' => 'serverless_usage'] + $change;
        }
    }

    /**
     * Metered dply-managed server line — all-in cost-plus billed as a per-cent
     * quantity, monthly only. Mirrors the Cloud-resource and serverless-usage lines.
     *
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function reconcileManagedServerLine(
        Subscription $subscription,
        DesiredBillingState $desired,
        array &$changes,
    ): void {
        if ($this->isYearly($subscription)) {
            return;
        }

        $priceId = (string) (config('subscription.standard.stripe.managed_server') ?? '');
        if ($priceId === '') {
            return;
        }

        $desiredQty = max(0, $desired->managedServerSubtotalCents);
        $current = $this->currentQuantity($subscription, $priceId);
        $change = $this->applyDelta($subscription, $priceId, $current, $desiredQty);
        if ($change !== null) {
            $changes[] = ['tier' => 'managed_server'] + $change;
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
        // Detect the interval from ANY yearly price on the subscription. A
        // Free-plan org can carry only a yearly managed-product line (no plan
        // line), so we check plan and managed yearly prices together rather
        // than keying off a single line that may be absent.
        $yearlyIds = array_merge(
            array_values((array) config('subscription.standard.stripe.plans_yearly', [])),
            [
                (string) (config('subscription.standard.stripe.serverless_yearly') ?? ''),
                (string) (config('subscription.standard.stripe.cloud_yearly') ?? ''),
                (string) (config('subscription.standard.stripe.edge_yearly') ?? ''),
            ],
        );

        foreach ($yearlyIds as $priceId) {
            $priceId = (string) $priceId;
            if ($priceId !== '' && $subscription->hasPrice($priceId)) {
                return true;
            }
        }

        return false;
    }
}
