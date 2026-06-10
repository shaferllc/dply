<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="billing-analytics" :breadcrumb="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
            ['label' => __('Billing analytics'), 'icon' => 'chart-bar'],
        ]">
            @php
                $interval = $summary['interval'] ?? 'month';
                $monthlyCents = (int) ($summary['monthly_total_cents'] ?? 0);
                $yearlyCents = (int) ($summary['yearly_total_cents'] ?? 0);
                $displayCents = $interval === 'year' ? $yearlyCents : $monthlyCents;
                $forecastMrrCents = (int) ($forecast['mrr_cents'] ?? 0);
                $forecastArrCents = (int) ($forecast['arr_cents'] ?? 0);
                $forecastProjectedMonthEndCents = (int) ($forecast['projected_month_end_cents'] ?? 0);
                $forecastDeltaVsThirtyDays = $forecast['delta_vs_thirty_days_cents'] ?? null;
                $spendTrendThirty = is_array($spendTrend['series_30'] ?? null) ? $spendTrend['series_30'] : [];
                $spendTrendNinety = is_array($spendTrend['series_90'] ?? null) ? $spendTrend['series_90'] : [];
                $totalBreakdownCents = max(1, collect($categoryBreakdown)->sum('cents'));
                $maxSpendTrendCents = max(1, collect($spendTrendNinety)->max('total_cents') ?? 1);
                $maxEdgeRequests = max(1, collect($edgeUsageDaily)->max('requests') ?? 1);
                $maxInvoiceCents = max(1, collect($invoiceHistory)->max('total_cents') ?? 1);

                // Surface flags — hide Edge / Cloud / Serverless lines and
                // sections when those surfaces aren't enabled for this org.
                // The numbers come from the controller (which doesn't know
                // about flags), so we filter at render time and only show
                // what's actually a product for this account.
                $edgeOn = \Laravel\Pennant\Feature::active('surface.edge');
                $cloudOn = \Laravel\Pennant\Feature::active('surface.cloud');
                $serverlessOn = \Laravel\Pennant\Feature::active('surface.serverless');
                $hasManagedSurfaces = $edgeOn || $cloudOn || $serverlessOn;

                // Resource-count parts the hero stat tile shows. We only
                // include rows for surfaces the org actually has access to.
                $resourceParts = [
                    ['count' => $summary['server_count'] ?? 0, 'label' => __('VM'), 'visible' => true],
                    ['count' => $summary['edge_count'] ?? 0, 'label' => __('Edge'), 'visible' => $edgeOn],
                    ['count' => $summary['cloud_count'] ?? 0, 'label' => __('Cloud'), 'visible' => $cloudOn],
                    ['count' => $summary['serverless_count'] ?? 0, 'label' => __('Fn'), 'visible' => $serverlessOn],
                ];
                $billableResources = collect($resourceParts)
                    ->filter(fn (array $p) => $p['visible'])
                    ->sum('count');

                // Trim category breakdown + line items to drop the off-
                // surface rows. Match on labels rather than slugs because
                // the controller emits plain labels — matches "Edge",
                // "Cloud", "Serverless".
                $categoryBreakdown = collect($categoryBreakdown)
                    ->reject(function ($segment) use ($edgeOn, $cloudOn, $serverlessOn) {
                        $label = strtolower((string) ($segment['label'] ?? ''));
                        return (! $edgeOn && str_contains($label, 'edge'))
                            || (! $cloudOn && str_contains($label, 'cloud'))
                            || (! $serverlessOn && str_contains($label, 'serverless'));
                    })
                    ->values()
                    ->all();
                $lineItems = collect($lineItems)
                    ->reject(function ($item) use ($edgeOn, $cloudOn, $serverlessOn) {
                        $label = strtolower((string) ($item['label'] ?? ''));
                        return (! $edgeOn && str_contains($label, 'edge'))
                            || (! $cloudOn && str_contains($label, 'cloud'))
                            || (! $serverlessOn && str_contains($label, 'serverless'));
                    })
                    ->values()
                    ->all();
                $totalBreakdownCents = max(1, collect($categoryBreakdown)->sum('cents'));

                // Subscription status palette mirrors billing.show — same
                // tile/dot tokens so an admin reading both pages sees a
                // consistent visual vocabulary.
                if (! empty($summary['subscribed'])) {
                    $statusTone = 'success';
                    $statusLabel = ucfirst((string) ($summary['stripe_status'] ?? __('Active')));
                    $statusSub = ! empty($summary['next_invoice_at'])
                        ? __('Next invoice :date', ['date' => \Illuminate\Support\Carbon::parse($summary['next_invoice_at'])->toFormattedDateString()])
                        : ($interval === 'year' ? __('Billed annually') : __('Billed monthly'));
                } elseif (! empty($summary['on_trial'])) {
                    $statusTone = 'info';
                    $statusLabel = __('Trial');
                    $days = (int) ($summary['trial_days_left'] ?? 0);
                    $statusSub = trans_choice(':days day left|:days days left', $days, ['days' => $days]);
                } else {
                    $statusTone = 'neutral';
                    $statusLabel = __('Not subscribed');
                    $statusSub = __('Estimate only until you add a plan');
                }

                $statusTile = [
                    'success' => 'border-brand-sage/30 bg-brand-sage/8',
                    'info' => 'border-sky-200 bg-sky-50',
                    'warning' => 'border-amber-200 bg-amber-50',
                    'neutral' => 'border-brand-ink/10 bg-white',
                ][$statusTone];
                $statusDot = [
                    'success' => 'bg-brand-sage',
                    'info' => 'bg-sky-500',
                    'warning' => 'bg-amber-500',
                    'neutral' => 'bg-brand-ink/15',
                ][$statusTone];

                // Shared section-header chrome (matches billing.show + automation).
                $tonePalette = [
                    'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
                    'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
                    'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
                    'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
                    'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
                    'forest' => 'bg-brand-forest/10 text-brand-forest ring-brand-forest/20',
                ];
            @endphp

            {{-- Hero: positioning + at-a-glance stat strip. --}}
            <section class="dply-card overflow-hidden">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                    <div class="lg:col-span-7">
                        <div class="flex items-start gap-3">
                            <x-icon-badge size="md">
                                <x-heroicon-o-chart-bar class="h-6 w-6" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Billing') }}</p>
                                <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Billing analytics') }}</h2>
                                <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Live estimate, resource breakdown, Edge delivery meters, and Stripe invoice history for :org.', ['org' => $organization->name]) }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <x-outline-link href="{{ route('billing.show', $organization) }}" wire:navigate>
                                <x-heroicon-o-credit-card class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Billing & plan') }}
                            </x-outline-link>
                            <x-outline-link href="{{ route('billing.invoices', $organization) }}" wire:navigate>
                                <x-heroicon-o-document class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Invoices') }}
                            </x-outline-link>
                        </div>
                    </div>
                    <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                        <div class="rounded-2xl border px-4 py-3 shadow-sm {{ $statusTile }}">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</dt>
                            <dd class="mt-1 flex items-center gap-1.5">
                                <span class="inline-block h-2 w-2 rounded-full {{ $statusDot }}" aria-hidden="true"></span>
                                <span class="text-sm font-semibold text-brand-ink">{{ $statusLabel }}</span>
                            </dd>
                            <p class="mt-1 truncate text-[11px] text-brand-moss" title="{{ $statusSub }}">{{ $statusSub }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Estimated') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">${{ number_format($displayCents / 100, 0) }}</span>
                                <span class="text-[11px] text-brand-moss">{{ $interval === 'year' ? '/'.__('yr') : '/'.__('mo') }}</span>
                            </dd>
                            <p class="mt-1 text-[11px] text-brand-mist">{{ __('Daily run rate $:n', ['n' => number_format(($summary['daily_run_rate_cents'] ?? 0) / 100, 2)]) }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Resources') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $billableResources }}</span>
                                <span class="text-[11px] text-brand-moss">{{ __('billable') }}</span>
                            </dd>
                            <p class="mt-1 truncate text-[11px] text-brand-mist">
                                @foreach (array_values(array_filter($resourceParts, fn ($p) => $p['visible'])) as $i => $part)
                                    @if ($i > 0) <span aria-hidden="true">·</span> @endif
                                    {{ $part['count'] }} {{ $part['label'] }}
                                @endforeach
                            </p>
                        </div>
                    </dl>
                </div>
            </section>

            @if (cost_observatory_active($organization))
                @php
                    $obsDplyCents = (int) ($costObservatory['dply_platform_cents'] ?? 0);
                    $obsProviderCents = (int) ($costObservatory['provider_infrastructure_cents'] ?? 0);
                    $obsStackCents = (int) ($costObservatory['stack_total_cents'] ?? 0);
                    $obsPartial = ! empty($costObservatory['provider_partial']);
                    $obsUnknown = (int) ($costObservatory['provider_unknown_count'] ?? 0);
                    $obsComparison = is_array($costObservatory['comparison'] ?? null) ? $costObservatory['comparison'] : [];
                    $obsForgeBaseline = (int) ($obsComparison['forge_baseline_cents'] ?? 0);
                    $obsForgePlusProvider = (int) ($obsComparison['forge_plus_provider_cents'] ?? 0);
                    $obsDplyPlusProvider = (int) ($obsComparison['dply_plus_provider_cents'] ?? 0);
                    $obsDelta = (int) ($obsComparison['delta_vs_forge_cents'] ?? 0);
                    $obsServers = is_array($costObservatory['servers'] ?? null) ? $costObservatory['servers'] : [];
                @endphp
                <section class="mt-6 dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-banknotes class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Observatory') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Transparent cost observatory') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Dply platform fees, estimated provider infrastructure, and metered delivery — in one pane. We bill our work; you pay your cloud provider directly.') }}</p>
                        </div>
                    </div>
                    <div class="grid gap-4 p-6 sm:grid-cols-2 sm:p-7 lg:grid-cols-4">
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Dply platform') }}</p>
                            <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">${{ number_format($obsDplyCents / 100, 2) }}<span class="text-xs font-normal text-brand-moss">/mo</span></p>
                            <p class="mt-1 text-[11px] text-brand-moss">{{ __('Plan + managed products + Edge usage') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Provider infrastructure') }}</p>
                            <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">
                                @if ($obsProviderCents > 0)
                                    ${{ number_format($obsProviderCents / 100, 2) }}<span class="text-xs font-normal text-brand-moss">/mo</span>
                                @else
                                    <span class="text-lg">{{ __('Unknown') }}</span>
                                @endif
                            </p>
                            <p class="mt-1 text-[11px] text-brand-moss">
                                @if ($obsPartial)
                                    {{ trans_choice(':known with estimates · :unknown need cost notes|:known with estimates · :unknown need cost notes', $obsUnknown, ['known' => count($obsServers) - $obsUnknown, 'unknown' => $obsUnknown]) }}
                                @else
                                    {{ __('Catalog or saved notes on BYO VMs') }}
                                @endif
                            </p>
                        </div>
                        <div class="rounded-2xl border border-brand-sage/30 bg-brand-sage/8 px-4 py-3 shadow-sm">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Full stack estimate') }}</p>
                            <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">${{ number_format($obsStackCents / 100, 2) }}<span class="text-xs font-normal text-brand-moss">/mo</span></p>
                            <p class="mt-1 text-[11px] text-brand-moss">{{ __('Dply + provider (where known)') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('vs Forge Hobby') }}</p>
                            <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">${{ number_format($obsForgeBaseline / 100, 2) }}<span class="text-xs font-normal text-brand-moss">/mo</span></p>
                            <p class="mt-1 text-[11px] text-brand-moss">
                                @if ($obsDelta !== 0)
                                    {{ __('Dply stack :delta vs Forge + same infra', ['delta' => ($obsDelta >= 0 ? '+' : '-').'$'.number_format(abs($obsDelta) / 100, 2)]) }}
                                @else
                                    {{ __('Forge $:n/server + your provider bills', ['n' => number_format(((int) ($obsComparison['forge_per_server_cents'] ?? 1200)) / 100, 0)]) }}
                                @endif
                            </p>
                        </div>
                    </div>

                    @if ($obsServers !== [])
                        <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-7">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('BYO VM provider estimates') }}</p>
                            <div class="mt-3 overflow-hidden rounded-xl border border-brand-ink/10">
                                <table class="w-full text-sm">
                                    <thead class="bg-brand-sand/35 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                        <tr>
                                            <th class="px-4 py-2 text-left">{{ __('Server') }}</th>
                                            <th class="px-4 py-2 text-left">{{ __('Provider / plan') }}</th>
                                            <th class="px-4 py-2 text-left">{{ __('Source') }}</th>
                                            <th class="px-4 py-2 text-right">{{ __('Est. /mo') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/5">
                                        @foreach ($obsServers as $obsServer)
                                            <tr class="transition-colors hover:bg-brand-sand/15">
                                                <td class="px-4 py-2.5 font-medium text-brand-ink">{{ $obsServer['name'] }}</td>
                                                <td class="px-4 py-2.5 text-brand-moss">
                                                    {{ $obsServer['provider'] ?? '—' }}
                                                    @if (! empty($obsServer['plan']))
                                                        <span class="text-brand-mist">· {{ $obsServer['plan'] }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-2.5 text-xs text-brand-moss">
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
                                                <td class="px-4 py-2.5 text-right font-mono tabular-nums text-brand-ink">
                                                    @if (($obsServer['monthly_usd_cents'] ?? 0) > 0)
                                                        ${{ number_format($obsServer['monthly_usd_cents'] / 100, 2) }}
                                                    @else
                                                        —
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot class="bg-brand-sand/30">
                                        <tr>
                                            <td colspan="3" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Provider subtotal') }}</td>
                                            <td class="px-4 py-2.5 text-right font-mono tabular-nums font-semibold text-brand-ink">${{ number_format($obsProviderCents / 100, 2) }}</td>
                                        </tr>
                                        <tr>
                                            <td colspan="3" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Forge Hobby + provider') }}</td>
                                            <td class="px-4 py-2.5 text-right font-mono tabular-nums text-brand-moss">${{ number_format($obsForgePlusProvider / 100, 2) }}</td>
                                        </tr>
                                        <tr>
                                            <td colspan="3" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide text-brand-sage">{{ __('Dply + provider') }}</td>
                                            <td class="px-4 py-2.5 text-right font-mono tabular-nums font-semibold text-brand-forest">${{ number_format($obsDplyPlusProvider / 100, 2) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <p class="mt-3 text-xs leading-relaxed text-brand-mist">{{ $costObservatory['disclaimer'] ?? '' }}</p>
                        </div>
                    @endif
                </section>
            @endif

            <div class="mt-6 space-y-6">

                {{-- Recurring revenue / forecast row. Four compact tiles — a
                     dedicated section so MRR/ARR/projection/delta read as one
                     set instead of stacked next to operational KPIs. --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-arrow-trending-up class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Forecast') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recurring revenue') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Normalized MRR / ARR, projected month-end, and the change versus 30 days ago.') }}</p>
                        </div>
                    </div>
                    <div class="grid gap-3 p-6 sm:grid-cols-2 sm:p-7 xl:grid-cols-4">
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('MRR') }}</p>
                            <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">${{ number_format($forecastMrrCents / 100, 2) }}</p>
                            <p class="mt-1 text-[11px] text-brand-moss">{{ __('Normalized recurring monthly revenue') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('ARR') }}</p>
                            <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">${{ number_format($forecastArrCents / 100, 2) }}</p>
                            <p class="mt-1 text-[11px] text-brand-moss">{{ __('Annualized recurring run-rate') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Month-end projection') }}</p>
                            <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">${{ number_format($forecastProjectedMonthEndCents / 100, 2) }}</p>
                            <p class="mt-1 text-[11px] text-brand-moss">{{ __('Fixed + extrapolated Edge usage MTD') }}</p>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Δ vs 30 days') }}</p>
                            @if (is_int($forecastDeltaVsThirtyDays))
                                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums {{ $forecastDeltaVsThirtyDays >= 0 ? 'text-brand-rust' : 'text-brand-forest' }}">
                                    {{ $forecastDeltaVsThirtyDays >= 0 ? '+' : '-' }}${{ number_format(abs($forecastDeltaVsThirtyDays) / 100, 2) }}
                                </p>
                                <p class="mt-1 text-[11px] text-brand-moss">{{ __('Change in current monthly estimate') }}</p>
                            @else
                                <p class="mt-1 text-sm font-semibold text-brand-ink">{{ __('Not enough history') }}</p>
                                <p class="mt-1 text-[11px] text-brand-mist">{{ __('Appears once snapshots accumulate') }}</p>
                            @endif
                        </div>
                    </div>
                </section>

                {{-- Spend trend --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-presentation-chart-line class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Trend') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Historical spend') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Daily billing snapshots for the last 90 days, with a focused 30-day table below.') }}</p>
                        </div>
                    </div>
                    <div class="p-6 sm:p-7">
                        @if ($spendTrendNinety === [])
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-white/40 px-5 py-8 text-center">
                                <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                    <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <p class="mt-3 text-sm text-brand-moss">{{ __('No snapshots yet. Daily snapshots populate this trend automatically.') }}</p>
                            </div>
                        @else
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/20 p-4">
                                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Last 90 days') }}</p>
                                <div class="mt-3 flex h-24 items-end gap-1" aria-hidden="true">
                                    @foreach ($spendTrendNinety as $day)
                                        <div class="flex-1 min-w-0">
                                            <div
                                                class="w-full rounded-t bg-brand-ink/25 transition-colors hover:bg-brand-ink/45"
                                                style="height: {{ max(4, round(($day['total_cents'] / $maxSpendTrendCents) * 100)) }}%"
                                                title="{{ $day['label'] }} — ${{ number_format(($day['total_cents'] ?? 0) / 100, 2) }}"
                                            ></div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            @if ($spendTrendThirty !== [])
                                <div class="mt-5">
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Last 30 days') }}</p>
                                    <div class="mt-2 overflow-hidden rounded-xl border border-brand-ink/10">
                                        <table class="w-full text-sm">
                                            <thead class="bg-brand-sand/35 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                                <tr>
                                                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                                                    <th class="px-4 py-2 text-right">{{ __('Total') }}</th>
                                                    <th class="px-4 py-2 text-right">{{ __('Edge usage') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-brand-ink/5">
                                                @foreach ($spendTrendThirty as $day)
                                                    <tr class="transition-colors hover:bg-brand-sand/15">
                                                        <td class="px-4 py-2.5 text-brand-ink">{{ \Illuminate\Support\Carbon::parse($day['date'])->toFormattedDateString() }}</td>
                                                        <td class="px-4 py-2.5 text-right font-mono tabular-nums text-brand-ink">${{ number_format(($day['total_cents'] ?? 0) / 100, 2) }}</td>
                                                        <td class="px-4 py-2.5 text-right font-mono tabular-nums text-brand-moss">${{ number_format(($day['edge_usage_cents'] ?? 0) / 100, 2) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endif
                        @endif
                    </div>
                </section>

                {{-- Spend by category --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-chart-pie class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Breakdown') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Spend by category') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Current-cycle estimate — updates when your fleet changes.') }}</p>
                        </div>
                    </div>
                    <div class="space-y-5 p-6 sm:p-7">
                        <div class="flex h-4 w-full overflow-hidden rounded-full bg-brand-cream/80">
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

                        <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs text-brand-moss">
                            @foreach ($categoryBreakdown as $segment)
                                @if (($segment['cents'] ?? 0) > 0)
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2.5 w-2.5 rounded-sm {{ $segment['color'] ?? 'bg-brand-moss' }}"></span>
                                        {{ $segment['label'] }} · <span class="font-mono tabular-nums">${{ number_format($segment['cents'] / 100, 2) }}</span>
                                    </span>
                                @endif
                            @endforeach
                        </div>

                        <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                            <table class="w-full text-sm">
                                <thead class="bg-brand-sand/35 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                    <tr>
                                        <th class="px-4 py-2 text-left">{{ __('Line item') }}</th>
                                        <th class="px-4 py-2 text-right">{{ __('Qty') }}</th>
                                        <th class="px-4 py-2 text-right">{{ __('Unit') }}</th>
                                        <th class="px-4 py-2 text-right">{{ __('Monthly') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5">
                                    @foreach ($lineItems as $item)
                                        <tr class="transition-colors hover:bg-brand-sand/15">
                                            <td class="px-4 py-3 text-brand-ink">
                                                {{ $item['label'] }}
                                                @if (! empty($item['detail']))
                                                    <span class="block text-xs text-brand-moss">{{ $item['detail'] }}</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-right font-mono tabular-nums text-brand-moss">{{ $item['quantity'] }}</td>
                                            <td class="px-4 py-3 text-right font-mono tabular-nums text-brand-moss">${{ number_format($item['unit_cents'] / 100, 2) }}</td>
                                            <td class="px-4 py-3 text-right font-mono tabular-nums font-semibold text-brand-ink">${{ number_format($item['line_cents'] / 100, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-brand-sand/30">
                                    <tr>
                                        <td colspan="3" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Estimated total') }}</td>
                                        <td class="px-4 py-3 text-right font-mono tabular-nums font-semibold text-brand-ink">${{ number_format($monthlyCents / 100, 2) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </section>

                @if ($edgeOn)
                {{-- Edge sites --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Delivery') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Edge sites') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Per-site platform fee, delivery usage (MTD), and daily request trends.') }}</p>
                        </div>
                    </div>
                    <div class="p-6 sm:p-7">
                        @if ($edgeSites === [])
                            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-white/40 px-5 py-8 text-center">
                                <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                    <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <p class="mt-3 text-sm text-brand-moss">{{ __('No live Edge sites in this organization yet.') }}</p>
                            </div>
                        @else
                            @if ($edgeUsageDaily !== [])
                                <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/20 p-4">
                                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Org total — daily requests') }}</p>
                                    <div class="mt-3 flex h-20 items-end gap-1" aria-hidden="true">
                                        @foreach ($edgeUsageDaily as $day)
                                            <div class="flex-1 min-w-0 group relative">
                                                <div
                                                    class="w-full rounded-t bg-brand-ink/20 transition-colors hover:bg-brand-ink/40"
                                                    style="height: {{ max(4, round(($day['requests'] / $maxEdgeRequests) * 100)) }}%"
                                                ></div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                                @foreach ($edgeSites as $edgeSite)
                                    @include('livewire.billing.partials.edge-site-billing-card', ['site' => $edgeSite])
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>

                @endif

                @if ($hasManagedSurfaces)
                {{-- Managed products --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-cube class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Catalog') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Managed products') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Live Cloud, Edge, and Serverless sites billed per unit — separate from BYO VM tiers.') }}</p>
                        </div>
                    </div>
                    @php
                        $managedCatalog = array_filter([
                            'edge' => $edgeOn ? ['title' => __('Edge sites'), 'icon' => 'heroicon-o-globe-alt'] : null,
                            'cloud' => $cloudOn ? ['title' => __('Cloud apps'), 'icon' => 'heroicon-o-cube'] : null,
                            'serverless' => $serverlessOn ? ['title' => __('Serverless functions'), 'icon' => 'heroicon-o-bolt'] : null,
                        ]);
                    @endphp
                    <div class="grid gap-4 p-6 sm:p-7 lg:grid-cols-3">
                        @foreach ($managedCatalog as $key => $meta)
                            @php $rows = $managedProducts[$key] ?? []; @endphp
                            <div class="rounded-xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <h4 class="text-sm font-semibold text-brand-ink">{{ $meta['title'] }}</h4>
                                        <p class="mt-0.5 text-[11px] text-brand-moss">{{ trans_choice(':count live|:count live', count($rows), ['count' => count($rows)]) }}</p>
                                    </div>
                                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sand/45 text-brand-forest ring-1 ring-brand-ink/10">
                                        <x-dynamic-component :component="$meta['icon']" class="h-4 w-4" aria-hidden="true" />
                                    </span>
                                </div>
                                @if ($rows === [])
                                    <p class="mt-4 text-sm text-brand-mist">{{ __('None active') }}</p>
                                @else
                                    <ul class="mt-3 space-y-1.5 text-sm">
                                        @foreach ($rows as $row)
                                            <li class="flex items-start justify-between gap-2">
                                                <span class="truncate text-brand-ink" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                                <span class="shrink-0 font-mono tabular-nums text-brand-moss">${{ number_format(($row['unit_cents'] ?? 0) / 100, 2) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
                @endif

                {{-- BYO fleet --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Compute') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('BYO server fleet') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Spec-tiered VMs you SSH into — counted separately from managed products.') }}</p>
                        </div>
                    </div>
                    @if ($billableServers === [] && $excludedServers === [])
                        <div class="px-6 py-10 text-center sm:px-7">
                            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-sm text-brand-moss">{{ __('No servers in this organization.') }}</p>
                        </div>
                    @else
                        <table class="w-full text-sm">
                            <thead class="bg-brand-sand/35 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                <tr>
                                    <th class="px-6 py-2 text-left sm:px-7">{{ __('Server') }}</th>
                                    <th class="px-4 py-2 text-left">{{ __('Tier / status') }}</th>
                                    <th class="px-6 py-2 text-right sm:px-7">{{ __('Monthly') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5">
                                @foreach ($billableServers as $server)
                                    <tr class="transition-colors hover:bg-brand-sand/15">
                                        <td class="px-6 py-3 font-medium text-brand-ink sm:px-7">{{ $server['name'] }}</td>
                                        <td class="px-4 py-3 text-brand-moss">{{ $server['tier'] }}</td>
                                        <td class="px-6 py-3 text-right font-mono tabular-nums text-brand-ink sm:px-7">${{ number_format($server['monthly_cents'] / 100, 2) }}</td>
                                    </tr>
                                @endforeach
                                @foreach ($excludedServers as $row)
                                    <tr class="opacity-70 transition-colors hover:bg-brand-sand/15">
                                        <td class="px-6 py-3 text-brand-ink sm:px-7">{{ $row['name'] }}</td>
                                        <td class="px-4 py-3 text-brand-moss">{{ $row['reason'] }}</td>
                                        <td class="px-6 py-3 text-right text-brand-mist sm:px-7">—</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>

                {{-- Stripe sync events --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Audit') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Stripe sync events') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Recent billing reconciliation runs, including no-op and failed runs.') }}</p>
                        </div>
                    </div>
                    @if ($syncEvents === [])
                        <div class="px-6 py-10 text-center sm:px-7">
                            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-arrow-path class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-sm text-brand-moss">{{ __('No sync events yet.') }}</p>
                        </div>
                    @else
                        <table class="w-full text-sm">
                            <thead class="bg-brand-sand/35 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                <tr>
                                    <th class="px-6 py-2 text-left sm:px-7">{{ __('Time') }}</th>
                                    <th class="px-4 py-2 text-left">{{ __('Trigger') }}</th>
                                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                                    <th class="px-4 py-2 text-right">{{ __('Changes') }}</th>
                                    <th class="px-6 py-2 text-right sm:px-7">{{ __('Monthly total') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5">
                                @foreach ($syncEvents as $event)
                                    @php
                                        $eventStatus = $event['status'] ?? 'unknown';
                                        $statusClasses = match ($eventStatus) {
                                            'failed' => 'border-red-200 bg-red-50 text-red-700',
                                            'success' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                                            default => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
                                        };
                                    @endphp
                                    <tr class="transition-colors hover:bg-brand-sand/15">
                                        <td class="px-6 py-3 text-brand-ink sm:px-7" title="{{ $event['created_at'] ?? '' }}">
                                            {{ ! empty($event['created_at']) ? \Illuminate\Support\Carbon::parse($event['created_at'])->diffForHumans() : '—' }}
                                        </td>
                                        <td class="px-4 py-3 text-brand-moss">{{ str_replace('_', ' ', $event['trigger'] ?? 'manual') }}</td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $statusClasses }}">
                                                {{ $eventStatus }}
                                            </span>
                                            @if (! empty($event['error_message']))
                                                <p class="mt-1 text-xs text-red-700">{{ $event['error_message'] }}</p>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right font-mono tabular-nums text-brand-moss">{{ $event['change_count'] ?? 0 }}</td>
                                        <td class="px-6 py-3 text-right font-mono tabular-nums text-brand-ink sm:px-7">${{ number_format(($event['monthly_total_cents'] ?? 0) / 100, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>

                {{-- Invoice history --}}
                <section class="dply-card overflow-hidden">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-heroicon-o-document class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('History') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Invoice history') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Recent Stripe invoices — up to 24 months of paid charges.') }}</p>
                        </div>
                        <a href="{{ route('billing.invoices', $organization) }}" wire:navigate class="shrink-0 text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('All invoices') }} →</a>
                    </div>
                    @if ($invoiceHistory === [])
                        <div class="px-6 py-10 text-center sm:px-7">
                            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-document class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-sm text-brand-moss">{{ __('No invoices yet — subscribe and complete checkout to see history here.') }}</p>
                        </div>
                    @else
                        <div class="p-6 sm:p-7">
                            <div class="flex h-24 items-end gap-2" aria-hidden="true">
                                @foreach (array_slice($invoiceHistory, 0, 12) as $invoice)
                                    <div class="flex-1 min-w-0 group relative">
                                        <div
                                            class="w-full rounded-t {{ ($invoice['paid'] ?? false) ? 'bg-brand-forest/70' : 'bg-brand-gold/60' }}"
                                            style="height: {{ max(8, round(($invoice['total_cents'] / $maxInvoiceCents) * 100)) }}%"
                                            title="{{ $invoice['date'] ?? '' }} — ${{ number_format($invoice['total_cents'] / 100, 2) }}"
                                        ></div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <table class="w-full border-t border-brand-ink/10 text-sm">
                            <thead class="bg-brand-sand/35 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                <tr>
                                    <th class="px-6 py-2 text-left sm:px-7">{{ __('Date') }}</th>
                                    <th class="px-4 py-2 text-left">{{ __('Number') }}</th>
                                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                                    <th class="px-6 py-2 text-right sm:px-7">{{ __('Total') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5">
                                @foreach (array_slice($invoiceHistory, 0, 12) as $invoice)
                                    <tr class="transition-colors hover:bg-brand-sand/15">
                                        <td class="px-6 py-3 text-brand-ink sm:px-7">
                                            {{ $invoice['date'] !== '' ? \Illuminate\Support\Carbon::parse($invoice['date'])->toFormattedDateString() : '—' }}
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs text-brand-moss">{{ $invoice['number'] ?? '—' }}</td>
                                        <td class="px-4 py-3 capitalize text-brand-moss">{{ $invoice['status'] ?? '—' }}</td>
                                        <td class="px-6 py-3 text-right font-mono tabular-nums font-semibold text-brand-ink sm:px-7">${{ number_format($invoice['total_cents'] / 100, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>

            </div>
        </x-organization-shell>
    </div>
</div>
