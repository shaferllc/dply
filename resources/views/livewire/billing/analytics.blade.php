<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="billing-analytics">
            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Billing analytics'), 'icon' => 'chart-bar'],
            ]" />

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
            @endphp

            <div class="space-y-8">
                <div class="dply-card overflow-hidden p-6 sm:p-8">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h1 class="text-2xl font-bold text-brand-ink">{{ __('Billing analytics') }}</h1>
                            <p class="mt-2 text-sm text-brand-moss max-w-2xl">
                                {{ __('Live estimate, resource breakdown, Edge delivery meters, and Stripe invoice history for :org.', ['org' => $organization->name]) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-outline-link href="{{ route('billing.show', $organization) }}" wire:navigate>
                                {{ __('Billing & plan') }}
                            </x-outline-link>
                            <x-outline-link href="{{ route('billing.invoices', $organization) }}" wire:navigate>
                                {{ __('Invoices') }}
                            </x-outline-link>
                        </div>
                    </div>
                </div>

                {{-- KPI row --}}
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="dply-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Estimated bill') }}</p>
                        <p class="mt-2 text-3xl font-bold text-brand-ink">${{ number_format($displayCents / 100, 2) }}</p>
                        <p class="mt-1 text-sm text-brand-moss">{{ $interval === 'year' ? __('/yr') : __('/mo') }}</p>
                    </div>
                    <div class="dply-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Daily run rate') }}</p>
                        <p class="mt-2 text-3xl font-bold text-brand-ink">${{ number_format(($summary['daily_run_rate_cents'] ?? 0) / 100, 2) }}</p>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Based on current fleet') }}</p>
                    </div>
                    <div class="dply-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Billable resources') }}</p>
                        <p class="mt-2 text-3xl font-bold text-brand-ink">
                            {{ ($summary['server_count'] ?? 0) + ($summary['serverless_count'] ?? 0) + ($summary['cloud_count'] ?? 0) + ($summary['edge_count'] ?? 0) }}
                        </p>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __(':servers BYO · :edge Edge · :cloud Cloud · :fn serverless', [
                                'servers' => $summary['server_count'] ?? 0,
                                'edge' => $summary['edge_count'] ?? 0,
                                'cloud' => $summary['cloud_count'] ?? 0,
                                'fn' => $summary['serverless_count'] ?? 0,
                            ]) }}
                        </p>
                    </div>
                    <div class="dply-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Subscription') }}</p>
                        @if ($summary['subscribed'] ?? false)
                            <p class="mt-2 text-lg font-bold text-brand-ink capitalize">{{ $summary['stripe_status'] ?? __('active') }}</p>
                            @if (! empty($summary['next_invoice_at']))
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Next invoice :date', ['date' => \Illuminate\Support\Carbon::parse($summary['next_invoice_at'])->toFormattedDateString()]) }}</p>
                            @endif
                        @elseif ($summary['on_trial'] ?? false)
                            <p class="mt-2 text-lg font-bold text-brand-ink">{{ __('Trial') }}</p>
                            <p class="mt-1 text-sm text-brand-moss">{{ trans_choice(':days day left|:days days left', $summary['trial_days_left'] ?? 0, ['days' => $summary['trial_days_left'] ?? 0]) }}</p>
                        @else
                            <p class="mt-2 text-lg font-bold text-brand-ink">{{ __('Not subscribed') }}</p>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Estimate only until you add a plan') }}</p>
                        @endif
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div class="dply-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('MRR') }}</p>
                        <p class="mt-2 text-3xl font-bold text-brand-ink">${{ number_format($forecastMrrCents / 100, 2) }}</p>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Normalized recurring monthly revenue') }}</p>
                    </div>
                    <div class="dply-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('ARR') }}</p>
                        <p class="mt-2 text-3xl font-bold text-brand-ink">${{ number_format($forecastArrCents / 100, 2) }}</p>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Annualized recurring run-rate') }}</p>
                    </div>
                    <div class="dply-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Projected month-end') }}</p>
                        <p class="mt-2 text-3xl font-bold text-brand-ink">${{ number_format($forecastProjectedMonthEndCents / 100, 2) }}</p>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Fixed charges + extrapolated Edge usage MTD') }}</p>
                    </div>
                    <div class="dply-card p-5">
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Vs 30 days ago') }}</p>
                        @if (is_int($forecastDeltaVsThirtyDays))
                            <p class="mt-2 text-3xl font-bold {{ $forecastDeltaVsThirtyDays >= 0 ? 'text-brand-rust' : 'text-brand-forest' }}">
                                {{ $forecastDeltaVsThirtyDays >= 0 ? '+' : '-' }}${{ number_format(abs($forecastDeltaVsThirtyDays) / 100, 2) }}
                            </p>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Change in current monthly estimate') }}</p>
                        @else
                            <p class="mt-2 text-lg font-semibold text-brand-ink">{{ __('Not enough history') }}</p>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('A 30-day delta appears after snapshots accumulate') }}</p>
                        @endif
                    </div>
                </div>

                {{-- Spend trend --}}
                <div class="dply-card overflow-hidden p-6 sm:p-8">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Historical spend trend') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Daily billing snapshots for the last 90 days, with a focused 30-day view.') }}</p>
                        </div>
                    </div>

                    @if ($spendTrendNinety === [])
                        <p class="mt-6 rounded-xl border border-dashed border-brand-ink/15 bg-white/40 px-5 py-8 text-center text-sm text-brand-moss">
                            {{ __('No snapshots yet. Daily snapshots populate this trend automatically.') }}
                        </p>
                    @else
                        <div class="mt-6 rounded-xl border border-brand-ink/10 bg-brand-cream/20 p-4">
                            <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Last 90 days') }}</p>
                            <div class="mt-3 flex items-end gap-1 h-24" aria-hidden="true">
                                @foreach ($spendTrendNinety as $day)
                                    <div class="flex-1 min-w-0">
                                        <div
                                            class="w-full rounded-t bg-brand-ink/25 hover:bg-brand-ink/45 transition-colors"
                                            style="height: {{ max(4, round(($day['total_cents'] / $maxSpendTrendCents) * 100)) }}%"
                                            title="{{ $day['label'] }} — ${{ number_format(($day['total_cents'] ?? 0) / 100, 2) }}"
                                        ></div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        @if ($spendTrendThirty !== [])
                            <div class="mt-6 overflow-hidden rounded-xl border border-brand-ink/10">
                                <table class="w-full text-sm">
                                    <thead class="bg-brand-cream/60 text-brand-ink/70">
                                        <tr>
                                            <th class="px-4 py-2.5 text-left font-semibold">{{ __('Date') }}</th>
                                            <th class="px-4 py-2.5 text-right font-semibold">{{ __('Total') }}</th>
                                            <th class="px-4 py-2.5 text-right font-semibold">{{ __('Edge usage') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-ink/5">
                                        @foreach ($spendTrendThirty as $day)
                                            <tr>
                                                <td class="px-4 py-2.5 text-brand-ink">{{ \Illuminate\Support\Carbon::parse($day['date'])->toFormattedDateString() }}</td>
                                                <td class="px-4 py-2.5 text-right tabular-nums text-brand-ink">${{ number_format(($day['total_cents'] ?? 0) / 100, 2) }}</td>
                                                <td class="px-4 py-2.5 text-right tabular-nums text-brand-moss">${{ number_format(($day['edge_usage_cents'] ?? 0) / 100, 2) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    @endif
                </div>

                {{-- Category breakdown --}}
                <div class="dply-card overflow-hidden p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Spend by category') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Current-cycle estimate — updates when your fleet changes.') }}</p>

                    <div class="mt-6 flex h-4 w-full overflow-hidden rounded-full bg-brand-cream/80">
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

                    <div class="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-xs text-brand-moss">
                        @foreach ($categoryBreakdown as $segment)
                            @if (($segment['cents'] ?? 0) > 0)
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-sm {{ $segment['color'] ?? 'bg-brand-moss' }}"></span>
                                    {{ $segment['label'] }} · ${{ number_format($segment['cents'] / 100, 2) }}
                                </span>
                            @endif
                        @endforeach
                    </div>

                    <div class="mt-8 overflow-hidden rounded-xl border border-brand-ink/10">
                        <table class="w-full text-sm">
                            <thead class="bg-brand-cream/60 text-brand-ink/70">
                                <tr>
                                    <th class="px-4 py-2.5 text-left font-semibold">{{ __('Line item') }}</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">{{ __('Qty') }}</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">{{ __('Unit') }}</th>
                                    <th class="px-4 py-2.5 text-right font-semibold">{{ __('Monthly') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5">
                                @foreach ($lineItems as $item)
                                    <tr>
                                        <td class="px-4 py-3 text-brand-ink">
                                            {{ $item['label'] }}
                                            @if (! empty($item['detail']))
                                                <span class="block text-xs text-brand-moss">{{ $item['detail'] }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-right tabular-nums">{{ $item['quantity'] }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums">${{ number_format($item['unit_cents'] / 100, 2) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums font-medium">${{ number_format($item['line_cents'] / 100, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-brand-cream/40 font-semibold text-brand-ink">
                                <tr>
                                    <td colspan="3" class="px-4 py-3 text-right">{{ __('Estimated total') }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">${{ number_format($monthlyCents / 100, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                {{-- Edge usage --}}
                <div class="dply-card overflow-hidden p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Edge sites') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Per-site platform fee, delivery usage (MTD), and daily request trends.') }}</p>

                    @if ($edgeSites === [])
                        <p class="mt-6 rounded-xl border border-dashed border-brand-ink/15 bg-white/40 px-5 py-8 text-center text-sm text-brand-moss">
                            {{ __('No live Edge sites in this organization yet.') }}
                        </p>
                    @else
                        @if ($edgeUsageDaily !== [])
                            <div class="mt-6 rounded-xl border border-brand-ink/10 bg-brand-cream/20 p-4">
                                <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Organization total — daily requests') }}</p>
                                <div class="mt-3 flex items-end gap-1 h-20" aria-hidden="true">
                                    @foreach ($edgeUsageDaily as $day)
                                        <div class="flex-1 min-w-0 group relative">
                                            <div
                                                class="w-full rounded-t bg-brand-ink/20 hover:bg-brand-ink/40 transition-colors"
                                                style="height: {{ max(4, round(($day['requests'] / $maxEdgeRequests) * 100)) }}%"
                                            ></div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="mt-6 grid gap-4 lg:grid-cols-2">
                            @foreach ($edgeSites as $edgeSite)
                                @include('livewire.billing.partials.edge-site-billing-card', ['site' => $edgeSite])
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Managed products --}}
                <div class="dply-card overflow-hidden p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Managed products') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Live Cloud, Edge, and Serverless sites billed per unit — separate from BYO VM tiers.') }}</p>

                    <div class="mt-6 grid gap-6 lg:grid-cols-3">
                        @foreach ([
                            'edge' => __('Edge sites'),
                            'cloud' => __('Cloud apps'),
                            'serverless' => __('Serverless functions'),
                        ] as $key => $title)
                            @php $rows = $managedProducts[$key] ?? []; @endphp
                            <div class="rounded-xl border border-brand-ink/10 bg-white/30 p-4">
                                <h3 class="font-semibold text-brand-ink">{{ $title }}</h3>
                                <p class="mt-1 text-xs text-brand-moss">{{ trans_choice(':count live|:count live', count($rows), ['count' => count($rows)]) }}</p>
                                @if ($rows === [])
                                    <p class="mt-4 text-sm text-brand-moss/80">{{ __('None active') }}</p>
                                @else
                                    <ul class="mt-4 space-y-2 text-sm">
                                        @foreach ($rows as $row)
                                            <li class="flex items-start justify-between gap-2">
                                                <span class="text-brand-ink truncate" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                                <span class="shrink-0 tabular-nums text-brand-moss">${{ number_format(($row['unit_cents'] ?? 0) / 100, 2) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- BYO fleet --}}
                <div class="dply-card overflow-hidden p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('BYO server fleet') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Spec-tiered VMs you SSH into — counted separately from managed products.') }}</p>

                    @if ($billableServers === [] && $excludedServers === [])
                        <p class="mt-6 text-sm text-brand-moss">{{ __('No servers in this organization.') }}</p>
                    @else
                        <div class="mt-6 overflow-hidden rounded-xl border border-brand-ink/10">
                            <table class="w-full text-sm">
                                <thead class="bg-brand-cream/60 text-brand-ink/70">
                                    <tr>
                                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Server') }}</th>
                                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Tier / status') }}</th>
                                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Monthly') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5">
                                    @foreach ($billableServers as $server)
                                        <tr>
                                            <td class="px-4 py-3 font-medium text-brand-ink">{{ $server['name'] }}</td>
                                            <td class="px-4 py-3 text-brand-moss">{{ $server['tier'] }}</td>
                                            <td class="px-4 py-3 text-right tabular-nums">${{ number_format($server['monthly_cents'] / 100, 2) }}</td>
                                        </tr>
                                    @endforeach
                                    @foreach ($excludedServers as $row)
                                        <tr class="opacity-70">
                                            <td class="px-4 py-3 text-brand-ink">{{ $row['name'] }}</td>
                                            <td class="px-4 py-3 text-brand-moss">{{ $row['reason'] }}</td>
                                            <td class="px-4 py-3 text-right text-brand-moss">—</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                {{-- Stripe sync events --}}
                <div class="dply-card overflow-hidden p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Stripe sync audit log') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Recent billing reconciliation events, including no-op and failed runs.') }}</p>

                    @if ($syncEvents === [])
                        <p class="mt-6 text-sm text-brand-moss">{{ __('No sync events yet.') }}</p>
                    @else
                        <div class="mt-6 overflow-hidden rounded-xl border border-brand-ink/10">
                            <table class="w-full text-sm">
                                <thead class="bg-brand-cream/60 text-brand-ink/70">
                                    <tr>
                                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Time') }}</th>
                                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Trigger') }}</th>
                                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Status') }}</th>
                                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Changes') }}</th>
                                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Monthly total') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5">
                                    @foreach ($syncEvents as $event)
                                        <tr>
                                            <td class="px-4 py-3 text-brand-ink">
                                                {{ ! empty($event['created_at']) ? \Illuminate\Support\Carbon::parse($event['created_at'])->diffForHumans() : '—' }}
                                            </td>
                                            <td class="px-4 py-3 text-brand-moss">{{ str_replace('_', ' ', $event['trigger'] ?? 'manual') }}</td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                                    {{ ($event['status'] ?? '') === 'failed'
                                                        ? 'bg-rose-100 text-rose-700'
                                                        : (($event['status'] ?? '') === 'success'
                                                            ? 'bg-emerald-100 text-emerald-700'
                                                            : 'bg-brand-cream text-brand-moss') }}">
                                                    {{ $event['status'] ?? 'unknown' }}
                                                </span>
                                                @if (! empty($event['error_message']))
                                                    <p class="mt-1 text-xs text-rose-700">{{ $event['error_message'] }}</p>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-right tabular-nums text-brand-moss">{{ $event['change_count'] ?? 0 }}</td>
                                            <td class="px-4 py-3 text-right tabular-nums text-brand-ink">${{ number_format(($event['monthly_total_cents'] ?? 0) / 100, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                {{-- Invoice history --}}
                <div class="dply-card overflow-hidden p-6 sm:p-8">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Invoice history') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Recent Stripe invoices — up to 24 months of paid charges.') }}</p>
                        </div>
                        <x-outline-link href="{{ route('billing.invoices', $organization) }}" wire:navigate>
                            {{ __('All invoices') }}
                        </x-outline-link>
                    </div>

                    @if ($invoiceHistory === [])
                        <p class="mt-6 text-sm text-brand-moss">{{ __('No invoices yet — subscribe and complete checkout to see history here.') }}</p>
                    @else
                        <div class="mt-6 flex items-end gap-2 h-24" aria-hidden="true">
                            @foreach (array_slice($invoiceHistory, 0, 12) as $invoice)
                                <div class="flex-1 min-w-0 group relative">
                                    <div
                                        class="w-full rounded-t {{ ($invoice['paid'] ?? false) ? 'bg-brand-forest/70' : 'bg-brand-gold/60' }}"
                                        style="height: {{ max(8, round(($invoice['total_cents'] / $maxInvoiceCents) * 100)) }}%"
                                    ></div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-6 overflow-hidden rounded-xl border border-brand-ink/10">
                            <table class="w-full text-sm">
                                <thead class="bg-brand-cream/60 text-brand-ink/70">
                                    <tr>
                                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Date') }}</th>
                                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Number') }}</th>
                                        <th class="px-4 py-2.5 text-left font-semibold">{{ __('Status') }}</th>
                                        <th class="px-4 py-2.5 text-right font-semibold">{{ __('Total') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5">
                                    @foreach (array_slice($invoiceHistory, 0, 12) as $invoice)
                                        <tr>
                                            <td class="px-4 py-3 text-brand-ink">
                                                {{ $invoice['date'] !== '' ? \Illuminate\Support\Carbon::parse($invoice['date'])->toFormattedDateString() : '—' }}
                                            </td>
                                            <td class="px-4 py-3 font-mono text-xs text-brand-moss">{{ $invoice['number'] ?? '—' }}</td>
                                            <td class="px-4 py-3 capitalize text-brand-moss">{{ $invoice['status'] ?? '—' }}</td>
                                            <td class="px-4 py-3 text-right tabular-nums font-medium">${{ number_format($invoice['total_cents'] / 100, 2) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

            </div>
        </x-organization-shell>
    </div>
</div>
