<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="billing">
            <x-livewire-validation-errors />

            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Billing & plan'), 'icon' => 'rectangle-stack'],
            ]" />

            <div class="space-y-8">
                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Billing & plan') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Trial status, Pro billing, usage limits, and Stripe checkout for :org.', ['org' => $organization->name]) }}
                            </p>
                        </div>
                        <div class="lg:col-span-8 flex flex-wrap items-start justify-end gap-3">
                            <x-outline-link href="{{ route('docs.index') }}" wire:navigate>
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Documentation') }}
                            </x-outline-link>
                        </div>
                    </div>
                </div>

                @if (request()->query('checkout') === 'success')
                    <x-alert tone="success">{{ __('Subscription updated successfully.') }}</x-alert>
                @endif
                @if (request()->query('checkout') === 'cancelled')
                    <x-alert tone="warning">{{ __('Checkout was cancelled.') }}</x-alert>
                @endif
                @error('plan')
                    <x-alert tone="error">{{ $message }}</x-alert>
                @enderror
                @error('billing')
                    <x-alert tone="error">{{ $message }}</x-alert>
                @enderror

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Subscription') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Current trial or subscription status.') }}</p>
                        </div>
                        <div class="lg:col-span-8">
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
                    </div>
                </div>

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Plan limits & usage') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Counts include every server and site in this organization. Trial limits apply org-wide until your Stripe subscription matches the configured Pro prices.') }}
                            </p>
                        </div>
                        <div class="lg:col-span-8 space-y-4">
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
                            <p class="text-xs text-brand-mist">
                                <a href="{{ route('docs.markdown', ['slug' => 'org-roles-and-limits']) }}" wire:navigate class="font-medium text-brand-sage hover:text-brand-ink underline underline-offset-2">{{ __('Roles & limits reference') }}</a>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Payment method') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Default card on file.') }}</p>
                        </div>
                        <div class="lg:col-span-8">
                            <p class="text-sm text-brand-ink">{{ $this->paymentSummary }}</p>
                        </div>
                    </div>
                </div>

                @if ($this->canManageBilling)
                    <div class="dply-card overflow-hidden">
                        <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                            <div class="lg:col-span-4">
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Invoices') }}</h2>
                                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Recent paid invoices from Stripe.') }}</p>
                            </div>
                            <div class="lg:col-span-8 space-y-4 min-w-0">
                                <div class="flex justify-end">
                                    <a href="{{ route('billing.invoices', $organization) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink">
                                        {{ __('View all invoices') }}
                                    </a>
                                </div>
                                @if ($this->invoices->isEmpty())
                                    <p class="text-sm text-brand-moss">{{ __('No invoices yet, or they could not be loaded.') }}</p>
                                @else
                                    <ul class="divide-y divide-brand-mist/80 rounded-xl border border-brand-mist overflow-hidden bg-white">
                                        @foreach ($this->invoices as $invoice)
                                            @php
                                                $stripeInv = $invoice->asStripeInvoice();
                                                $hosted = $stripeInv->hosted_invoice_url ?? null;
                                            @endphp
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

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Actions') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Move from trial to Pro or manage billing in Stripe.') }}</p>
                        </div>
                        <div class="lg:col-span-8">
                            <div class="flex flex-wrap gap-3">
                                @if ($this->plans->isNotEmpty())
                                    @foreach ($this->plans as $plan)
                                        <button type="button" wire:click="checkout('{{ $plan['id'] }}')" class="inline-flex items-center rounded-xl border border-transparent bg-brand-ink px-5 py-2.5 text-xs font-semibold text-brand-cream shadow-md hover:bg-brand-forest">
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
                        </div>
                    </div>
                </div>
            </div>
        </x-organization-shell>
    </div>
</div>
