<div>
    <header class="border-b border-slate-200 bg-white">
        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center">
                <h2 class="font-semibold text-xl text-slate-800 leading-tight">
                    Billing — {{ $organization->name }}
                </h2>
                <a href="{{ route('organizations.show', $organization) }}" class="text-slate-600 hover:text-slate-900 text-sm">← Organization</a>
            </div>
        </div>
    </header>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if (request()->query('checkout') === 'success')
                <div class="mb-4 p-4 rounded-md bg-green-50 text-green-800">Subscription updated successfully.</div>
            @endif
            @if (request()->query('checkout') === 'cancelled')
                <div class="mb-4 p-4 rounded-md bg-amber-50 text-amber-800">Checkout was cancelled.</div>
            @endif
            @error('plan')
                <div class="mb-4 p-4 rounded-md bg-red-50 text-red-800">{{ $message }}</div>
            @enderror
            @error('billing')
                <div class="mb-4 p-4 rounded-md bg-red-50 text-red-800">{{ $message }}</div>
            @enderror

            <div class="space-y-6">
                <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-slate-200">
                        <h3 class="font-medium text-slate-900">Subscription</h3>
                        <p class="text-sm text-slate-500">Current plan and status.</p>
                    </div>
                    <div class="px-6 py-4 space-y-2">
                        <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Status</dt>
                                <dd class="mt-0.5 text-sm text-slate-900">
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
                                        No active subscription
                                    @endif
                                </dd>
                            </div>
                            @if ($this->planName)
                                <div>
                                    <dt class="text-sm font-medium text-slate-500">Plan</dt>
                                    <dd class="mt-0.5 text-sm text-slate-900">{{ $this->planName }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                </section>

                <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-slate-200">
                        <h3 class="font-medium text-slate-900">Plan limits &amp; usage</h3>
                        <p class="text-sm text-slate-500">Counts include every server and site in this organization. Pro removes these caps when your Stripe subscription matches the configured Pro prices.</p>
                    </div>
                    <div class="px-6 py-4">
                        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="rounded-md border border-slate-100 bg-slate-50 p-4">
                                <dt class="text-sm font-medium text-slate-500">Servers</dt>
                                <dd class="mt-1 text-lg font-semibold text-slate-900 tabular-nums">
                                    {{ $organization->servers()->count() }}
                                    @if ($organization->maxServers() >= PHP_INT_MAX)
                                        <span class="text-sm font-normal text-slate-600"> / Unlimited</span>
                                    @else
                                        <span class="text-sm font-normal text-slate-600"> / {{ $organization->maxServersDisplay() }} on Free</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="rounded-md border border-slate-100 bg-slate-50 p-4">
                                <dt class="text-sm font-medium text-slate-500">Sites</dt>
                                <dd class="mt-1 text-lg font-semibold text-slate-900 tabular-nums">
                                    {{ $organization->sites()->count() }}
                                    @if ($organization->maxSites() >= PHP_INT_MAX)
                                        <span class="text-sm font-normal text-slate-600"> / Unlimited</span>
                                    @else
                                        <span class="text-sm font-normal text-slate-600"> / {{ $organization->maxSitesDisplay() }} on Free</span>
                                    @endif
                                </dd>
                            </div>
                        </dl>
                        @if ($organization->effectiveMemberSeatCap() !== null)
                            <p class="mt-4 text-sm text-slate-600">
                                <span class="font-medium text-slate-700">Member seats:</span>
                                {{ $organization->users()->count() }} members + {{ $organization->invitations()->where('expires_at', '>', now())->count() }} pending invites
                                (cap {{ $organization->effectiveMemberSeatCap() }}).
                            </p>
                        @endif
                        <p class="mt-4 text-xs text-slate-500">
                            Defaults: <code class="bg-slate-100 px-1 rounded">SUBSCRIPTION_SERVERS_FREE_LIMIT</code>, <code class="bg-slate-100 px-1 rounded">SUBSCRIPTION_SITES_FREE_LIMIT</code>.
                            <a href="{{ route('docs.org-roles-and-limits') }}" class="text-indigo-600 hover:text-indigo-800 underline">Roles &amp; limits reference</a>
                        </p>
                    </div>
                </section>

                <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-slate-200">
                        <h3 class="font-medium text-slate-900">Payment method</h3>
                        <p class="text-sm text-slate-500">Default card on file.</p>
                    </div>
                    <div class="px-6 py-4">
                        <p class="text-sm text-slate-900">{{ $this->paymentSummary }}</p>
                    </div>
                </section>

                @if ($this->canManageBilling)
                    <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div class="px-6 py-4 border-b border-slate-200">
                            <h3 class="font-medium text-slate-900">Invoices</h3>
                            <p class="text-sm text-slate-500">Recent paid invoices from Stripe.</p>
                        </div>
                        <div class="px-6 py-4">
                            @if ($this->invoices->isEmpty())
                                <p class="text-sm text-slate-500">No invoices yet, or they could not be loaded.</p>
                            @else
                                <ul class="divide-y divide-slate-100">
                                    @foreach ($this->invoices as $invoice)
                                        @php
                                            $stripeInv = $invoice->asStripeInvoice();
                                            $hosted = $stripeInv->hosted_invoice_url ?? null;
                                        @endphp
                                        <li class="py-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                                            <div>
                                                <span class="font-medium text-slate-900">{{ $invoice->date()->toFormattedDateString() }}</span>
                                                <span class="text-slate-500 ms-2">{{ $invoice->total() }}</span>
                                            </div>
                                            @if ($hosted)
                                                <a href="{{ $hosted }}" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">View in Stripe</a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </section>
                @endif

                <section class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="px-6 py-4 border-b border-slate-200">
                        <h3 class="font-medium text-slate-900">Actions</h3>
                        <p class="text-sm text-slate-500">Subscribe or manage billing in Stripe.</p>
                    </div>
                    <div class="px-6 py-4 flex flex-wrap gap-3">
                        @if ($this->plans->isNotEmpty())
                            @foreach ($this->plans as $plan)
                                <button type="button" wire:click="checkout('{{ $plan['id'] }}')" class="inline-flex items-center px-4 py-2 bg-slate-900 border border-transparent rounded-md font-semibold text-xs text-white hover:bg-slate-800">
                                    Subscribe — {{ $plan['name'] }}
                                </button>
                            @endforeach
                        @else
                            <p class="text-sm text-slate-500">No plans configured. Set Stripe price IDs in .env.</p>
                        @endif
                        @if ($this->canManageBilling)
                            <button type="button" wire:click="portal" class="inline-flex items-center px-4 py-2 bg-slate-100 border border-transparent rounded-md font-semibold text-xs text-slate-700 hover:bg-slate-200">
                                Manage billing
                            </button>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
