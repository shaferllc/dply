<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="billing">
            <div>
                <x-livewire-validation-errors />

                <header class="mb-8">
                    <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Billing & plan') }}</h1>
                    <p class="mt-2 text-sm text-brand-moss max-w-2xl leading-relaxed">
                        {{ __('Plan status, payment method, usage limits, and Stripe checkout for :org.', ['org' => $organization->name]) }}
                    </p>
                </header>

    @if (request()->query('checkout') === 'success')
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">{{ __('Subscription updated successfully.') }}</div>
    @endif
    @if (request()->query('checkout') === 'cancelled')
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status">{{ __('Checkout was cancelled.') }}</div>
    @endif
    @error('plan')
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ $message }}</div>
    @enderror
    @error('billing')
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ $message }}</div>
    @enderror

    <div class="space-y-6">
        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-brand-ink/10 bg-brand-sand/30">
                <h2 class="font-semibold text-brand-ink">{{ __('Subscription') }}</h2>
                <p class="text-sm text-brand-moss mt-0.5">{{ __('Current plan and status.') }}</p>
            </div>
            <div class="px-6 py-4 space-y-2">
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
                                {{ __('No active subscription') }}
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
        </section>

        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-brand-ink/10 bg-brand-sand/30">
                <h2 class="font-semibold text-brand-ink">{{ __('Plan limits & usage') }}</h2>
                <p class="text-sm text-brand-moss mt-0.5">{{ __('Counts include every server and site in this organization. Pro removes these caps when your Stripe subscription matches the configured Pro prices.') }}</p>
            </div>
            <div class="px-6 py-4">
                <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                        <dt class="text-sm font-medium text-brand-moss">{{ __('Servers') }}</dt>
                        <dd class="mt-1 text-lg font-semibold text-brand-ink tabular-nums">
                            {{ $organization->servers()->count() }}
                            @if ($organization->maxServers() >= PHP_INT_MAX)
                                <span class="text-sm font-normal text-brand-moss"> / {{ __('Unlimited') }}</span>
                            @else
                                <span class="text-sm font-normal text-brand-moss"> / {{ $organization->maxServersDisplay() }} {{ __('on Free') }}</span>
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
                                <span class="text-sm font-normal text-brand-moss"> / {{ $organization->maxSitesDisplay() }} {{ __('on Free') }}</span>
                            @endif
                        </dd>
                    </div>
                </dl>
                @if ($organization->effectiveMemberSeatCap() !== null)
                    <p class="mt-4 text-sm text-brand-moss">
                        <span class="font-medium text-brand-ink">{{ __('Member seats:') }}</span>
                        {{ $organization->users()->count() }} {{ __('members') }} + {{ $organization->invitations()->where('expires_at', '>', now())->count() }} {{ __('pending invites') }}
                        ({{ __('cap') }} {{ $organization->effectiveMemberSeatCap() }}).
                    </p>
                @endif
                <p class="mt-4 text-xs text-brand-mist">
                    {{ __('Defaults:') }} <code class="rounded bg-brand-sand/60 px-1 py-0.5 text-brand-ink">SUBSCRIPTION_SERVERS_FREE_LIMIT</code>, <code class="rounded bg-brand-sand/60 px-1 py-0.5 text-brand-ink">SUBSCRIPTION_SITES_FREE_LIMIT</code>.
                    <a href="{{ route('docs.org-roles-and-limits') }}" class="font-medium text-brand-sage hover:text-brand-ink underline underline-offset-2">{{ __('Roles & limits reference') }}</a>
                </p>
            </div>
        </section>

        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-brand-ink/10 bg-brand-sand/30">
                <h2 class="font-semibold text-brand-ink">{{ __('Payment method') }}</h2>
                <p class="text-sm text-brand-moss mt-0.5">{{ __('Default card on file.') }}</p>
            </div>
            <div class="px-6 py-4">
                <p class="text-sm text-brand-ink">{{ $this->paymentSummary }}</p>
            </div>
        </section>

        @if ($this->canManageBilling)
            <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-brand-ink/10 bg-brand-sand/30 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold text-brand-ink">{{ __('Invoices') }}</h2>
                        <p class="text-sm text-brand-moss mt-0.5">{{ __('Recent paid invoices from Stripe.') }}</p>
                    </div>
                    <a href="{{ route('billing.invoices', $organization) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('View all invoices') }}</a>
                </div>
                <div class="px-6 py-4">
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
            </section>
        @endif

        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-brand-ink/10 bg-brand-sand/30">
                <h2 class="font-semibold text-brand-ink">{{ __('Actions') }}</h2>
                <p class="text-sm text-brand-moss mt-0.5">{{ __('Subscribe or manage billing in Stripe.') }}</p>
            </div>
            <div class="px-6 py-4 flex flex-wrap gap-3">
                @if ($this->plans->isNotEmpty())
                    @foreach ($this->plans as $plan)
                        <button type="button" wire:click="checkout('{{ $plan['id'] }}')" class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90">
                            {{ __('Subscribe — :plan', ['plan' => $plan['name']]) }}
                        </button>
                    @endforeach
                @else
                    <p class="text-sm text-brand-moss">{{ __('No plans configured. Set Stripe price IDs in .env.') }}</p>
                @endif
                @if ($this->canManageBilling)
                    <button type="button" wire:click="portal" class="inline-flex items-center rounded-xl border border-brand-ink/15 bg-brand-sand/40 px-4 py-2.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/60">
                        {{ __('Manage billing') }}
                    </button>
                @endif
            </div>
        </section>
    </div>
            </div>
        </x-organization-shell>
    </div>
</div>

