@php
    $showBilling = ($edgeUsageBillingEnabled ?? false) || (($edgeManagedFee ?? 0) > 0);
@endphp

@if ($showBilling)
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Billing & usage') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Managed Edge sites are billed per live site on your organization plan.') }}</p>
        </div>
        <div class="space-y-3 px-6 py-5 text-sm sm:px-8">
            @if (($edgeManagedFee ?? 0) > 0)
                <p class="text-brand-ink">
                    <span class="font-semibold">${{ number_format($edgeManagedFee, 2) }}</span>
                    <span class="text-brand-moss">/ {{ __('month per live Edge site') }}</span>
                </p>
            @endif
            @if ($edgeUsageBillingEnabled ?? false)
                @php
                    $rates = app(\App\Services\Billing\ManagedProductCostEstimator::class)->edgeUsageRates();
                @endphp
                <p class="text-brand-moss">{{ __('Usage beyond included quotas is metered on requests and egress.') }}</p>
                <ul class="mt-2 space-y-1 text-xs text-brand-moss">
                    @if (($rates['requests_per_million'] ?? 0) > 0)
                        <li>{{ __(':price per million requests', ['price' => '$'.number_format($rates['requests_per_million'], 2)]) }}</li>
                    @endif
                    @if (($rates['egress_per_gb'] ?? 0) > 0)
                        <li>{{ __(':price per GB egress', ['price' => '$'.number_format($rates['egress_per_gb'], 2)]) }}</li>
                    @endif
                </ul>
            @endif
            <a href="{{ route('billing.show', auth()->user()?->currentOrganization()) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-brand-forest hover:underline dark:text-brand-sage">
                {{ __('View organization billing →') }}
                <x-heroicon-o-arrow-right class="h-3.5 w-3.5" />
            </a>
        </div>
    </section>
@endif
