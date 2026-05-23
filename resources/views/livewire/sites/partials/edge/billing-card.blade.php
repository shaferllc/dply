@php
    $billing = $edgeSiteBilling ?? null;
    $showBilling = ($edgeUsageBillingEnabled ?? false) || (($edgeManagedFee ?? 0) > 0) || $billing !== null;
@endphp

@if ($showBilling)
    <section class="dply-card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <div>
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Billing & usage') }}</h3>
                <p class="mt-0.5 text-sm text-brand-moss">
                    @if ($billing !== null)
                        {{ __('Est. :total / mo · :requests requests MTD', [
                            'total' => '$'.number_format(($billing['total_cents'] ?? 0) / 100, 2),
                            'requests' => number_format($billing['requests'] ?? 0),
                        ]) }}
                    @else
                        {{ __('Platform fee plus metered delivery when enabled.') }}
                    @endif
                </p>
            </div>
            <a
                href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'edge-billing']) }}"
                wire:navigate
                class="inline-flex items-center gap-1 text-sm font-medium text-brand-forest hover:underline dark:text-brand-sage"
            >
                {{ __('View stats') }}
                <x-heroicon-o-arrow-right class="h-4 w-4" />
            </a>
        </div>
    </section>
@endif
