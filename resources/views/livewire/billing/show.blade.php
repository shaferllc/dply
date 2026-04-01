<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="billing">
            <div>
                <x-livewire-validation-errors />

                <x-page-header
                    :title="__('Billing & plan')"
                    :description="__('Trial status, Pro billing, usage limits, and Stripe checkout for :org.', ['org' => $organization->name])"
                    flush
                />

    @if (request()->query('checkout') === 'success')
        <x-alert tone="success" class="mb-6">{{ __('Subscription updated successfully.') }}</x-alert>
    @endif
    @if (request()->query('checkout') === 'cancelled')
        <x-alert tone="warning" class="mb-6">{{ __('Checkout was cancelled.') }}</x-alert>
    @endif
    @error('plan')
        <x-alert tone="error" class="mb-6">{{ $message }}</x-alert>
    @enderror
    @error('billing')
        <x-alert tone="error" class="mb-6">{{ $message }}</x-alert>
    @enderror

    <div class="space-y-6">
        <x-section-card>
            <x-slot name="header">
                <h2 class="font-semibold text-brand-ink">{{ __('Subscription') }}</h2>
                <p class="text-sm text-brand-moss mt-0.5">{{ __('Current trial or subscription status.') }}</p>
            </x-slot>
            <div class="space-y-2">
                <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm font-medium text-brand-moss">{{ __('Status') }}</dt>
                        <dd class="mt-0.5 text-sm text-brand-ink">
                            @if ($this->status)
                                <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium
                                    @if ($this->status === 'active') bg-green-100 text-green-800
                                    @elseif ($this->status === 'trialing') bg-blue-100 text-blue-800
                                    @elseif (in_array($this->status, ['past_due', 'unpaid'])) bg-amber-100 text-amber-800
                                    @elseif (in_array($this->status, ['canceled', 'cancelled', 'incomplete_expired'])) bg-slate-100 text-slate-800
                                    @else bg-slate-100 text-slate-800
                                    @endif">
                                    {{ $this->status }}
                                </span>
                            @else
                                {{ __('Trial') }}
                            @endif
                        </dd>
                    </div>
                    @if ($this->planName)
                        <div>
                            <dt class="text-sm font-medium text-brand-moss">{{ __('Plan') }}</dt>
                            <dd class="mt-0.5 text-sm text-brand-ink">{{ $this->planName }}</dd>
                        </div>
                    @endif
                </dl>
            </div>
        </x-section-card>

        <x-section-card>
            <x-slot name="header">
                <h2 class="font-semibold text-brand-ink">{{ __('Plan limits & usage') }}</h2>
                <p class="text-sm text-brand-moss mt-0.5">{{ __('Counts include every server and site in this organization. Trial limits apply org-wide until your Stripe subscription matches the configured Pro prices.') }}</p>
            </x-slot>
            <div>
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                        <dt class="text-sm font-medium text-brand-moss">{{ __('Servers') }}</dt>
                        <dd class="mt-1 text-lg font-semibold text-brand-ink tabular-nums">
                            {{ $organization->servers()->count() }}
                            @if ($organization->maxServers() >= PHP_INT_MAX)
                                <span class="text-sm font-normal text-brand-moss"> / {{ __('Unlimited') }}</span>
                            @else
                                <span class="text-sm font-normal text-brand-moss"> / {{ $organization->maxServersDisplay() }} {{ __('during trial') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                        <dt class="text-sm font-medium text-brand-moss">{{ __('Sites') }}</dt>
                        <dd class="mt-1 text-lg font-semibold text-brand-ink tabular-nums">
                            {{ $organization->sites()->count() }}
                            @if ($organization->maxSites() >= PHP_INT_MAX)
                                <span class="text-sm font-normal text-brand-moss"> / {{ __('Unlimited') }}</span>
                            @else
                                <span class="text-sm font-normal text-brand-moss"> / {{ $organization->maxSitesDisplay() }} {{ __('during trial') }}</span>
                            @endif
                        </dd>
                    </div>
                </dl>
                <p class="mt-4 text-xs text-brand-mist">
                    {{ __('Defaults:') }} <code class="rounded bg-brand-sand/60 px-1 py-0.5 text-brand-ink">SUBSCRIPTION_SERVERS_FREE_LIMIT</code>, <code class="rounded bg-brand-sand/60 px-1 py-0.5 text-brand-ink">SUBSCRIPTION_SITES_FREE_LIMIT</code>.
                    <a href="{{ route('docs.org-roles-and-limits') }}" class="font-medium text-brand-sage hover:text-brand-ink underline underline-offset-2">{{ __('Roles & limits reference') }}</a>
                </p>
            </div>
        </x-section-card>

        <x-section-card>
            <x-slot name="header">
                <h2 class="font-semibold text-brand-ink">{{ __('Payment method') }}</h2>
                <p class="text-sm text-brand-moss mt-0.5">{{ __('Default card on file.') }}</p>
            </x-slot>
            <div>
                <p class="text-sm text-brand-ink">{{ $this->paymentSummary }}</p>
            </div>
        </x-section-card>

        @if ($this->canManageBilling)
            <x-section-card>
                <x-slot name="header">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold text-brand-ink">{{ __('Invoices') }}</h2>
                        <p class="text-sm text-brand-moss mt-0.5">{{ __('Recent paid invoices from Stripe.') }}</p>
                    </div>
                    <a href="{{ route('billing.invoices', $organization) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('View all invoices') }}</a>
                    </div>
                </x-slot>
                <div>
                    @if ($this->invoices->isEmpty())
                        <p class="text-sm text-brand-moss">{{ __('No invoices yet, or they could not be loaded.') }}</p>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($this->invoices as $invoice)
                                @php
                                    $stripeInv = $invoice->asStripeInvoice();
                                    $hosted = $stripeInv->hosted_invoice_url ?? null;
                                @endphp
                                <li class="py-3 flex flex-wrap items-center justify-between gap-2 text-sm">
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
            </x-section-card>
        @endif

        <x-section-card>
            <x-slot name="header">
                <h2 class="font-semibold text-brand-ink">{{ __('Actions') }}</h2>
                <p class="text-sm text-brand-moss mt-0.5">{{ __('Move from trial to Pro or manage billing in Stripe.') }}</p>
            </x-slot>
            <div class="flex flex-wrap gap-3">
                @if ($this->plans->isNotEmpty())
                    @foreach ($this->plans as $plan)
                        <button type="button" wire:click="checkout('{{ $plan['id'] }}')" class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90">
                            {{ __('Choose :plan', ['plan' => $plan['name']]) }}
                        </button>
                    @endforeach
                @else
                    <p class="text-sm text-brand-moss">{{ __('No plans configured. Set Stripe price IDs in .env.') }}</p>
                @endif
                @if ($this->canManageBilling)
                    <x-secondary-button type="button" wire:click="portal">
                        {{ __('Manage billing') }}
                    </x-secondary-button>
                @endif
            </div>
        </x-section-card>
    </div>
            </div>
        </x-organization-shell>
    </div>
</div>

