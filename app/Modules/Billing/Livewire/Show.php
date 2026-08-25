<?php

namespace App\Modules\Billing\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Models\OrganizationBundleEntitlement;
use App\Models\Server;
use App\Modules\Billing\Services\DesiredBillingState;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Billing\Services\StandardSubscriptionCreator;
use App\Modules\Billing\Services\StripeInvoiceRows;
use App\Modules\Billing\Services\SubscriptionPlanResolver;
use App\Modules\Billing\Services\VatInsightService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Laravel\Cashier\Subscription;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Livewire exposes #[Computed] methods and the older get<Name>Property()
 * methods as $this-><name> in PHP and Blade. PHPStan cannot see that
 * magic, so the contract is stated here.
 *
 * @property-read Subscription|null $subscription
 * @property-read string|null $subscriptionInterval
 * @property-read Collection<int, Server> $billableServers
 * @property-read DesiredBillingState $billingState
 * @property-read list<array{key: string, label: string, price: float, max: ?int}> $planCatalog
 * @property-read list<array{key: string, label: string, price: float, min: int, max: ?int, current: bool}> $planLadder
 * @property-read array{label: string, delta: float, servers_until: int}|null $nextTier
 * @property-read list<array{label: string, quantity: int, unit_cents: int, line_cents: int, detail: ?string}> $tierLineItems
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use DispatchesToastNotifications;

    public Organization $organization;

    /**
     * Billing-entity fields for the org's invoices. Migrated off
     * `users` in 2026-05 because subscriptions are org-scoped.
     */
    public string $invoice_email = '';

    public string $vat_number = '';

    public string $billing_currency = '';

    public string $billing_details = '';

    public function mount(Organization $organization): void
    {
        $this->authorize('update', $organization);
        $this->organization = $organization;
        $this->invoice_email = (string) ($organization->invoice_email ?? '');
        $this->vat_number = (string) ($organization->vat_number ?? '');
        $this->billing_currency = (string) ($organization->billing_currency ?? '');
        $this->billing_details = (string) ($organization->billing_details ?? '');
    }

    public function saveBillingDetails(VatInsightService $vatInsights): void
    {
        $this->authorize('update', $this->organization);

        $rules = [
            'invoice_email' => ['nullable', 'string', 'email', 'max:255'],
            'vat_number' => [
                'nullable',
                'string',
                'max:64',
                function ($attribute, $value, $fail) use ($vatInsights): void {
                    $msg = $vatInsights->blockingValidationMessage(is_string($value) ? $value : null);
                    if ($msg !== null) {
                        $fail($msg);
                    }
                },
            ],
            'billing_details' => ['nullable', 'string', 'max:5000'],
        ];

        // Currency must come from the supported list when populated.
        $allowed = array_keys((array) config('profile_options.currencies', []));
        $rules['billing_currency'] = $this->billing_currency === ''
            ? ['nullable']
            : ['nullable', 'string', Rule::in($allowed)];

        $this->validate($rules);

        $this->organization->update([
            'invoice_email' => $this->invoice_email !== '' ? $this->invoice_email : null,
            'vat_number' => $this->vat_number !== '' ? $this->vat_number : null,
            'billing_currency' => $this->billing_currency === '' ? null : $this->billing_currency,
            'billing_details' => $this->billing_details !== '' ? $this->billing_details : null,
        ]);

        $fresh = $this->organization->fresh();
        if ($fresh) {
            $this->organization = $fresh;
            $this->invoice_email = (string) ($fresh->invoice_email ?? '');
            $this->vat_number = (string) ($fresh->vat_number ?? '');
            $this->billing_currency = (string) ($fresh->billing_currency ?? '');
            $this->billing_details = (string) ($fresh->billing_details ?? '');
        }

        $this->toastSuccess(__('Billing details saved.'));

        foreach ($vatInsights->collectSoftWarnings($this->vat_number) as $message) {
            $this->toastInfo($message);
        }
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
            $interval = $this->subscriptionIsYearly($sub) ? 'yearly' : 'monthly';

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

        if ($items === []) {
            // Free plan, no managed products — nothing for Stripe to bill, so
            // there's no subscription to start. The org keeps using dply free.
            $this->addError('billing', __('Your fleet is on the free plan — there\'s nothing to subscribe to yet. Add another server or a managed product to move onto a paid plan.'));

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
        // asStripeCheckoutSession() rather than $checkout->url: Checkout::__get()
        // just forwards to the underlying session, and the typed accessor says
        // so explicitly.
        return $this->redirect((string) $checkout->asStripeCheckoutSession()->url, navigate: false);
    }

    /**
     * Switch an existing subscription between monthly and yearly billing.
     * Swaps every line item (base + each tier) to the target interval's price
     * set and invoices the prorated difference immediately.
     */
    public function switchInterval(): mixed
    {
        $this->authorize('update', $this->organization);

        $subscription = $this->organization->subscription('default');
        if (! $subscription || ! $subscription->valid()) {
            return $this->billingRedirect('billing_error', __('No active subscription to change.'));
        }

        $current = $this->subscriptionInterval;
        $target = $current === StandardSubscriptionCreator::INTERVAL_YEAR
            ? StandardSubscriptionCreator::INTERVAL_MONTH
            : StandardSubscriptionCreator::INTERVAL_YEAR;

        $computer = app(OrganizationBillingStateComputer::class);
        $creator = app(StandardSubscriptionCreator::class);

        try {
            $items = $creator->buildPriceList($computer->compute($this->organization), $target);
        } catch (RuntimeException $e) {
            return $this->billingRedirect('billing_error', __('The :interval price set is not configured.', ['interval' => $target]));
        }

        // Cashier's swap() wants prices keyed by ID, value = options.
        $swap = [];
        foreach ($items as $item) {
            $swap[$item['price']] = ['quantity' => $item['quantity']];
        }

        audit_log($this->organization, auth()->user(), 'billing.interval_switched', null, null, [
            'from' => $current,
            'to' => $target,
        ]);

        try {
            $subscription->swapAndInvoice($swap);
        } catch (Throwable $e) {
            return $this->billingRedirect('billing_error', __('Could not switch billing interval. Please try again or contact support.'));
        }

        return $this->billingRedirect('billing_status', __('Billing switched to :interval.', [
            'interval' => $target === StandardSubscriptionCreator::INTERVAL_YEAR ? __('yearly') : __('monthly'),
        ]));
    }

    /**
     * Cancel the subscription at the end of the current billing period. The
     * customer keeps full access until then (Cashier grace period) and can
     * resume before it ends.
     */
    public function cancelSubscription(): mixed
    {
        $this->authorize('update', $this->organization);

        $subscription = $this->organization->subscription('default');
        if (! $subscription || ! $subscription->valid()) {
            return $this->billingRedirect('billing_error', __('No active subscription to cancel.'));
        }
        if ($subscription->canceled()) {
            return $this->billingRedirect('billing_error', __('This subscription is already scheduled to cancel.'));
        }

        audit_log($this->organization, auth()->user(), 'billing.subscription_canceled');

        try {
            $subscription->cancel();
        } catch (Throwable $e) {
            return $this->billingRedirect('billing_error', __('Could not cancel the subscription. Please try again or contact support.'));
        }

        // getAttribute(): ends_at is a Cashier column (cast to datetime in
        // the package), but Cashier's migrations live in the vendor dir, so
        // Larastan cannot see the column from database/migrations.
        $endsAt = $subscription->fresh()?->getAttribute('ends_at');

        return $this->billingRedirect('billing_status', $endsAt
            ? __('Subscription canceled. You keep full access until :date.', ['date' => $endsAt->toFormattedDateString()])
            : __('Subscription canceled. You keep access until the end of your billing period.'));
    }

    /**
     * Un-cancel a subscription that's still inside its grace period.
     */
    public function resumeSubscription(): mixed
    {
        $this->authorize('update', $this->organization);

        $subscription = $this->organization->subscription('default');
        if (! $subscription || ! $subscription->onGracePeriod()) {
            return $this->billingRedirect('billing_error', __('There\'s no canceled subscription to resume.'));
        }

        audit_log($this->organization, auth()->user(), 'billing.subscription_resumed');

        try {
            $subscription->resume();
        } catch (Throwable $e) {
            return $this->billingRedirect('billing_error', __('Could not resume the subscription. Please try again or contact support.'));
        }

        return $this->billingRedirect('billing_status', __('Your subscription has been resumed.'));
    }

    /**
     * Flash a message and reload the billing page. Reloading gives a clean
     * end state for these modal-driven actions: the modal disappears, any
     * stale subscription state is re-read fresh, and the flashed alert shows.
     */
    private function billingRedirect(string $key, string $message): mixed
    {
        session()->flash($key, $message);

        return $this->redirect(route('subscription.show', $this->organization));
    }

    /**
     * True when the subscription is canceled but still inside the grace period
     * — the customer has access but billing will stop at period end.
     */
    public function getOnGracePeriodProperty(): bool
    {
        return $this->subscription?->onGracePeriod() ?? false;
    }

    public function getSubscriptionEndsAtProperty(): ?CarbonInterface
    {
        return $this->subscription?->getAttribute('ends_at');
    }

    public function getOnDplyTrialProperty(): bool
    {
        return $this->organization->onDplyTrial();
    }

    /**
     * Bundled-products (free tracely + Lookout) state for this org's billing
     * page. Null — so the card is hidden — while the perk is dark, or for orgs
     * that neither qualify nor have a provisioned workspace. See
     * docs/adr/bundled-products-sso.md.
     *
     * @return array{entitled: bool, status: ?string}|null
     */
    public function getBundleProperty(): ?array
    {
        if (! config('bundle.enabled', false)) {
            return null;
        }

        $entitled = $this->organization->qualifiesForBundledProducts();
        $status = OrganizationBundleEntitlement::query()
            ->where('organization_id', $this->organization->id)
            ->value('status');

        if (! $entitled && $status === null) {
            return null;
        }

        return ['entitled' => $entitled, 'status' => $status !== null ? (string) $status : null];
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
        // Standard pricing is "available" as soon as any paid plan price (at
        // either interval) is configured in Stripe. The Free plan never needs
        // a price, so its absence doesn't gate the subscribe UI.
        $configured = array_merge(
            array_values((array) config('subscription.standard.stripe.plans', [])),
            array_values((array) config('subscription.standard.stripe.plans_yearly', [])),
        );

        foreach ($configured as $priceId) {
            if ((string) $priceId !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The bill dply *would* charge based on the current fleet — true
     * whether the org is on trial (estimate), subscribed (current invoice
     * basis), or paused (what subscribing would resume to).
     *
     * Request-memoised via {@see Computed} and
     * {@see OrganizationBillingStateComputer::compute()} so hero / preview /
     * line-item accessors share one DesiredBillingState.
     */
    /**
     * The five most recent Stripe invoices, rendered inline here — invoices are
     * a section of billing, not a page of their own. The full history stays at
     * the Invoices route for orgs with years of them.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function recentInvoices(): Collection
    {
        return StripeInvoiceRows::for($this->organization, 5)->take(5);
    }

    #[Computed]
    public function billingState(): DesiredBillingState
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
            ->get()
            ->reject(fn (Server $server): bool => $server->isManagedProductHost())
            ->values();
    }

    /**
     * Servers excluded from billing with a human-readable reason — surfaces
     * the "why isn't this server on my bill?" question right in the table.
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
            // Serverless / Functions hosts left this app for their own product;
            // listing them as "not billed here" is answering a question nobody
            // in dply can still ask.
            ->reject(fn (Server $s) => $s->isServerlessHost())
            ->map(function (Server $server) use ($cutoff, $minAge): array {
                $reason = match (true) {
                    $server->isManagedProductHost() => match (true) {
                        $server->isDplyCloudHost() => __('Billed as dply Cloud app'),
                        $server->isDplyEdgeHost() => __('Billed as dply Edge site'),
                        default => __('Billed as managed product'),
                    },
                    $server->status !== Server::STATUS_READY => __('Status: :status', ['status' => $server->status]),
                    $server->created_at !== null && $server->created_at->gt($cutoff) => __('Under the :days-day billable threshold', ['days' => $minAge]),
                    default => __('Excluded'),
                };

                return ['server' => $server, 'reason' => $reason];
            })
            ->values();
    }

    /**
     * Structured line items that sum to the monthly total. One entry for the
     * flat plan (chosen by server count) plus one per managed product in use.
     * Cents preserved so the view can choose monthly/yearly presentation.
     *
     * Realtime, Queue and Logs were missing here, which is why the hero showed
     * a $9.00 breakdown under a $24.00 total — the $15.00 Realtime line had
     * nowhere to render. The Cloud / Edge / serverless branches that used to
     * sit here are gone with those surfaces (remove-cloud-edge-serverless);
     * their subtotals are permanently zero.
     *
     * @return list<array{label: string, quantity: int, unit_cents: int, line_cents: int, detail: ?string}>
     */
    public function getTierLineItemsProperty(): array
    {
        $state = $this->billingState;

        $items = [
            [
                'label' => __('dply plan — :plan', ['plan' => $state->planLabel]),
                'quantity' => 1,
                'unit_cents' => $state->planPriceCents,
                'line_cents' => $state->planPriceCents,
                'detail' => $state->serverCount() > 0
                    ? trans_choice(':count server|:count servers', $state->serverCount(), ['count' => $state->serverCount()])
                    : null,
            ],
        ];

        if ($state->managedServerCount > 0) {
            $items[] = [
                'label' => __('dply managed server'),
                'quantity' => $state->managedServerCount,
                'unit_cents' => intdiv($state->managedServerSubtotalCents, $state->managedServerCount),
                'line_cents' => $state->managedServerSubtotalCents,
                'detail' => __('Billed cost-plus on dply-owned infrastructure'),
            ];
        }

        if ($state->realtimeSubtotalCents > 0) {
            $items[] = [
                'label' => __('Managed Realtime'),
                'quantity' => $state->realtimeCount,
                'unit_cents' => $state->realtimeCount > 0
                    ? intdiv($state->realtimeSubtotalCents, $state->realtimeCount)
                    : 0,
                'line_cents' => $state->realtimeSubtotalCents,
                'detail' => trans_choice(':count app|:count apps', $state->realtimeCount, ['count' => $state->realtimeCount]),
            ];
        }

        if ($state->queueSubtotalCents > 0) {
            $items[] = [
                'label' => __('Managed Queue'),
                'quantity' => $state->queueCount,
                'unit_cents' => $state->queueCount > 0
                    ? intdiv($state->queueSubtotalCents, $state->queueCount)
                    : 0,
                'line_cents' => $state->queueSubtotalCents,
                'detail' => trans_choice(':count namespace|:count namespaces', $state->queueCount, ['count' => $state->queueCount]),
            ];
        }

        if ($state->queueUsageSubtotalCents > 0) {
            $items[] = [
                'label' => __('Queue worker usage'),
                'quantity' => 1,
                'unit_cents' => $state->queueUsageSubtotalCents,
                'line_cents' => $state->queueUsageSubtotalCents,
                'detail' => __('Metered compute and job operations'),
            ];
        }

        if ($state->serverLogUsageSubtotalCents > 0) {
            $items[] = [
                'label' => __('Logs ingest'),
                'quantity' => 1,
                'unit_cents' => $state->serverLogUsageSubtotalCents,
                'line_cents' => $state->serverLogUsageSubtotalCents,
                'detail' => __('Metered above your plan allowance'),
            ];
        }

        return $items;
    }

    /**
     * Plan catalog for the interactive "what would it cost?" calculator,
     * ordered cheapest → most expensive. Prices in dollars; `max` is the
     * inclusive server-count ceiling (null = unlimited) so the Alpine widget
     * can resolve a plan from a hypothetical fleet size.
     *
     * @return list<array{key: string, label: string, price: float, max: ?int}>
     */
    public function getPlanCatalogProperty(): array
    {
        return array_map(
            fn (array $plan): array => [
                'key' => $plan['key'],
                'label' => $plan['label'],
                'price' => $plan['price_cents'] / 100,
                'max' => $plan['max_servers'],
            ],
            app(SubscriptionPlanResolver::class)->all(),
        );
    }

    /**
     * The tier ladder with the current rung marked, plus the server range each
     * rung covers. The catalog is ordered cheapest-first by max_servers, so a
     * rung's floor is the previous rung's ceiling + 1.
     *
     * @return list<array{key: string, label: string, price: float, min: int, max: ?int, current: bool}>
     */
    public function getPlanLadderProperty(): array
    {
        $current = $this->billingState->planKey;
        $floor = 1;
        $ladder = [];

        foreach ($this->planCatalog as $plan) {
            $max = $plan['max'] === null ? null : (int) $plan['max'];
            $ladder[] = [
                'key' => $plan['key'],
                'label' => $plan['label'],
                'price' => $plan['price'],
                'min' => $floor,
                'max' => $max,
                'current' => $plan['key'] === $current,
            ];
            $floor = $max === null ? $floor : $max + 1;
        }

        return $ladder;
    }

    /**
     * The rung above the current one, and what crossing into it costs.
     *
     * This is the fact the billing page could never tell you: adding one more
     * server is a price change, billed prorated the same day, and today it
     * looks like an ordinary "Add a server" click. Null on the top tier or when
     * the fleet is not yet at its ceiling.
     *
     * @return array{label: string, delta: float, servers_until: int}|null
     */
    public function getNextTierProperty(): ?array
    {
        $ladder = $this->planLadder;
        $index = null;

        foreach ($ladder as $i => $rung) {
            if ($rung['current']) {
                $index = $i;
                break;
            }
        }

        if ($index === null || ! isset($ladder[$index + 1])) {
            return null;
        }

        $here = $ladder[$index];
        $next = $ladder[$index + 1];

        if ($here['max'] === null) {
            return null;
        }

        return [
            'label' => $next['label'],
            'delta' => round($next['price'] - $here['price'], 2),
            'servers_until' => max(0, $here['max'] - $this->billingState->serverCount() + 1),
        ];
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

        return $this->subscriptionIsYearly($sub) ? 'year' : 'month';
    }

    /**
     * Detect a yearly subscription from any yearly plan or managed-product
     * price on it. A Free-plan org can carry only a yearly managed line (no
     * plan line), so we can't key off a single price.
     */
    private function subscriptionIsYearly(Subscription $sub): bool
    {
        $yearlyIds = array_merge(
            array_values((array) config('subscription.standard.stripe.plans_yearly', [])),
            [
                (string) (config('subscription.standard.stripe.cloud_yearly') ?? ''),
                (string) (config('subscription.standard.stripe.edge_yearly') ?? ''),
            ],
        );

        foreach ($yearlyIds as $priceId) {
            $priceId = (string) $priceId;
            if ($priceId !== '' && $sub->hasPrice($priceId)) {
                return true;
            }
        }

        return false;
    }

    public function getNextInvoiceAtProperty(): ?CarbonInterface
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
