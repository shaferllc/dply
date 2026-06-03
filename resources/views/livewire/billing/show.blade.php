@php
    // At-a-glance figures for the hero stat strip. The "status" tile is the
    // big one — color-coded so it doubles as a banner.
    $billableCount = $this->billableServers->count();
    $monthlyCents = (int) ($this->billingState->monthlyTotalCents ?? 0);
    $intervalLabel = $this->subscriptionInterval === 'year' ? __('billed annually') : __('billed monthly');

    $betaFeeWaived = $this->organization->betaFeeWaived();

    if ($betaFeeWaived) {
        $statusTone = 'success';
        $statusLabel = __('Beta');
        $statusSub = __('$0 — nothing due');
    } elseif ($this->onGracePeriod) {
        $statusTone = 'warning';
        $statusLabel = __('Cancelled');
        $statusSub = $this->subscriptionEndsAt ? __('Access until :date', ['date' => $this->subscriptionEndsAt->toFormattedDateString()]) : __('In grace period');
    } elseif ($this->subscription) {
        $statusTone = 'success';
        $statusLabel = __('Active');
        $statusSub = $intervalLabel;
    } elseif ($this->onDplyTrial) {
        $statusTone = 'info';
        $statusLabel = __('Trial');
        $statusSub = trans_choice(':n day left|:n days left', $this->dplyTrialDaysLeft, ['n' => $this->dplyTrialDaysLeft]);
    } else {
        $statusTone = 'neutral';
        $statusLabel = __('No plan');
        $statusSub = __('Pick a plan below');
    }

    $statusTiles = [
        'success' => 'border-brand-sage/30 bg-brand-sage/8',
        'info' => 'border-sky-200 bg-sky-50',
        'warning' => 'border-amber-200 bg-amber-50',
        'danger' => 'border-red-200 bg-red-50',
        'neutral' => 'border-brand-ink/10 bg-white',
    ];
    $statusDot = [
        'success' => 'bg-brand-sage',
        'info' => 'bg-sky-500',
        'warning' => 'bg-amber-500',
        'danger' => 'bg-red-500',
        'neutral' => 'bg-brand-ink/15',
    ];
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"
         x-data="{
             billingPreviewAnnual: @js($this->subscriptionInterval === 'year'),
             previewCounts: @js(collect($this->billingState->tierQuantities)->all()),
             previewPlans: @js($this->planCatalog),
             previewAnnualPct: @js((int) config('subscription.standard.annual_discount_pct', 20)),
             get previewServerCount() {
                 return ['xs','s','m','l','xl'].reduce((n, k) => n + (this.previewCounts[k] || 0), 0);
             },
             get previewPlan() {
                 const count = this.previewServerCount;
                 for (const plan of this.previewPlans) {
                     if (plan.max === null || count <= plan.max) return plan;
                 }
                 return this.previewPlans[this.previewPlans.length - 1];
             },
             get previewMonthlyTotal() {
                 return Math.max(0, this.previewPlan ? this.previewPlan.price : 0);
             },
             get previewBilledTotal() {
                 return this.billingPreviewAnnual
                     ? Math.round(this.previewMonthlyTotal * 12 * (1 - this.previewAnnualPct / 100))
                     : this.previewMonthlyTotal;
             },
             fmt(n) { return '$' + (Math.round(n * 100) / 100).toFixed(2); }
         }">
        <x-organization-shell :organization="$organization" section="billing">
            <x-livewire-validation-errors />

            @if ($betaFeeWaived)
                <div class="mb-6 rounded-2xl border border-brand-gold/30 bg-brand-gold/8 px-5 py-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                <x-heroicon-o-sparkles class="h-4 w-4 shrink-0 text-brand-gold" aria-hidden="true" />
                                {{ __('You’re in the dply beta — $0, nothing due') }}
                            </p>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ __('Your platform fee is waived and your dply-managed server is on us during the beta. Connect your own cloud servers free. Need more servers, or want to lock in early? Subscribe any time below — your free managed server stays free.') }}
                            </p>
                        </div>
                        <button type="button" wire:click="subscribeStandard('month')" wire:loading.attr="disabled" wire:target="subscribeStandard"
                                class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:border-brand-sage/40 disabled:opacity-60">
                            {{ __('Subscribe early') }}
                        </button>
                    </div>
                </div>
            @endif

            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Billing & plan'), 'icon' => 'rectangle-stack'],
            ]" />

            {{-- Hero card: positioning + status / fleet / bill stat strip. --}}
            <section class="dply-card overflow-hidden">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                    <div class="lg:col-span-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-credit-card class="h-6 w-6" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Billing') }}</p>
                                <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Billing & plan') }}</h2>
                                <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Simple pricing for :org. One flat plan chosen by server count — your first server is free, any size on any cloud. Managed products bill per unit on top.', ['org' => $organization->name]) }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <x-docs-link doc-route="docs.markdown" doc-slug="billing-and-plans">
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Billing docs') }}
                            </x-docs-link>
                            <x-outline-link href="{{ route('billing.analytics', $organization) }}" wire:navigate>
                                <x-heroicon-o-chart-bar class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Analytics') }}
                            </x-outline-link>
                            @if ($this->canManageBilling)
                                <x-outline-link href="{{ route('billing.invoices', $organization) }}" wire:navigate>
                                    <x-heroicon-o-document class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                    {{ __('All invoices') }}
                                </x-outline-link>
                            @endif
                        </div>
                    </div>
                    <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                        <div class="rounded-2xl border px-4 py-3 shadow-sm {{ $statusTiles[$statusTone] }}">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                            <dd class="mt-1 flex items-center gap-1.5">
                                <span class="inline-block h-2 w-2 rounded-full {{ $statusDot[$statusTone] }}" aria-hidden="true"></span>
                                <span class="text-sm font-semibold text-brand-ink">{{ $statusLabel }}</span>
                            </dd>
                            <p class="mt-1 truncate text-[11px] text-brand-moss" title="{{ $statusSub }}">{{ $statusSub }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Servers') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $billableCount }}</span>
                                <span class="text-[11px] text-brand-moss">{{ __('billable') }}</span>
                            </dd>
                            @if ($this->excludedServers->isNotEmpty())
                                <p class="mt-1 text-[11px] text-brand-mist">+{{ $this->excludedServers->count() }} {{ __('excluded') }}</p>
                            @endif
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Current bill') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">${{ number_format($monthlyCents / 100, 0) }}</span>
                                <span class="text-[11px] text-brand-moss">/{{ __('mo') }}</span>
                            </dd>
                            @if ($this->subscriptionInterval === 'year')
                                <p class="mt-1 text-[11px] text-brand-mist">${{ number_format($this->yearlyTotalCents / 100, 0) }}/{{ __('yr') }}</p>
                            @endif
                        </div>
                    </dl>
                </div>
            </section>

            {{-- Inline alerts: Stripe Checkout, flash, errors. --}}
            <div class="mt-4 space-y-3">
                @if (request()->query('checkout') === 'success')
                    <x-alert tone="success">{{ __('Subscription updated successfully.') }}</x-alert>
                @endif
                @if (session('billing_status'))
                    <x-alert tone="success">{{ session('billing_status') }}</x-alert>
                @endif
                @if (session('billing_error'))
                    <x-alert tone="error">{{ session('billing_error') }}</x-alert>
                @endif
                @if (request()->query('checkout') === 'cancelled')
                    <x-alert tone="warning">{{ __('Checkout was cancelled.') }}</x-alert>
                @endif
                @error('plan')<x-alert tone="error">{{ $message }}</x-alert>@enderror
                @error('billing')<x-alert tone="error">{{ $message }}</x-alert>@enderror

                {{-- In-flight banner — visible after a confirmation modal closes
                     while the Stripe call is still running. --}}
                <div wire:loading.flex wire:target="switchInterval,cancelSubscription,resumeSubscription"
                     class="hidden items-center gap-3 rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3">
                    <x-spinner variant="ink" size="sm" />
                    <span class="text-sm font-medium text-brand-ink">{{ __('Updating your subscription with Stripe…') }}</span>
                </div>
            </div>

            @php
                // Shared section-header chrome — icon tile + eyebrow + heading + lead.
                // Mirrors the pattern on the automation page so org settings read as
                // one family of screens.
                $tonePalette = [
                    'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
                    'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
                    'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
                    'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
                    'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
                ];
            @endphp

            <div class="mt-6 space-y-6">

                {{-- Existing rich partials: bill hero, fleet table, calculator.
                     These already bring their own dply-card framing, so we drop
                     them in as-is. --}}
                @include('livewire.billing.partials.bill-hero')
                @include('livewire.billing.partials.fleet-table')
                @include('livewire.billing.partials.bill-preview')

                {{-- Payment method --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-credit-card class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Payment') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Payment method') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Default card on file. Update from the Stripe portal.') }}</p>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3 p-6 sm:p-7">
                        <p class="text-sm text-brand-ink">{{ $this->paymentSummary }}</p>
                        @if ($this->canManageBilling)
                            <x-secondary-button type="button" wire:click="portal">
                                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Manage in Stripe') }}
                            </x-secondary-button>
                        @else
                            <p class="text-sm text-brand-mist">{{ __('Subscribe above to add a payment method.') }}</p>
                        @endif
                    </div>
                </section>

                {{-- Billing details. Org-scoped invoice email, VAT, currency,
                     legal details — printed on Stripe invoices for this org's
                     subscription. Migrated off the user-level profile page in
                     2026-05 because subscriptions are org-scoped. --}}
                @if ($this->canManageBilling)
                    @php
                        $currencies = config('profile_options.currencies', []);
                    @endphp
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-identification class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Invoicing') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Billing details') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Invoice email, VAT, currency, and legal details. Printed on every Stripe invoice for this organization\'s subscription.') }}</p>
                            </div>
                        </div>
                        <form wire:submit="saveBillingDetails" class="space-y-5 p-6 sm:p-7">
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="org_invoice_email" :value="__('Invoice email')" />
                                    <x-text-input id="org_invoice_email" wire:model="invoice_email" type="email" class="mt-1 block w-full" autocomplete="email" />
                                    <p class="mt-1.5 text-[11px] text-brand-mist">{{ __('Where invoices land — defaults to the org owner\'s email when blank.') }}</p>
                                    <x-input-error class="mt-2" :messages="$errors->get('invoice_email')" />
                                </div>
                                <div>
                                    <x-input-label for="org_vat_number" :value="__('VAT number')" />
                                    <x-text-input id="org_vat_number" wire:model="vat_number" type="text" class="mt-1 block w-full" placeholder="NL123456789B01" autocomplete="off" />
                                    <p class="mt-1.5 text-[11px] text-brand-mist">{{ __('Include the country code. EU businesses may receive a VAT exemption notice when valid.') }}</p>
                                    <x-input-error class="mt-2" :messages="$errors->get('vat_number')" />
                                </div>
                            </div>
                            <div>
                                <x-input-label for="org_billing_currency" :value="__('Currency')" />
                                <select
                                    id="org_billing_currency"
                                    wire:model="billing_currency"
                                    class="mt-1 block w-full max-w-md rounded-lg border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                >
                                    <option value="">{{ __('Select a currency') }}</option>
                                    @foreach ($currencies as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1.5 text-[11px] text-brand-mist">{{ __('Preferred currency for invoices and payment references.') }}</p>
                                <x-input-error class="mt-2" :messages="$errors->get('billing_currency')" />
                            </div>
                            <div>
                                <x-input-label for="org_billing_details" :value="__('Legal details')" />
                                <textarea
                                    id="org_billing_details"
                                    wire:model="billing_details"
                                    rows="4"
                                    class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage"
                                    placeholder="{{ __('Legal name, address, and other details to show on invoices') }}"
                                ></textarea>
                                <p class="mt-1.5 text-[11px] text-brand-mist">{{ __('Printed on newly created invoices when provided.') }}</p>
                                <x-input-error class="mt-2" :messages="$errors->get('billing_details')" />
                            </div>
                            <div class="flex items-center justify-end border-t border-brand-ink/10 pt-4">
                                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveBillingDetails">
                                    <span wire:loading.remove wire:target="saveBillingDetails" class="inline-flex items-center gap-2">
                                        <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Save billing details') }}
                                    </span>
                                    <span wire:loading wire:target="saveBillingDetails" class="inline-flex items-center gap-2">
                                        <x-spinner variant="cream" size="sm" />
                                        {{ __('Saving…') }}
                                    </span>
                                </x-primary-button>
                            </div>
                        </form>
                    </section>
                @endif

                {{-- Subscription — cancel / resume --}}
                @if ($this->canManageBilling)
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                @if ($this->onGracePeriod)
                                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                                @else
                                    <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                                @endif
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Subscription') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cancel or resume') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Cancel keeps your data and servers — billing just stops at the end of the period.') }}</p>
                            </div>
                        </div>
                        <div class="p-6 sm:p-7">
                            @if ($this->onGracePeriod)
                                <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3">
                                    <p class="text-sm font-semibold text-amber-950">
                                        {{ __('Subscription ends :date.', ['date' => $this->subscriptionEndsAt?->toFormattedDateString()]) }}
                                    </p>
                                    <p class="mt-0.5 text-sm text-amber-900/80">{{ __('You keep full access until then. Change your mind?') }}</p>
                                    <button type="button" wire:click="resumeSubscription"
                                            wire:loading.attr="disabled" wire:target="resumeSubscription"
                                            class="mt-3 inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
                                        <span wire:loading.remove wire:target="resumeSubscription" class="inline-flex items-center gap-2">
                                            <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Resume subscription') }}
                                        </span>
                                        <span wire:loading wire:target="resumeSubscription" class="inline-flex items-center gap-2">
                                            <x-spinner size="sm" variant="cream" />
                                            {{ __('Resuming…') }}
                                        </span>
                                    </button>
                                </div>
                            @else
                                <button type="button" x-on:click="$dispatch('open-modal', 'cancel-subscription')"
                                        class="inline-flex items-center gap-1.5 text-sm font-semibold text-red-700 underline underline-offset-2 hover:text-red-900">
                                    <x-heroicon-o-x-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Cancel subscription') }}
                                </button>
                            @endif
                        </div>
                    </section>
                @endif

                {{-- Invoices --}}
                @if ($this->canManageBilling)
                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-document class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('History') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Invoices') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Recent invoices from Stripe.') }}</p>
                            </div>
                            <a href="{{ route('billing.invoices', $organization) }}" wire:navigate class="shrink-0 text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('View all') }} →</a>
                        </div>
                        @if ($this->invoices->isEmpty())
                            <div class="px-6 py-10 text-center sm:px-7">
                                <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                    <x-heroicon-o-document class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <p class="mt-3 text-sm text-brand-moss">{{ __('No invoices yet.') }}</p>
                            </div>
                        @else
                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($this->invoices as $invoice)
                                    @php $hosted = $invoice->asStripeInvoice()->hosted_invoice_url ?? null; @endphp
                                    <li class="flex items-center justify-between gap-4 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7">
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-brand-ink">{{ $invoice->date()->toFormattedDateString() }}</p>
                                            <p class="mt-0.5 font-mono text-[11px] text-brand-moss tabular-nums">{{ $invoice->total() }}</p>
                                        </div>
                                        @if ($hosted)
                                            <a href="{{ $hosted }}" target="_blank" rel="noopener noreferrer" class="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-brand-sage hover:text-brand-ink">
                                                <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                {{ __('Open in Stripe') }}
                                            </a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif

                {{-- How billing works (partial brings its own card). --}}
                @include('livewire.billing.partials.how-billing-works')
            </div>

            {{-- Confirmation modals --}}
            @if ($this->subscription)
                @php $interval = $this->subscriptionInterval; @endphp
                <x-modal name="switch-interval" maxWidth="md">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">
                            {{ $interval === 'year' ? __('Switch to monthly billing') : __('Switch to annual billing') }}
                        </h3>
                        <p class="mt-3 text-sm text-brand-moss leading-relaxed">
                            @if ($interval === 'year')
                                {{ __('You\'ll move to monthly billing. A prorated adjustment for the rest of your current period appears on your next invoice, and you\'ll lose the 20% annual discount.') }}
                            @else
                                {{ __('You\'ll be charged a prorated amount for the rest of your current cycle now, then :amount/yr going forward — a 20% saving versus monthly.', ['amount' => '$'.number_format($this->yearlyTotalCents / 100, 2)]) }}
                            @endif
                            {{ __('The switch takes effect immediately.') }}
                        </p>
                        <div class="mt-6 flex justify-end gap-3">
                            <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'switch-interval')">
                                {{ __('Never mind') }}
                            </x-secondary-button>
                            <button type="button"
                                    wire:click="switchInterval"
                                    x-on:click="$dispatch('close-modal', 'switch-interval')"
                                    class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                                {{ $interval === 'year' ? __('Switch to monthly') : __('Switch to annual') }}
                            </button>
                        </div>
                    </div>
                </x-modal>

                <x-modal name="cancel-subscription" maxWidth="md">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Cancel subscription') }}</h3>
                        <p class="mt-3 text-sm text-brand-moss leading-relaxed">
                            @if ($this->nextInvoiceAt)
                                {{ __('You\'ll keep full access until :date — no further charges after that.', ['date' => $this->nextInvoiceAt->toFormattedDateString()]) }}
                            @else
                                {{ __('You\'ll keep full access until the end of your current billing period — no further charges after that.') }}
                            @endif
                        </p>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                            {{ __('Your servers, sites, and data stay intact. After the period ends, deploys pause; agents disconnect 30 days later. You can resume anytime before the period ends.') }}
                        </p>
                        <div class="mt-6 flex justify-end gap-3">
                            <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'cancel-subscription')">
                                {{ __('Keep subscription') }}
                            </x-secondary-button>
                            <button type="button"
                                    wire:click="cancelSubscription"
                                    x-on:click="$dispatch('close-modal', 'cancel-subscription')"
                                    class="inline-flex items-center rounded-xl bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800">
                                {{ __('Cancel subscription') }}
                            </button>
                        </div>
                    </div>
                </x-modal>
            @endif
        </x-organization-shell>
    </div>
</div>
