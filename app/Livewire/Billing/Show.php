<?php

namespace App\Livewire\Billing;

use App\Enums\ServerTier;
use App\Models\Organization;
use App\Models\Server;
use App\Services\Billing\DesiredBillingState;
use App\Services\Billing\OrganizationBillingStateComputer;
use App\Services\Billing\StandardSubscriptionCreator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Laravel\Cashier\Invoice;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;
use Throwable;

#[Layout('layouts.app')]
class Show extends Component
{
    public Organization $organization;

    public function mount(Organization $organization): void
    {
        $this->authorize('update', $organization);
        $this->organization = $organization;
    }

    public function getSubscriptionProperty()
    {
        return $this->organization->subscription('default');
    }

    public function getStatusProperty(): ?string
    {
        $sub = $this->getSubscriptionProperty();

        return $sub ? $sub->stripe_status : null;
    }

    public function getPlanNameProperty(): ?string
    {
        $sub = $this->getSubscriptionProperty();
        if (! $sub) {
            return null;
        }
        if ($this->organization->onStandardSubscription()) {
            $interval = $sub->hasPrice((string) (config('subscription.standard.stripe.base_yearly') ?? ''))
                ? 'yearly'
                : 'monthly';

            return 'Standard ('.$interval.')';
        }
        if ($this->organization->onEnterpriseSubscription()) {
            return 'Enterprise';
        }

        return $sub->stripe_price ?? $sub->items->first()?->stripe_price;
    }

    public function getPaymentSummaryProperty(): string
    {
        $org = $this->organization;
        if ($org->pm_last_four) {
            return '•••• '.$org->pm_last_four;
        }
        $paymentMethod = $org->defaultPaymentMethod();
        if ($paymentMethod && method_exists($paymentMethod, 'asStripePaymentMethod')) {
            $pm = $paymentMethod->asStripePaymentMethod();
            if (isset($pm->card->last4)) {
                return '•••• '.$pm->card->last4;
            }
        }

        return 'No payment method';
    }

    /**
     * True only when there's a real subscription to manage. Deliberately not
     * gated on hasStripeId() — a Stripe customer record is created the moment
     * a Checkout session opens, well before (or even without) a completed
     * subscription. Gating on the customer record would hide the Subscribe
     * button from anyone who abandoned a checkout.
     */
    public function getCanManageBillingProperty(): bool
    {
        return $this->subscription !== null;
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function getInvoicesProperty(): Collection
    {
        if (! $this->organization->hasStripeId()) {
            return collect();
        }

        try {
            return $this->organization->invoices(false, ['limit' => 12]);
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * Start a Stripe Checkout session for the Standard plan. Line items are
     * seeded from the org's current server fleet, so the customer's first bill
     * reflects what they're actually running.
     */
    public function subscribeStandard(string $interval = StandardSubscriptionCreator::INTERVAL_MONTH): mixed
    {
        $this->authorize('update', $this->organization);

        if (! in_array($interval, [StandardSubscriptionCreator::INTERVAL_MONTH, StandardSubscriptionCreator::INTERVAL_YEAR], true)) {
            $this->addError('plan', __('Invalid billing interval.'));

            return null;
        }

        if ($this->organization->subscription('default') !== null) {
            $this->addError('billing', __('This organization already has an active subscription. Use Manage Billing to make changes.'));

            return null;
        }

        $computer = app(OrganizationBillingStateComputer::class);
        $creator = app(StandardSubscriptionCreator::class);

        try {
            $items = $creator->buildPriceList($computer->compute($this->organization), $interval);
        } catch (RuntimeException $e) {
            $this->addError('billing', __('Standard pricing is not configured yet. Contact support.'));

            return null;
        }

        audit_log($this->organization, auth()->user(), 'billing.checkout_started', null, null, [
            'plan' => 'standard',
            'interval' => $interval,
        ]);

        $subscriptionUrl = route('subscription.show', $this->organization);
        $builder = $this->organization->newSubscription('default');
        foreach ($items as $item) {
            $builder->price($item['price'], $item['quantity']);
        }

        $checkout = $builder->checkout([
            'success_url' => $subscriptionUrl.'?checkout=success',
            'cancel_url' => $subscriptionUrl.'?checkout=cancelled',
        ], []);

        // Stripe Checkout lives on a different origin (checkout.stripe.com),
        // so Livewire's default wire:navigate redirect fails silently — pass
        // navigate: false to force a full-page window.location swap.
        return $this->redirect($checkout->url, navigate: false);
    }

    public function getOnDplyTrialProperty(): bool
    {
        return $this->organization->onDplyTrial();
    }

    public function getDplyTrialDaysLeftProperty(): int
    {
        $endsAt = $this->organization->trial_ends_at;
        if (! $endsAt) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInDays($endsAt, false)));
    }

    public function getStandardPricingAvailableProperty(): bool
    {
        return (string) (config('subscription.standard.stripe.base_monthly') ?? '') !== ''
            || (string) (config('subscription.standard.stripe.base_yearly') ?? '') !== '';
    }

    /**
     * The bill dply *would* charge based on the current fleet — true
     * whether the org is on trial (estimate), subscribed (current invoice
     * basis), or paused (what subscribing would resume to).
     */
    public function getBillingStateProperty(): DesiredBillingState
    {
        return app(OrganizationBillingStateComputer::class)->compute($this->organization);
    }

    /**
     * Servers that currently count toward the bill: status=ready and older
     * than the min-billable-age threshold. Eager-loaded with a server tier
     * so the view can render specs without N+1 queries.
     *
     * @return Collection<int, Server>
     */
    public function getBillableServersProperty(): Collection
    {
        $minAge = max(0, (int) config('subscription.standard.min_billable_age_days', 1));

        return $this->organization->servers()
            ->where('status', Server::STATUS_READY)
            ->where('created_at', '<=', now()->subDays($minAge))
            ->orderBy('name')
            ->get();
    }

    /**
     * Servers excluded from billing with a human-readable reason — surfaces
     * the "why isn't this server on my bill?" question right in the table.
     *
     * @return Collection<int, array{server: Server, reason: string}>
     */
    public function getExcludedServersProperty(): Collection
    {
        $minAge = max(0, (int) config('subscription.standard.min_billable_age_days', 1));
        $cutoff = now()->subDays($minAge);
        $billableIds = $this->billableServers->pluck('id')->all();

        return $this->organization->servers()
            ->orderBy('name')
            ->get()
            ->reject(fn (Server $s) => in_array($s->id, $billableIds, true))
            ->map(function (Server $server) use ($cutoff, $minAge): array {
                $reason = match (true) {
                    $server->status !== Server::STATUS_READY => __('Status: :status', ['status' => $server->status]),
                    $server->created_at !== null && $server->created_at->gt($cutoff)
                        => __('Under the :days-day billable threshold', ['days' => $minAge]),
                    default => __('Excluded'),
                };

                return ['server' => $server, 'reason' => $reason];
            })
            ->values();
    }

    /**
     * Structured line items for the "Your bill" hero. One entry for the org
     * base, one per non-empty tier with quantity and per-unit price. Cents
     * preserved so the view can choose monthly/yearly presentation.
     *
     * @return list<array{label: string, quantity: int, unit_cents: int, line_cents: int}>
     */
    public function getTierLineItemsProperty(): array
    {
        $state = $this->billingState;
        $tierPrices = (array) config('subscription.standard.tiers', []);

        $items = [
            [
                'label' => __('dply base'),
                'quantity' => 1,
                'unit_cents' => $state->baseCents,
                'line_cents' => $state->baseCents,
            ],
        ];

        foreach (ServerTier::ordered() as $tier) {
            $qty = $state->quantityFor($tier);
            if ($qty <= 0) {
                continue;
            }

            $unit = (int) ($tierPrices[$tier->value] ?? 0);
            $items[] = [
                'label' => __('dply server — :tier', ['tier' => strtoupper($tier->value)]),
                'quantity' => $qty,
                'unit_cents' => $unit,
                'line_cents' => $unit * $qty,
            ];
        }

        return $items;
    }

    public function getYearlyTotalCentsProperty(): int
    {
        $pct = (int) config('subscription.standard.annual_discount_pct', 20);

        return (int) round($this->billingState->monthlyTotalCents * 12 * (100 - $pct) / 100);
    }

    public function getSubscriptionIntervalProperty(): ?string
    {
        $sub = $this->subscription;
        if (! $sub) {
            return null;
        }

        $yearlyBase = (string) (config('subscription.standard.stripe.base_yearly') ?? '');
        if ($yearlyBase !== '' && $sub->hasPrice($yearlyBase)) {
            return 'year';
        }

        return 'month';
    }

    public function getNextInvoiceAtProperty(): ?\Carbon\CarbonInterface
    {
        $sub = $this->subscription;
        if (! $sub) {
            return null;
        }

        try {
            $upcoming = $this->organization->upcomingInvoice();

            return $upcoming?->date();
        } catch (Throwable) {
            return null;
        }
    }

    public function portal(): mixed
    {
        $this->authorize('update', $this->organization);

        if (! $this->organization->hasStripeId()) {
            $this->addError('billing', 'No billing account yet. Subscribe to a plan first.');

            return null;
        }

        audit_log($this->organization, auth()->user(), 'billing.portal_accessed');

        return $this->organization->redirectToBillingPortal(route('subscription.show', $this->organization));
    }

    public function render(): View
    {
        return view('livewire.billing.show');
    }
}
