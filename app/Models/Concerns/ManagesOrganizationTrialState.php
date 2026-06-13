<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\TrialState;
use App\Services\Billing\OrganizationBillingStateComputer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesOrganizationTrialState
{


    /**
     * True while the org is in its 14-day no-card trial window. Distinct from
     * Cashier's onTrial() (which only knows about Stripe-tracked trials) —
     * dply trials exist *before* a Stripe subscription is created.
     */
    public function onDplyTrial(): bool
    {
        return $this->trialState() === TrialState::ActiveTrial;
    }

    /**
     * Resolve the org's current subscription-lifecycle state. The single
     * source of truth for "what can this org do right now?" — see
     * App\Enums\TrialState for the full state machine.
     */
    public function trialState(): TrialState
    {
        // Includes the cancel grace period — Cashier's valid() stays true
        // until ends_at, so a just-canceled org keeps full access.
        if ($this->onAnyPaidPlan()) {
            return TrialState::Subscribed;
        }

        // Closed-beta participants pay $0 with full access and are never paused:
        // the trial/pause ladder only protects dply from cost on orgs that
        // should be paying. Subscribed (early-subscribe) wins above; at the
        // global cutover isBeta() flips false and the org rejoins this ladder
        // (BetaGraduateCommand reseeds a fresh trial). NoTrial = free indefinitely.
        if ($this->isBeta()) {
            return TrialState::NoTrial;
        }

        $reference = $this->pauseLadderReference();
        if ($reference !== null) {
            // Free-plan exemption: an org that owes nothing this cycle (Free
            // plan, no managed products, no Edge usage) is never paused — the
            // pause ladder only protects dply from cost on orgs that should be
            // paying. Such an org simply lives on the free tier indefinitely.
            if ($this->owesNothingThisCycle()) {
                return TrialState::NoTrial;
            }

            $softPauseDays = (int) config('subscription.standard.soft_pause_days', 30);

            return $reference->copy()->addDays($softPauseDays)->isPast()
                ? TrialState::ExpiredHard
                : TrialState::ExpiredSoft;
        }

        if ($this->trial_ends_at === null) {
            return TrialState::NoTrial;
        }

        return TrialState::ActiveTrial;
    }

    /**
     * True when the org's current fleet bills to nothing this cycle: a Free
     * plan (within the free server ceiling) with no managed products and no
     * Edge delivery usage. Memoized per request — recomputing on every
     * {@see TrialState} call would be wasteful.
     */
    public function owesNothingThisCycle(): bool
    {
        return $this->owesNothingMemo ??= app(OrganizationBillingStateComputer::class)
            ->compute($this)
            ->isFree();
    }

    /**
     * The date the soft → hard pause ladder is measured from, or null when the
     * org isn't on a pause track. Two sources, in priority order:
     *
     *  1. A subscription that has fully ended (canceled, past its grace period)
     *     — the org lapsed from a paid plan; measure from the subscription's
     *     end date.
     *  2. A trial that's already expired — measure from trial_ends_at.
     *
     * A future-dated trial returns null (still an active trial, not paused).
     */
    private function pauseLadderReference(): ?CarbonInterface
    {
        $subscription = $this->subscription('default');
        if ($subscription && $subscription->ended() && $subscription->ends_at !== null) {
            return $subscription->ends_at;
        }

        if ($this->trial_ends_at !== null && $this->trial_ends_at->isPast()) {
            return $this->trial_ends_at;
        }

        return null;
    }

    /**
     * True when the org's current pause state stems from a canceled/ended
     * subscription rather than an expired trial — drives banner copy.
     */
    public function lapsedFromSubscription(): bool
    {
        $subscription = $this->subscription('default');

        return $subscription !== null && $subscription->ended();
    }

    /**
     * True when the subscription is canceled but still inside the period the
     * customer already paid for — full access continues, billing stops at
     * {@see subscriptionEndsAt}.
     */
    public function onSubscriptionGracePeriod(): bool
    {
        $subscription = $this->subscription('default');

        return $subscription !== null && $subscription->onGracePeriod();
    }

    /**
     * The date a canceled subscription's access ends. Null when not canceled.
     */
    public function subscriptionEndsAt(): ?CarbonInterface
    {
        return $this->subscription('default')?->ends_at;
    }

    /**
     * When the soft-pause window flips to hard-pause. Null when not on a pause
     * track (subscribed, active trial, no trial recorded). Useful for "agent
     * disconnects on {date}" UI copy.
     */
    public function hardPauseStartsAt(): ?CarbonImmutable
    {
        if ($this->onAnyPaidPlan()) {
            return null;
        }

        $reference = $this->pauseLadderReference();
        if ($reference === null) {
            return null;
        }

        $softPauseDays = (int) config('subscription.standard.soft_pause_days', 30);

        return $reference->copy()->addDays($softPauseDays)->toImmutable();
    }

    /**
     * Gate for cash-burning deploy operations. False when in either expired-
     * trial state; true while on any active subscription or live trial.
     */
    public function canDeploy(): bool
    {
        return $this->trialState()->permitsBilledWork();
    }

    /**
     * Gate for the Run-Now scheduler button. Same policy as deploys: paused
     * accounts can't trigger fresh runs, but the cron-driven scheduler that
     * lives on the customer's own server continues independently of dply.
     */
    public function canSchedulerRun(): bool
    {
        return $this->trialState()->permitsBilledWork();
    }

    /**
     * Gate for incoming agent metrics. Day-45 hard-pause behavior — dply
     * stops accepting telemetry from the org's servers, which is where the
     * ongoing cost lives. Soft-paused orgs keep reporting so dashboards and
     * the billing page stay accurate while the customer is being prompted
     * to add a card.
     */
    public function acceptsMetrics(): bool
    {
        return $this->trialState() !== TrialState::ExpiredHard;
    }
}
