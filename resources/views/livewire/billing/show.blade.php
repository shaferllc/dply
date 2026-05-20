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
                        </div>
                    </div>
                </div>

                {{-- Stripe Checkout result alerts --}}
                @if (request()->query('checkout') === 'success')
                    <x-alert tone="success">{{ __('Subscription updated successfully.') }}</x-alert>
                @endif
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
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Default card on file. Update, cancel, or switch billing interval from the Stripe portal.') }}</p>
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
        </x-organization-shell>
    </div>
</div>
