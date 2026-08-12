@php
    $tonePalette = [
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'sky' => 'bg-sky-50 text-sky-800 ring-sky-200',
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'mist' => 'bg-brand-sand/50 text-brand-moss ring-brand-ink/10',
        'forest' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
    ];

    $summary = $report['summary'] ?? [];
    $hardware = $report['hardware'] ?? [];
    $providerCents = (int) ($summary['provider_cents'] ?? 0);
    $providerFormatted = $providerCents > 0 ? '$'.number_format($providerCents / 100, 2).'/mo' : __('Unknown');
    $dplyFormatted = $report['dply']['formatted'] ?? '—';
    $org = $server->organization;
    $observatoryActive = cost_observatory_active($org);
    $metricsAt = isset($report['capacity']['metrics_at']) ? \Illuminate\Support\Carbon::parse($report['capacity']['metrics_at']) : null;
    $siteCount = (int) ($summary['site_count'] ?? 0);

    // Metrics freshness is a Monitor concern, and the Utilization block inside
    // Details already dates the snapshot — it doesn't earn a banner on a cost card.
    $costAlerts = array_values(array_filter(
        $report['alerts'] ?? [],
        static fn (array $alert): bool => ! in_array($alert['kind'] ?? '', ['metrics_pending', 'metrics_stale'], true),
    ));

    $currency = $report['currency'] ?? ['native' => null, 'totals' => [], 'note' => ''];

    // Meter severity cut-offs, shared with the right-size nudges so the bar
    // colour and the advice below it can never disagree.
    $hotUtil = (float) config('server_cost_card.right_size.hot_util_pct', 85);
    $busyUtil = (float) config('server_cost_card.right_size.headroom_util_pct', 40);
@endphp

<div id="settings-cost-estimate" class="{{ $card }} scroll-mt-24 overflow-hidden p-0">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-calculator"
        :title="__('Monthly cost')"
        :note="__('Estimate — not your invoice. Provider estimate plus Dply fee.')"
        class="border-b border-brand-ink/10"
    >
        @if ($observatoryActive && $org)
            <x-slot:actions>
                <a
                    href="{{ route('billing.analytics', $org) }}"
                    wire:navigate
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-forest shadow-sm transition hover:bg-brand-sand/40"
                >
                    {{ __('Org observatory') }}
                    <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                </a>
            </x-slot:actions>
        @endif
    </x-workspace-panel-head>

    <div class="px-5 py-5 sm:px-6">
        <p class="font-mono text-3xl font-semibold tabular-nums tracking-tight text-brand-ink">
            {{ $report['totals']['formatted'] ?? '—' }}
        </p>
        <ul class="mt-3 space-y-1 text-sm text-brand-moss">
            <li class="flex flex-wrap items-baseline justify-between gap-2">
                <span>{{ __('Provider') }}</span>
                <span class="font-mono tabular-nums text-brand-ink">{{ $providerFormatted }}</span>
            </li>
            <li class="flex flex-wrap items-baseline justify-between gap-2">
                <span>{{ __('Dply fee') }}</span>
                <span class="font-mono tabular-nums text-brand-ink">{{ $dplyFormatted }}</span>
            </li>
        </ul>
        @if ($siteCount > 0)
            <p class="mt-2 text-xs text-brand-mist">
                {{ trans_choice(':count site|:count sites', $siteCount, ['count' => $siteCount]) }}
            </p>
        @endif

        @if (! empty($currency['totals']))
            <div class="mt-4 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                @if (! empty($currency['native']))
                    {{-- The provider bills in its own currency (Hetzner quotes EUR);
                         lead with that so the USD figure above is traceable. --}}
                    <p class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-xs text-brand-moss">
                        <span>{{ __('Provider bills') }}</span>
                        <span class="font-mono text-sm font-semibold tabular-nums text-brand-ink">{{ $currency['native']['formatted'] }}</span>
                        <span class="text-brand-mist">{{ $currency['native']['rate_note'] }}</span>
                    </p>
                @endif

                <dl @class(['flex flex-wrap gap-x-5 gap-y-2', 'mt-3 border-t border-brand-ink/10 pt-3' => ! empty($currency['native'])])>
                    @foreach ($currency['totals'] as $conversion)
                        <div class="min-w-0">
                            <dt class="text-2xs font-semibold uppercase tracking-[0.14em] {{ $conversion['base'] ? 'text-brand-forest' : 'text-brand-mist' }}">
                                {{ $conversion['code'] }}
                            </dt>
                            <dd @class([
                                'mt-0.5 font-mono text-sm tabular-nums',
                                'font-semibold text-brand-ink' => $conversion['base'],
                                'text-brand-moss' => ! $conversion['base'],
                            ])>{{ $conversion['formatted'] }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if (! empty($currency['note']))
                    <p class="mt-2.5 text-2xs leading-relaxed text-brand-mist">{{ $currency['note'] }}</p>
                @endif
            </div>
        @endif
    </div>

    @if ($costAlerts !== [])
        <ul class="divide-y divide-brand-ink/10 border-t border-brand-ink/10">
            @foreach ($costAlerts as $alert)
                @php
                    $alertTone = match ($alert['severity']) {
                        'critical', 'warning' => $tonePalette['amber'],
                        default => $tonePalette['sky'],
                    };
                    $actionHref = null;
                    if (! empty($alert['action_route'])) {
                        if (($alert['action_route'] ?? '') === 'servers.settings') {
                            $actionHref = route('servers.settings', ['server' => $server, 'tab' => 'governance']);
                        } else {
                            $actionHref = route($alert['action_route'], $server);
                        }
                        if (! empty($alert['action_anchor'])) {
                            $actionHref .= '#'.$alert['action_anchor'];
                        }
                    }
                @endphp
                <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-2.5 sm:px-6">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 {{ $alertTone }}">
                            <x-heroicon-o-exclamation-triangle class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-brand-ink">{{ $alert['title'] }}</p>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ $alert['message'] }}</p>
                        </div>
                    </div>
                    @if ($actionHref && ! empty($alert['action_label']))
                        <a href="{{ $actionHref }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                            {{ $alert['action_label'] }}
                            <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <details class="group border-t border-brand-ink/10">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-3 text-sm font-semibold text-brand-ink marker:content-none sm:px-6 [&::-webkit-details-marker]:hidden">
            <span>{{ __('Details') }}</span>
            <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-brand-moss transition group-open:rotate-180" aria-hidden="true" />
        </summary>

        <div class="space-y-6 border-t border-brand-ink/10 px-5 py-5 sm:px-6">
            {{-- Facts, not a chart: a four-up definition row that actually uses the
                 card's width. The old two-column grid left the right half empty. --}}
            <div>
                <h4 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Specs') }}</h4>
                <dl class="mt-3 grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="min-w-0">
                        <dt class="text-xs text-brand-moss">{{ __('vCPU') }}</dt>
                        <dd class="mt-0.5 truncate text-sm font-semibold text-brand-ink">{{ $hardware['cpu_count'] ?? __('Unknown') }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-brand-moss">{{ __('Memory') }}</dt>
                        <dd class="mt-0.5 truncate text-sm font-semibold text-brand-ink">{{ $hardware['mem_formatted'] ?? __('Unknown') }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-brand-moss">{{ __('Provider / plan') }}</dt>
                        <dd class="mt-0.5 truncate text-sm font-semibold text-brand-ink" title="{{ trim(($hardware['provider'] ?? '—').' '.($hardware['plan'] ?? '')) }}">
                            {{ $hardware['provider'] ?? '—' }}
                            @if (! empty($hardware['plan']))
                                <span class="font-normal text-brand-moss">· {{ $hardware['plan'] }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-brand-moss">{{ __('Region') }}</dt>
                        <dd class="mt-0.5 truncate text-sm font-semibold text-brand-ink">{{ $hardware['region'] ?? __('Unknown') }}</dd>
                    </div>
                </dl>
            </div>

            <div>
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h4 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Utilization') }}</h4>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-moss">
                            @if ($metricsAt)
                                <span>{{ __('Last snapshot :time', ['time' => $metricsAt->diffForHumans()]) }}</span>
                                {{-- Staleness belongs here, next to the numbers it qualifies,
                                     rather than as a banner over the whole cost card. --}}
                                @unless ($report['capacity']['metrics_fresh'] ?? true)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-2xs font-semibold text-amber-900 ring-1 ring-amber-200">
                                        <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0 text-amber-600" aria-hidden="true" />
                                        {{ __('Stale') }}
                                    </span>
                                @endunless
                            @else
                                <span>{{ __('Waiting for first monitor snapshot') }}</span>
                            @endif
                        </p>
                    </div>
                    <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                        {{ __('Monitor') }}
                        <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                    </a>
                </div>

                {{-- CPU and memory are each one ratio against a limit, so each gets a
                     meter, not a number on its own: the track shows the ceiling the
                     percentage is measured against. Fill carries severity over a
                     lighter step of the same ramp, and every meter states its state in
                     words as well as colour. --}}
                <dl class="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([['label' => __('CPU'), 'pct' => $report['capacity']['cpu_pct']], ['label' => __('Memory'), 'pct' => $report['capacity']['mem_pct']]] as $meter)
                        @php
                            $pct = $meter['pct'] !== null ? max(0.0, min(100.0, (float) $meter['pct'])) : null;
                            $state = match (true) {
                                $pct === null => ['label' => __('Pending'), 'track' => 'bg-brand-ink/8', 'fill' => 'bg-brand-mist', 'text' => 'text-brand-mist'],
                                $pct >= $hotUtil => ['label' => __('Hot'), 'track' => 'bg-rose-100', 'fill' => 'bg-rose-500', 'text' => 'text-rose-700'],
                                $pct >= $busyUtil => ['label' => __('Busy'), 'track' => 'bg-amber-100', 'fill' => 'bg-amber-500', 'text' => 'text-amber-700'],
                                default => ['label' => __('Healthy'), 'track' => 'bg-emerald-100', 'fill' => 'bg-emerald-500', 'text' => 'text-emerald-700'],
                            };
                        @endphp
                        <div class="min-w-0">
                            <dt class="text-xs text-brand-moss">{{ $meter['label'] }}</dt>
                            <dd class="mt-1">
                                {{-- State sits beside its own number, not flung to the far
                                     edge of a wide column where it reads as unrelated. --}}
                                <span class="flex items-baseline gap-2">
                                    <span class="text-sm font-semibold text-brand-ink">{{ $pct !== null ? number_format($pct, 0).'%' : '—' }}</span>
                                    <span class="text-xs font-medium {{ $state['text'] }}">{{ $state['label'] }}</span>
                                </span>
                                <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full {{ $state['track'] }}"
                                     role="meter"
                                     aria-valuenow="{{ $pct !== null ? (int) round($pct) : 0 }}"
                                     aria-valuemin="0"
                                     aria-valuemax="100"
                                     aria-label="{{ $meter['label'] }}">
                                    @if ($pct !== null)
                                        <div class="h-full rounded-full {{ $state['fill'] }}" style="width: {{ max(2, (int) round($pct)) }}%"></div>
                                    @endif
                                </div>
                            </dd>
                        </div>
                    @endforeach

                    <div class="min-w-0">
                        <dt class="text-xs text-brand-moss">{{ __('Headroom') }}</dt>
                        <dd class="mt-1 text-sm font-semibold text-brand-ink">
                            @php $headroom = $report['capacity']['headroom_sites'] ?? null; @endphp
                            @if ($headroom === null)
                                <span class="font-normal text-brand-mist">{{ __('Metrics pending') }}</span>
                            @elseif ($headroom < 1)
                                {{-- "Room for ~0 more sites" is a non-statement; say what it means. --}}
                                {{ __('No spare capacity') }}
                            @else
                                {{ trans_choice('Room for ~:count more small site|Room for ~:count more small sites', $headroom, ['count' => $headroom]) }}
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            @if ($siteCount > 0)
                <div>
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                        <h4 class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Per-site share') }}</h4>
                        <p class="text-xs text-brand-moss">{{ __('Even split — for chargeback, not invoicing.') }}</p>
                    </div>
                    {{-- A column of numbers, so: a table, right-aligned, tabular figures.
                         The total row is what makes it worth the chrome — it shows the
                         split adds back up to the stack total above. --}}
                    <div class="mt-3 overflow-x-auto rounded-xl border border-brand-ink/10">
                        <table class="min-w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-brand-ink/10 text-2xs uppercase tracking-[0.14em] text-brand-mist">
                                    <th scope="col" class="px-3 py-2 font-semibold">{{ __('Site') }}</th>
                                    <th scope="col" class="px-3 py-2 text-right font-semibold">{{ __('Est. share /mo') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5">
                                @foreach ($report['site_rows'] ?? [] as $siteRow)
                                    <tr class="transition-colors hover:bg-brand-sand/20">
                                        <td class="px-3 py-2">
                                            @if ($siteRow['href'])
                                                <a href="{{ $siteRow['href'] }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ $siteRow['name'] }}</a>
                                            @else
                                                {{ $siteRow['name'] }}
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono tabular-nums text-brand-ink">{{ $siteRow['formatted'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            @if ($siteCount > 1)
                                <tfoot>
                                    <tr class="border-t border-brand-ink/10 bg-brand-sand/20">
                                        <th scope="row" class="px-3 py-2 text-left font-semibold text-brand-ink">{{ __('Stack total') }}</th>
                                        <td class="px-3 py-2 text-right font-mono font-semibold tabular-nums text-brand-ink">{{ $report['totals']['formatted'] ?? '—' }}</td>
                                    </tr>
                                </tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </details>
</div>
