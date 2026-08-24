{{-- Band 01 — What you owe.
     Replaces the old bill-hero. Every line here sums to the total; the hero it
     replaced showed a $9.00 breakdown under a $24.00 total because Realtime,
     Queue and Logs had no line item (see Show::getTierLineItemsProperty). --}}
@php
    $lineItems = $this->tierLineItems;
    $monthlyCents = (int) $this->billingState->monthlyTotalCents;
    $yearlyCents = $this->yearlyTotalCents;
    $annualSavingCents = max(0, ($monthlyCents * 12) - $yearlyCents);
@endphp

<section class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        dense
        class="border-b border-brand-ink/10"
        icon="heroicon-o-receipt-percent"
        :title="__('What you owe')"
        :note="$this->onDplyTrial ? __('Nothing is charged during the trial.') : __('Your current monthly charges.')"
    />

    <div class="flex flex-wrap items-end justify-between gap-4 px-3 pt-4 sm:px-4">
        <p class="font-mono text-4xl font-semibold leading-none tracking-tight text-brand-ink">
            ${{ number_format($monthlyCents / 100, 2) }}<span class="ms-1.5 font-sans text-sm font-medium text-brand-moss">{{ __('/mo') }}</span>
        </p>
        @if ($annualSavingCents > 0)
            <p class="text-end text-xs leading-relaxed text-brand-moss">
                {{ __(':amount/yr on annual billing', ['amount' => '$'.number_format($yearlyCents / 100, 2)]) }}<br>
                <span class="font-semibold text-brand-forest">{{ __('saves :amount a year', ['amount' => '$'.number_format($annualSavingCents / 100, 2)]) }}</span>
            </p>
        @endif
    </div>

    <div class="overflow-x-auto px-3 pb-4 pt-4 sm:px-4">
        <table class="w-full min-w-[26rem] border-collapse text-sm">
            <thead>
                <tr class="border-b border-brand-ink/10">
                    <th scope="col" class="pb-2 text-start text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Item') }}</th>
                    <th scope="col" class="pb-2 pe-3 text-end text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Qty') }}</th>
                    <th scope="col" class="pb-2 text-end text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-ink/10">
                @foreach ($lineItems as $item)
                    <tr wire:key="line-{{ $loop->index }}">
                        <td class="py-2.5 pe-3">
                            <p class="text-sm text-brand-ink">{{ $item['label'] }}</p>
                            @if (! empty($item['detail']))
                                <p class="mt-0.5 text-xs text-brand-moss">{{ $item['detail'] }}</p>
                            @endif
                        </td>
                        <td class="py-2.5 pe-3 text-end font-mono text-sm tabular-nums text-brand-moss">{{ $item['quantity'] }}</td>
                        <td class="py-2.5 text-end font-mono text-sm tabular-nums text-brand-ink">${{ number_format((int) $item['line_cents'] / 100, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-brand-ink">
                    <td class="pt-3 text-sm font-semibold text-brand-ink">{{ __('Total') }}</td>
                    <td></td>
                    <td class="pt-3 text-end font-mono text-base font-semibold tabular-nums text-brand-ink">${{ number_format($monthlyCents / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- Subscribe CTA. Lived in bill-hero, which this band replaced — the most
         important action on the page for an unsubscribed org, so it stays
         directly under the total rather than in the Payment method section. --}}
    @if (! $this->subscription)
        <div class="border-t border-brand-ink/10 px-3 py-3 sm:px-4">
            <div class="flex flex-col gap-2 sm:flex-row">
                <button
                    type="button"
                    wire:click="subscribeStandard('month')"
                    class="inline-flex items-center justify-center rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                >
                    {{ __('Subscribe — :amount/mo', ['amount' => '$'.number_format($monthlyCents / 100, 2)]) }}
                </button>
                <button
                    type="button"
                    wire:click="subscribeStandard('year')"
                    class="inline-flex items-center justify-center rounded-lg border-2 border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink transition-colors hover:border-brand-gold/40"
                >
                    {{ __('Pay yearly — save 20%') }}
                </button>
            </div>
            <p class="mt-2 text-xs text-brand-moss">
                @if ($this->standardPricingAvailable)
                    {{ __('Secure checkout via Stripe. Cancel anytime.') }}
                @else
                    {{ __('Stripe prices are not configured on this install, so checkout will fail until they are set.') }}
                @endif
            </p>
        </div>
    @endif
</section>
