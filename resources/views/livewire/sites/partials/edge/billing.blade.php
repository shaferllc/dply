@php
    $billing = $edgeSiteBilling ?? null;
    $showBilling = ($edgeUsageBillingEnabled ?? false) || (($edgeManagedFee ?? 0) > 0) || $billing !== null;
    $maxRequests = max(1, collect($billing['daily'] ?? [])->max('requests') ?? 1);
    $maxEgress = max(1, collect($billing['daily'] ?? [])->max('bytes_egress') ?? 1);
    $usageDetail = is_array($billing['usage_detail'] ?? null) ? $billing['usage_detail'] : [];
@endphp

@if (! $showBilling)
    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-white/40 px-6 py-10 text-center dark:bg-brand-ink/20">
        <x-heroicon-o-chart-bar class="mx-auto h-8 w-8 text-brand-moss/60" />
        <p class="mt-3 text-sm text-brand-moss">{{ __('Billing details for this Edge site are not available yet.') }}</p>
    </div>
@else
    <div class="space-y-6">
        @include('livewire.sites.partials.edge.observability-nav', ['activeObservabilitySection' => 'billing'])

        @include('livewire.sites.partials.edge.guardrail-card')

        @if ($billing !== null)
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-brand-ink/10 bg-white/50 px-5 py-4 dark:bg-brand-ink/20">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Est. total / mo') }}</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-brand-ink">${{ number_format(($billing['total_cents'] ?? 0) / 100, 2) }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white/50 px-5 py-4 dark:bg-brand-ink/20">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Platform fee') }}</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-brand-ink">${{ number_format(($billing['platform_cents'] ?? 0) / 100, 2) }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white/50 px-5 py-4 dark:bg-brand-ink/20">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Delivery usage (MTD)') }}</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-brand-ink">${{ number_format(($billing['usage_cents'] ?? 0) / 100, 2) }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white/50 px-5 py-4 dark:bg-brand-ink/20">
                    <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Requests (MTD)') }}</p>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-brand-ink">{{ number_format($billing['requests'] ?? 0) }}</p>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="dply-card overflow-hidden">
                    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Usage this month') }}</h3>
                    </div>
                    <dl class="grid gap-4 px-6 py-5 text-sm sm:grid-cols-2 sm:px-8">
                        <div class="flex justify-between gap-2">
                            <dt class="text-brand-moss">{{ __('Requests') }}</dt>
                            <dd class="tabular-nums font-medium text-brand-ink">{{ number_format($billing['requests'] ?? 0) }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-brand-moss">{{ __('Egress') }}</dt>
                            <dd class="tabular-nums font-medium text-brand-ink">{{ number_format(($billing['bytes_egress'] ?? 0) / (1024 ** 3), 2) }} GB</dd>
                        </div>
                        @if (($billing['r2_storage_bytes'] ?? 0) > 0)
                            <div class="flex justify-between gap-2">
                                <dt class="text-brand-moss">{{ __('R2 storage') }}</dt>
                                <dd class="tabular-nums font-medium text-brand-ink">{{ number_format(($billing['r2_storage_bytes'] ?? 0) / (1024 ** 3), 2) }} GB</dd>
                            </div>
                        @endif
                        @if (($billing['usage_billing_enabled'] ?? false) && ! empty($usageDetail['included_requests']))
                            <div class="sm:col-span-2 rounded-lg border border-brand-sage/30 bg-brand-cream/30 px-4 py-3 text-xs text-brand-moss dark:bg-brand-ink/30">
                                {{ __('Includes :requests requests and :egress GB egress per site before overage.', [
                                    'requests' => number_format((int) ($usageDetail['included_requests'] ?? 0)),
                                    'egress' => number_format(((int) ($usageDetail['included_bytes_egress'] ?? 0)) / (1024 ** 3), 1),
                                ]) }}
                            </div>
                        @endif
                    </dl>
                </section>

                @if (($billing['daily'] ?? []) !== [])
                    @php
                        $billingDaily = $billing['daily'];
                        $billingLastIdx = count($billingDaily) - 1;
                        $billingMidIdx = (int) floor($billingLastIdx / 2);
                    @endphp
                    <section class="dply-card overflow-hidden">
                        <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Daily requests (30d)') }}</h3>
                            <span class="font-mono text-[11px] text-brand-moss">{{ __('max :n', ['n' => number_format((int) $maxRequests)]) }}</span>
                        </div>
                        <div class="px-6 py-5 sm:px-8">
                            <div class="flex items-end gap-0.5 h-24">
                                @foreach ($billingDaily as $day)
                                    <div class="group relative flex-1 min-w-0 h-full flex items-end cursor-help">
                                        <div
                                            class="w-full rounded-t bg-brand-sage/70 transition-colors group-hover:bg-brand-forest"
                                            style="height: {{ max(4, round(($day['requests'] / $maxRequests) * 100)) }}%"
                                        ></div>
                                        <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand-ink px-2 py-1 text-[11px] font-medium text-white shadow-lg group-hover:block">
                                            <span class="font-semibold">{{ $day['label'] ?? '' }}</span> · {{ number_format($day['requests'] ?? 0) }} {{ __('requests') }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-2 flex justify-between text-[10px] text-brand-moss">
                                <span>{{ $billingDaily[0]['label'] ?? '' }}</span>
                                @if ($billingMidIdx > 0 && $billingMidIdx < $billingLastIdx)
                                    <span>{{ $billingDaily[$billingMidIdx]['label'] ?? '' }}</span>
                                @endif
                                @if ($billingLastIdx > 0)
                                    <span>{{ $billingDaily[$billingLastIdx]['label'] ?? '' }}</span>
                                @endif
                            </div>
                        </div>
                    </section>
                @elseif (! ($billing['has_snapshots'] ?? false))
                    <section class="dply-card overflow-hidden">
                        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Daily requests (30d)') }}</h3>
                        </div>
                        <p class="px-6 py-8 text-sm text-brand-moss sm:px-8">{{ __('No usage snapshots yet this month. Daily stats appear after edge usage collection runs.') }}</p>
                    </section>
                @endif
            </div>

            @if (($billing['daily'] ?? []) !== [])
                @php
                    $billingDailyEg = $billing['daily'];
                    $billingEgLastIdx = count($billingDailyEg) - 1;
                    $billingEgMidIdx = (int) floor($billingEgLastIdx / 2);
                    $maxEgressMb = ($maxEgress / (1024 ** 2));
                @endphp
                <section class="dply-card overflow-hidden">
                    <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                        <h3 class="text-base font-semibold text-brand-ink">{{ __('Daily egress (30d)') }}</h3>
                        <span class="font-mono text-[11px] text-brand-moss">{{ __('max :n MB', ['n' => number_format($maxEgressMb, 1)]) }}</span>
                    </div>
                    <div class="px-6 py-5 sm:px-8">
                        <div class="flex items-end gap-0.5 h-20">
                            @foreach ($billingDailyEg as $day)
                                <div class="group relative flex-1 min-w-0 h-full flex items-end cursor-help">
                                    <div
                                        class="w-full rounded-t bg-sky-500/70 transition-colors group-hover:bg-sky-600"
                                        style="height: {{ max(4, round(($day['bytes_egress'] / $maxEgress) * 100)) }}%"
                                    ></div>
                                    <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand-ink px-2 py-1 text-[11px] font-medium text-white shadow-lg group-hover:block">
                                        <span class="font-semibold">{{ $day['label'] ?? '' }}</span> · {{ number_format(($day['bytes_egress'] ?? 0) / (1024 ** 2), 1) }} MB
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex justify-between text-[10px] text-brand-moss">
                            <span>{{ $billingDailyEg[0]['label'] ?? '' }}</span>
                            @if ($billingEgMidIdx > 0 && $billingEgMidIdx < $billingEgLastIdx)
                                <span>{{ $billingDailyEg[$billingEgMidIdx]['label'] ?? '' }}</span>
                            @endif
                            @if ($billingEgLastIdx > 0)
                                <span>{{ $billingDailyEg[$billingEgLastIdx]['label'] ?? '' }}</span>
                            @endif
                        </div>
                    </div>
                </section>
            @endif
        @else
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-currency-dollar class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Pricing') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Platform pricing') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Flat fee per live Edge site on your organization plan.') }}</p>
                    </div>
                </div>
                <div class="space-y-3 px-6 py-6 sm:px-7">
                    @if (($edgeManagedFee ?? 0) > 0)
                        <p class="text-sm text-brand-ink">
                            <span class="text-2xl font-bold tabular-nums">${{ number_format($edgeManagedFee, 2) }}</span>
                            <span class="text-brand-moss">/ {{ __('month per live Edge site') }}</span>
                        </p>
                    @endif
                    @if ($edgeUsageBillingEnabled ?? false)
                        <p class="text-sm text-brand-moss">{{ __('Usage beyond included quotas is metered on requests and egress.') }}</p>
                        <ul class="space-y-1 text-xs text-brand-moss">
                            @if (($edgeUsageRates['requests_per_million'] ?? 0) > 0)
                                <li>{{ __(':price per million requests', ['price' => '$'.number_format($edgeUsageRates['requests_per_million'], 2)]) }}</li>
                            @endif
                            @if (($edgeUsageRates['egress_per_gb'] ?? 0) > 0)
                                <li>{{ __(':price per GB egress', ['price' => '$'.number_format($edgeUsageRates['egress_per_gb'], 2)]) }}</li>
                            @endif
                        </ul>
                    @endif
                </div>
            </section>
        @endif

        <section class="rounded-xl border border-brand-ink/10 bg-brand-cream/20 px-6 py-5 dark:bg-brand-ink/20 sm:px-8">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-brand-ink">{{ __('Organization billing') }}</p>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Compare all Edge sites, invoices, and spend trends for your workspace.') }}</p>
                </div>
                <a
                    href="{{ route('billing.analytics', $site->organization_id) }}"
                    wire:navigate
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white/60 px-4 py-2 text-sm font-medium text-brand-forest hover:bg-white dark:bg-brand-ink/40 dark:text-brand-sage"
                >
                    {{ __('Open billing analytics') }}
                    <x-heroicon-o-arrow-right class="h-4 w-4" />
                </a>
            </div>
        </section>
    </div>
@endif
