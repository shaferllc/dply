<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @php
            // This page answers one question — "what will I be charged this month,
            // and why is it changing" — so it leads with one number. It used to
            // show five different totals (header estimate, observatory platform,
            // observatory full-stack, forecast, and the category tfoot) with
            // nothing saying which was the answer. The forecast is the answer;
            // everything else is either how it breaks down or reference.
            $interval = $summary['interval'] ?? 'month';
            $monthlyCents = (int) ($summary['monthly_total_cents'] ?? 0);
            $projectedCents = (int) ($forecast['projected_month_end_cents'] ?? 0);
            $deltaCents = $forecast['delta_vs_thirty_days_cents'] ?? null;
            $dailyRunRateCents = (int) ($summary['daily_run_rate_cents'] ?? 0);

            $spendTrendThirty = is_array($spendTrend['series_30'] ?? null) ? $spendTrend['series_30'] : [];
            $spendTrendNinety = is_array($spendTrend['series_90'] ?? null) ? $spendTrend['series_90'] : [];
            $maxSpendTrendCents = max(1, collect($spendTrendNinety)->max('total_cents') ?? 1);

            $totalBreakdownCents = max(1, collect($categoryBreakdown)->sum('cents'));

            // Observatory. Kept, but as one collapsed line rather than three tiles
            // competing with the headline number: your provider bill is real, and
            // it is not what dply is charging you.
            $obsDplyCents = (int) ($costObservatory['dply_platform_cents'] ?? 0);
            $obsProviderCents = (int) ($costObservatory['provider_infrastructure_cents'] ?? 0);
            $obsStackCents = (int) ($costObservatory['stack_total_cents'] ?? 0);
            $obsUnknown = (int) ($costObservatory['provider_unknown_count'] ?? 0);
            $obsServers = is_array($costObservatory['servers'] ?? null) ? $costObservatory['servers'] : [];
            $showObservatory = cost_observatory_active($organization) && $obsServers !== [];

            // Status line under the headline. Not a tile of its own — the only
            // thing a reader needs from it is whether the number is a real bill
            // or an estimate, and when it lands.
            if (! empty($summary['subscribed'])) {
                $statusDot = 'bg-brand-sage';
                $statusNote = ! empty($summary['next_invoice_at'])
                    ? __('Next invoice :date', ['date' => \Illuminate\Support\Carbon::parse($summary['next_invoice_at'])->toFormattedDateString()])
                    : ($interval === 'year' ? __('Billed annually') : __('Billed monthly'));
            } elseif (! empty($summary['on_trial'])) {
                $statusDot = 'bg-sky-500';
                $days = (int) ($summary['trial_days_left'] ?? 0);
                $statusNote = __('Trial').' · '.trans_choice(':days day left|:days days left', $days, ['days' => $days]);
            } else {
                $statusDot = 'bg-brand-ink/15';
                $statusNote = __('Estimate only until you add a plan');
            }

            $th = 'px-3 py-1.5 sm:px-4';
            $td = 'px-3 py-2 sm:px-4';
            $summaryRow = 'flex cursor-pointer list-none items-center gap-1.5 px-3 py-2 text-xs font-semibold text-brand-moss transition-colors hover:bg-brand-sand/20 hover:text-brand-ink sm:px-4';
        @endphp

        <x-organization-shell
            dense
            :organization="$organization"
            section="billing-analytics"
            :title="__('Billing analytics')"
            :description="__('What you\'re on track to pay this month, and what\'s driving it.')"
            icon="heroicon-o-chart-bar"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Billing & plan'), 'href' => route('billing.show', $organization), 'icon' => 'credit-card'],
                ['label' => __('Trends'), 'icon' => 'chart-bar'],
            ]"
        >
            <x-slot:tabs>
                <x-billing.tabs :organization="$organization" active="analytics" />
            </x-slot:tabs>

            <x-slot:actions>
                <x-outline-link size="xxs" href="{{ route('billing.invoices', $organization) }}" wire:navigate>
                    <x-heroicon-o-document class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Invoices') }}
                </x-outline-link>
            </x-slot:actions>

            {{-- The answer, first and once. --}}
            <section class="border-b border-brand-ink/10 px-3 py-4 sm:px-4">
                <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Projected this month') }}</p>
                <p class="mt-1 font-mono text-4xl font-semibold leading-none tabular-nums text-brand-ink">
                    ${{ number_format($projectedCents / 100, 2) }}
                </p>
                <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                    @if (is_int($deltaCents))
                        <span class="font-semibold {{ $deltaCents >= 0 ? 'text-brand-rust' : 'text-brand-forest' }}">
                            {{ $deltaCents >= 0 ? '+' : '−' }}${{ number_format(abs($deltaCents) / 100, 2) }}
                        </span>
                        <span>{{ __('vs the last 30 days') }}</span>
                    @else
                        <span class="text-brand-mist">{{ __('Trend appears once snapshots accumulate') }}</span>
                    @endif
                    <span class="text-brand-mist" aria-hidden="true">·</span>
                    <span>{{ __('Run rate $:n/day', ['n' => number_format($dailyRunRateCents / 100, 2)]) }}</span>
                    <span class="text-brand-mist" aria-hidden="true">·</span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block h-2 w-2 shrink-0 rounded-full {{ $statusDot }}" aria-hidden="true"></span>
                        {{ $statusNote }}
                    </span>
                </p>
            </section>

            {{-- Where it goes. The bar and its legend say in one glance what the
                 line-item table says in eleven rows, so the table stays folded. --}}
            <section class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-chart-pie"
                    :title="__('Where it goes')"
                    :note="__('Current-cycle estimate — updates when your fleet changes.')"
                />

                @if ($categoryBreakdown === [])
                    <p class="px-3 py-6 text-center text-sm text-brand-moss sm:px-4">{{ __('Nothing billable yet.') }}</p>
                @else
                    <div class="space-y-2 px-3 py-3 sm:px-4">
                        <div class="flex h-3 w-full overflow-hidden rounded-full bg-brand-cream/80">
                            @foreach ($categoryBreakdown as $segment)
                                @if (($segment['cents'] ?? 0) > 0)
                                    <div
                                        class="{{ $segment['color'] ?? 'bg-brand-moss' }} min-w-[2px]"
                                        style="width: {{ max(2, round(($segment['cents'] / $totalBreakdownCents) * 100, 1)) }}%"
                                        title="{{ $segment['label'] }} — ${{ number_format($segment['cents'] / 100, 2) }}"
                                    ></div>
                                @endif
                            @endforeach
                        </div>

                        <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-brand-moss">
                            @foreach ($categoryBreakdown as $segment)
                                @if (($segment['cents'] ?? 0) > 0)
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2.5 w-2.5 rounded-sm {{ $segment['color'] ?? 'bg-brand-moss' }}"></span>
                                        {{ $segment['label'] }} · <span class="font-mono tabular-nums">${{ number_format($segment['cents'] / 100, 2) }}</span>
                                    </span>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            {{-- Trend. --}}
            <section class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-presentation-chart-line"
                    :title="__('Trend')"
                    :note="__('Daily billing snapshots for the last 90 days.')"
                />

                @if ($spendTrendNinety === [])
                    <p class="px-3 py-6 text-center text-sm text-brand-moss sm:px-4">
                        {{ __('No snapshots yet. Daily snapshots populate this trend automatically.') }}
                    </p>
                @else
                    <div class="px-3 py-3 sm:px-4">
                        <div class="flex h-16 items-end gap-1" aria-hidden="true">
                            @foreach ($spendTrendNinety as $day)
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="w-full rounded-t bg-brand-ink/25 transition-colors hover:bg-brand-ink/45"
                                        style="height: {{ max(4, round(($day['total_cents'] / $maxSpendTrendCents) * 100)) }}%"
                                        title="{{ $day['label'] }} — ${{ number_format(($day['total_cents'] ?? 0) / 100, 2) }}"
                                    ></div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-1.5 flex items-baseline justify-between font-mono text-2xs text-brand-mist">
                            <span>{{ $spendTrendNinety[0]['label'] ?? '' }}</span>
                            <span>{{ trans_choice(':count day|:count days', count($spendTrendNinety), ['count' => count($spendTrendNinety)]) }}</span>
                            <span>{{ end($spendTrendNinety)['label'] ?? '' }}</span>
                        </div>
                    </div>
                @endif
            </section>

            {{-- Reference below the fold. Everything here is a table someone
                 occasionally audits, not something to read on arrival. --}}
            @if ($lineItems !== [])
                <details class="group border-b border-brand-ink/10">
                    <summary class="{{ $summaryRow }}">
                        <x-heroicon-o-chevron-right class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
                        {{ __('Line items') }}
                        <span class="font-normal text-brand-mist">({{ count($lineItems) }})</span>
                    </summary>
                    <table class="w-full border-t border-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/35 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                            <tr>
                                <th class="{{ $th }} text-left">{{ __('Line item') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Qty') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Unit') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Monthly') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($lineItems as $item)
                                <tr class="transition-colors hover:bg-brand-sand/15">
                                    <td class="{{ $td }} text-brand-ink">
                                        {{ $item['label'] }}
                                        @if (! empty($item['detail']))
                                            <span class="block text-xs text-brand-moss">{{ $item['detail'] }}</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }} text-right font-mono tabular-nums text-brand-moss">{{ $item['quantity'] }}</td>
                                    <td class="{{ $td }} text-right font-mono tabular-nums text-brand-moss">${{ number_format($item['unit_cents'] / 100, 2) }}</td>
                                    <td class="{{ $td }} text-right font-mono font-semibold tabular-nums text-brand-ink">${{ number_format($item['line_cents'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-brand-sand/30">
                            <tr>
                                <td colspan="3" class="{{ $td }} text-right text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Estimated total') }}</td>
                                <td class="{{ $td }} text-right font-mono font-semibold tabular-nums text-brand-ink">${{ number_format($monthlyCents / 100, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </details>
            @endif

            @if ($showObservatory)
                <details class="group border-b border-brand-ink/10">
                    <summary class="{{ $summaryRow }}">
                        <x-heroicon-o-chevron-right class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
                        {{ __('Your provider costs') }}
                        <span class="font-normal text-brand-mist">
                            @if ($obsProviderCents > 0)
                                {{ __('(:n/mo to your cloud, on top of ours)', ['n' => '$'.number_format($obsProviderCents / 100, 2)]) }}
                            @else
                                {{ __('(unknown — add cost notes on your servers)') }}
                            @endif
                        </span>
                    </summary>

                    <p class="border-t border-brand-ink/10 bg-brand-sand/15 px-3 py-2 text-xs leading-relaxed text-brand-moss sm:px-4">
                        {{ __('We bill our work; you pay your cloud provider directly.') }}
                        <span class="font-mono tabular-nums text-brand-ink">${{ number_format($obsDplyCents / 100, 2) }}</span> {{ __('from us') }}
                        + <span class="font-mono tabular-nums text-brand-ink">${{ number_format($obsProviderCents / 100, 2) }}</span> {{ __('from them') }}
                        = <span class="font-mono font-semibold tabular-nums text-brand-forest">${{ number_format($obsStackCents / 100, 2) }}</span> {{ __('full stack') }}@if ($obsUnknown > 0)<span class="text-brand-mist">, {{ trans_choice(':count server needs a cost note|:count servers need cost notes', $obsUnknown, ['count' => $obsUnknown]) }}</span>@endif.
                    </p>

                    <table class="w-full border-t border-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/35 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                            <tr>
                                <th class="{{ $th }} text-left">{{ __('Server') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Provider / plan') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Source') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Est. /mo') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($obsServers as $obsServer)
                                <tr class="transition-colors hover:bg-brand-sand/15">
                                    <td class="{{ $td }} font-medium text-brand-ink">{{ $obsServer['name'] }}</td>
                                    <td class="{{ $td }} text-brand-moss">
                                        {{ $obsServer['provider'] ?? '—' }}
                                        @if (! empty($obsServer['plan']))
                                            <span class="text-brand-mist">· {{ $obsServer['plan'] }}</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }} text-xs text-brand-moss">
                                        @switch($obsServer['source'] ?? 'unknown')
                                            @case('note')
                                                {{ __('Saved note') }}
                                                @break
                                            @case('catalog')
                                                {{ __('Provider catalog') }}
                                                @break
                                            @default
                                                {{ $obsServer['detail'] ?? __('Add cost note on server') }}
                                        @endswitch
                                    </td>
                                    <td class="{{ $td }} text-right font-mono tabular-nums text-brand-ink">
                                        @if (($obsServer['monthly_usd_cents'] ?? 0) > 0)
                                            ${{ number_format($obsServer['monthly_usd_cents'] / 100, 2) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if (! empty($costObservatory['disclaimer']))
                        <p class="px-3 py-2 text-xs leading-relaxed text-brand-mist sm:px-4">{{ $costObservatory['disclaimer'] }}</p>
                    @endif
                </details>
            @endif

            @if ($spendTrendThirty !== [])
                <details class="group border-b border-brand-ink/10">
                    <summary class="{{ $summaryRow }}">
                        <x-heroicon-o-chevron-right class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
                        {{ __('Daily detail') }}
                        <span class="font-normal text-brand-mist">{{ __('last 30 days') }}</span>
                    </summary>
                    <table class="w-full border-t border-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/35 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                            <tr>
                                <th class="{{ $th }} text-left">{{ __('Date') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($spendTrendThirty as $day)
                                <tr class="transition-colors hover:bg-brand-sand/15">
                                    <td class="{{ $td }} text-brand-ink">{{ \Illuminate\Support\Carbon::parse($day['date'])->toFormattedDateString() }}</td>
                                    <td class="{{ $td }} text-right font-mono tabular-nums text-brand-ink">${{ number_format(($day['total_cents'] ?? 0) / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @endif

            @if ($invoiceHistory !== [])
                {{-- Six rows and a link out. /billing/invoices is the archive; this
                     is only here so "did last month look like this?" is one click. --}}
                <details class="group border-b border-brand-ink/10 last:border-b-0">
                    <summary class="{{ $summaryRow }}">
                        <x-heroicon-o-chevron-right class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
                        {{ __('Recent invoices') }}
                        <span class="font-normal text-brand-mist">{{ __('what you were actually charged') }}</span>
                    </summary>
                    <table class="w-full border-t border-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/35 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                            <tr>
                                <th class="{{ $th }} text-left">{{ __('Date') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Number') }}</th>
                                <th class="{{ $th }} text-left">{{ __('Status') }}</th>
                                <th class="{{ $th }} text-right">{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach (array_slice($invoiceHistory, 0, 6) as $invoice)
                                <tr class="transition-colors hover:bg-brand-sand/15">
                                    <td class="{{ $td }} text-brand-ink">
                                        {{ ($invoice['date'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($invoice['date'])->toFormattedDateString() : '—' }}
                                    </td>
                                    <td class="{{ $td }} font-mono text-xs text-brand-moss">{{ $invoice['number'] ?? '—' }}</td>
                                    <td class="{{ $td }} capitalize text-brand-moss">{{ $invoice['status'] ?? '—' }}</td>
                                    <td class="{{ $td }} text-right font-mono font-semibold tabular-nums text-brand-ink">${{ number_format($invoice['total_cents'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="px-3 py-2 text-xs sm:px-4">
                        <a href="{{ route('billing.invoices', $organization) }}" wire:navigate class="font-semibold text-brand-sage hover:text-brand-ink">
                            {{ __('All invoices') }} →
                        </a>
                    </p>
                </details>
            @endif
        </x-organization-shell>
    </div>
</div>
