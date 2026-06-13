<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Server;
use App\Services\Billing\OrganizationBillingStateComputer;
use App\Services\Billing\SubscriptionPlanResolver;
use Laravel\Cashier\Billable;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesOrganizationSubscription
{


    /**
     * The flat plan the org is currently on, resolved from its billable BYO
     * server count — the same basis the bill uses. Carries the plan's site
     * ceiling (`max_sites`).
     *
     * @return array{key: string, label: string, price_cents: int, max_servers: ?int, max_sites: ?int}
     */
    public function currentSubscriptionPlan(): array
    {
        return app(SubscriptionPlanResolver::class)
            ->resolveForServerCount($this->billablePlanServerCount());
    }

    /**
     * Billable BYO server count used to pick the plan. Mirrors the filter in
     * {@see OrganizationBillingStateComputer}: ready, past the new-server age
     * grace, and excluding dply-managed logical hosts.
     */
    private function billablePlanServerCount(): int
    {
        $minAgeDays = max(0, (int) config('subscription.standard.min_billable_age_days', 1));
        $ageCutoff = now()->subDays($minAgeDays);

        return $this->servers()
            ->where('status', Server::STATUS_READY)
            ->where('created_at', '<=', $ageCutoff)
            ->get()
            ->reject(fn (Server $server) => $server->isManagedProductHost())
            ->count();
    }

    public function planTierLabel(): string
    {
        if ($this->onEnterpriseSubscription()) {
            return 'Enterprise';
        }
        if ($this->onStandardSubscription()) {
            return 'Standard';
        }

        return 'Trial';
    }

    /**
     * True when this org has any active paid subscription — Standard or Enterprise.
     * Used as the "paying customer" gate by feature flags, API token creation, etc.
     */
    public function onAnyPaidPlan(): bool
    {
        return $this->onStandardSubscription() || $this->onEnterpriseSubscription();
    }

    /**
     * True when the org has an active dply Standard subscription — i.e. it
     * carries any price dply owns under the plan model: a flat plan price
     * (Starter/Pro/Business, monthly or yearly) or any a-la-carte managed
     * product / Edge-usage price. A Free-plan org with no managed products has
     * no Stripe subscription at all and returns false here.
     */
    public function onStandardSubscription(): bool
    {
        return $this->subscriptionMatchesAnyPrice($this->standardStripePriceIds());
    }

    /**
     * Every Stripe price ID dply owns under the Standard plan model, across
     * both billing intervals.
     *
     * @return list<?string>
     */
    private function standardStripePriceIds(): array
    {
        $stripe = (array) config('subscription.standard.stripe', []);

        $ids = array_merge(
            array_values((array) ($stripe['plans'] ?? [])),
            array_values((array) ($stripe['plans_yearly'] ?? [])),
            [
                $stripe['serverless'] ?? null,
                $stripe['serverless_yearly'] ?? null,
                $stripe['cloud'] ?? null,
                $stripe['cloud_yearly'] ?? null,
                $stripe['edge'] ?? null,
                $stripe['edge_yearly'] ?? null,
                $stripe['edge_usage'] ?? null,
            ],
        );

        return array_values(array_map(
            fn ($id) => is_string($id) ? $id : null,
            $ids,
        ));
    }

    /**
     * True when the org has a sales-led Enterprise subscription.
     */
    public function onEnterpriseSubscription(): bool
    {
        return $this->subscriptionMatchesAnyPrice([
            config('subscription.enterprise.stripe_price_id'),
        ]);
    }

    /**
     * @param  list<?string>  $priceIds
     */
    private function subscriptionMatchesAnyPrice(array $priceIds): bool
    {
        $subscription = $this->subscription('default');
        if (! $subscription || ! $subscription->valid()) {
            return false;
        }

        foreach ($priceIds as $priceId) {
            if (is_string($priceId) && $priceId !== '' && $subscription->hasPrice($priceId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Seat cap from Stripe is not part of the Standard pricing story — every
     * paid plan gets unlimited team members. Kept as a stub returning null so
     * {@see effectiveMemberSeatCap} can fall through to the env-level cap.
     */
    public function seatCapFromSubscription(): ?int
    {
        return null;
    }

    /**
     * Maximum members + pending invites; null means unlimited.
     */
    public function effectiveMemberSeatCap(): ?int
    {
        $env = config('dply.max_organization_members');
        $stripeCap = $this->seatCapFromSubscription();
        if ($stripeCap !== null && $env !== null) {
            return min($env, $stripeCap);
        }

        return $stripeCap ?? $env;
    }
}
