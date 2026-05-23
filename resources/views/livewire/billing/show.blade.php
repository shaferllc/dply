<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"
         x-data="{
             billingPreviewAnnual: @js($this->subscriptionInterval === 'year'),
             previewCounts: @js(collect($this->billingState->tierQuantities)->all()),
             previewTiers: @js(collect(config('subscription.standard.tiers', []))->map(fn ($c) => $c / 100)->all()),
             previewBase: @js(($this->billingState->baseCents) / 100),
             previewAnnualPct: @js((int) config('subscription.standard.annual_discount_pct', 20)),
             get previewServerSubtotal() {
                 return ['xs','s','m','l','xl'].reduce((sum, k) => sum + (this.previewCounts[k] || 0) * (this.previewTiers[k] || 0), 0);
             },
             get previewMonthlyTotal() {
                 return Math.max(0, this.previewBase + this.previewServerSubtotal);
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

            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Billing & plan'), 'icon' => 'rectangle-stack'],
            ]" />

            <div class="space-y-8">
                {{-- Header --}}
                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-7">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Billing & plan') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Usage-based pricing for :org. A flat base, plus a per-server fee that scales with detected server size. Same fee regardless of which cloud you run on.', ['org' => $organization->name]) }}
                            </p>
                        </div>
                        <div class="lg:col-span-5 flex flex-wrap items-start justify-end gap-3">
                            <x-outline-link href="{{ route('docs.markdown', ['slug' => 'billing-and-plans']) }}" wire:navigate>
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Billing docs') }}
                            </x-outline-link>
                            <x-outline-link href="{{ route('billing.analytics', $organization) }}" wire:navigate>
                                <x-heroicon-o-chart-bar class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Billing analytics') }}
                            </x-outline-link>
                        </div>
                    </div>
                </div>

                {{-- Stripe Checkout result alerts --}}
                @if (request()->query('checkout') === 'success')
                    <x-alert tone="success">{{ __('Subscription updated successfully.') }}</x-alert>
                @endif
                @if (session('billing_status'))
                    <x-alert tone="success">{{ session('billing_status') }}</x-alert>
                @endif
                @if (session('billing_error'))
                    <x-alert tone="error">{{ session('billing_error') }}</x-alert>
                @endif

                {{-- Page-level processing banner — visible after a confirmation
                     modal closes, while the Stripe call is in flight. --}}
                <div wire:loading.flex wire:target="switchInterval,cancelSubscription,resumeSubscription"
                     class="hidden items-center gap-3 rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3">
                    <svg class="animate-spin h-5 w-5 text-brand-ink" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path>
                    </svg>
                    <span class="text-sm font-medium text-brand-ink">{{ __('Updating your subscription with Stripe…') }}</span>
                </div>
                @if (request()->query('checkout') === 'cancelled')
                    <x-alert tone="warning">{{ __('Checkout was cancelled.') }}</x-alert>
                @endif
                @error('plan')<x-alert tone="error">{{ $message }}</x-alert>@enderror
                @error('billing')<x-alert tone="error">{{ $message }}</x-alert>@enderror

                {{-- Active-trial countdown is rendered by x-trial-pause-banner
                     via the organization shell — one source of truth across
                     the app. Subscribe actions live in the Payment method
                     section below. --}}

                {{-- HERO: Your bill --}}
                @include('livewire.billing.partials.bill-hero')

                {{-- Your fleet --}}
                @include('livewire.billing.partials.fleet-table')

                {{-- What would it cost? Interactive calculator --}}
                @include('livewire.billing.partials.bill-preview')

                {{-- Payment method --}}
                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Payment method') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Default card on file. Update your card from the Stripe portal.') }}</p>
                        </div>
                        <div class="lg:col-span-8 space-y-4">
                            <p class="text-sm text-brand-ink">{{ $this->paymentSummary }}</p>
                            @if ($this->canManageBilling)
                                <x-secondary-button type="button" wire:click="portal">
                                    {{ __('Manage in Stripe') }}
                                </x-secondary-button>
                            @else
                                <p class="text-sm text-brand-moss">{{ __('Subscribe above to add a payment method.') }}</p>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Subscription — cancel / resume --}}
                @if ($this->canManageBilling)
                    <div class="dply-card overflow-hidden">
                        <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                            <div class="lg:col-span-4">
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Subscription') }}</h2>
                                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Cancel keeps your data and servers — billing just stops at the end of the period.') }}</p>
                            </div>
                            <div class="lg:col-span-8">
                                @if ($this->onGracePeriod)
                                    <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3">
                                        <p class="text-sm font-semibold text-amber-950">
                                            {{ __('Subscription ends :date.', ['date' => $this->subscriptionEndsAt?->toFormattedDateString()]) }}
                                        </p>
                                        <p class="mt-0.5 text-sm text-amber-900/80">{{ __('You keep full access until then. Change your mind?') }}</p>
                                        <button type="button" wire:click="resumeSubscription"
                                                wire:loading.attr="disabled" wire:target="resumeSubscription"
                                                class="mt-3 inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
                                            <svg wire:loading wire:target="resumeSubscription" class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path>
                                            </svg>
                                            <span wire:loading.remove wire:target="resumeSubscription">{{ __('Resume subscription') }}</span>
                                            <span wire:loading wire:target="resumeSubscription">{{ __('Resuming…') }}</span>
                                        </button>
                                    </div>
                                @else
                                    <button type="button" x-on:click="$dispatch('open-modal', 'cancel-subscription')"
                                            class="text-sm font-semibold text-red-700 hover:text-red-900 underline underline-offset-2">
                                        {{ __('Cancel subscription') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Invoices --}}
                @if ($this->canManageBilling)
                    <div class="dply-card overflow-hidden">
                        <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                            <div class="lg:col-span-4">
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Invoices') }}</h2>
                                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Recent invoices from Stripe.') }}</p>
                            </div>
                            <div class="lg:col-span-8 space-y-4 min-w-0">
                                <div class="flex justify-end">
                                    <a href="{{ route('billing.invoices', $organization) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('View all') }}</a>
                                </div>
                                @if ($this->invoices->isEmpty())
                                    <p class="text-sm text-brand-moss">{{ __('No invoices yet.') }}</p>
                                @else
                                    <ul class="divide-y divide-brand-mist/80 rounded-xl border border-brand-mist overflow-hidden bg-white">
                                        @foreach ($this->invoices as $invoice)
                                            @php $hosted = $invoice->asStripeInvoice()->hosted_invoice_url ?? null; @endphp
                                            <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                                                <div>
                                                    <span class="font-medium text-brand-ink">{{ $invoice->date()->toFormattedDateString() }}</span>
                                                    <span class="text-brand-moss ms-2">{{ $invoice->total() }}</span>
                                                </div>
                                                @if ($hosted)
                                                    <a href="{{ $hosted }}" target="_blank" rel="noopener noreferrer" class="text-brand-sage hover:text-brand-ink text-sm font-medium">{{ __('View in Stripe') }}</a>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                {{-- How billing works --}}
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
