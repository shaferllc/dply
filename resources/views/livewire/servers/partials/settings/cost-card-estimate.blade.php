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

    $overallTone = match ($report['overall']) {
        'critical' => $tonePalette['rose'],
        'warning' => $tonePalette['amber'],
        'info' => $tonePalette['sky'],
        default => $tonePalette['emerald'],
    };

    $summary = $report['summary'] ?? [];
    $hardware = $report['hardware'] ?? [];
    $comparison = $report['comparison'] ?? [];
    $breakdown = $report['breakdown'] ?? ['provider_pct' => 0, 'dply_pct' => 0];
    $providerCents = (int) ($summary['provider_cents'] ?? 0);
    $stackCents = (int) ($summary['stack_cents'] ?? 0);
    $providerFormatted = $providerCents > 0 ? '$'.number_format($providerCents / 100, 2).'/mo' : __('Unknown');
    $deltaVsForge = (int) ($comparison['delta_vs_forge_cents'] ?? 0);
    $forgeBaseline = (int) ($comparison['forge_per_server_cents'] ?? 1200);
    $org = $server->organization;
    $observatoryActive = cost_observatory_active($org);
    $metricsAt = isset($report['capacity']['metrics_at']) ? \Illuminate\Support\Carbon::parse($report['capacity']['metrics_at']) : null;
@endphp

<div id="settings-cost-estimate" class="space-y-6 scroll-mt-24">
    <div class="{{ $card }} overflow-hidden p-0">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-calculator class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Estimate') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Stack estimate') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Honest BYO VM math — provider estimate plus dply tier fee. Not invoiced totals; edit cost notes below to improve provider lines.') }}
                    </p>
                </div>
            </div>
            @if ($observatoryActive && $org)
                <a href="{{ route('billing.analytics', $org) }}" wire:navigate class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                    {{ __('Org observatory') }}
                    <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </a>
            @endif
        </div>

        <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $overallTone }}">
                    <x-heroicon-o-currency-dollar class="h-5 w-5" aria-hidden="true" />
                </span>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Overall') }}</p>
                    <h4 class="mt-0.5 text-base font-semibold text-brand-ink">
                        @switch($report['overall'])
                            @case('critical') {{ __('Capacity constrained') }} @break
                            @case('warning') {{ __('Incomplete cost picture') }} @break
                            @case('info') {{ __('Review utilization') }} @break
                            @default {{ __('Stack estimate healthy') }}
                        @endswitch
                    </h4>
                    <p class="mt-1 text-sm text-brand-moss">
                        {{ $report['totals']['formatted'] ?? '—' }}
                        · {{ trans_choice(':count site|:count sites', $summary['site_count'] ?? 0, ['count' => $summary['site_count'] ?? 0]) }}
                        · {{ __('Dply :tier tier', ['tier' => $report['dply']['tier_label'] ?? '—']) }}
                    </p>
                </div>
            </div>
        </div>

        @if (($report['alert_count'] ?? 0) > 0)
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($report['alerts'] as $alert)
                    @php
                        $alertTone = match ($alert['severity']) {
                            'critical', 'warning' => $tonePalette['amber'],
                            default => $tonePalette['sky'],
                        };
                        $actionHref = null;
                        if (! empty($alert['action_route'])) {
                            if (($alert['action_route'] ?? '') === 'servers.settings') {
                                $actionHref = route('servers.settings', ['server' => $server, 'section' => 'governance']);
                            } else {
                                $actionHref = route($alert['action_route'], $server);
                            }
                            if (! empty($alert['action_anchor'])) {
                                $actionHref .= '#'.$alert['action_anchor'];
                            }
                        }
                    @endphp
                    <li class="flex flex-wrap items-start justify-between gap-3 px-6 py-4 sm:px-7">
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

        <div class="grid gap-4 border-t border-brand-ink/10 p-6 sm:grid-cols-2 sm:p-7 lg:grid-cols-4">
            <div class="rounded-2xl border border-brand-sage/30 bg-brand-sage/8 px-4 py-3 shadow-sm">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Full stack') }}</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">{{ $report['totals']['formatted'] ?? '—' }}</p>
                <p class="mt-1 text-[11px] text-brand-moss">{{ __('Provider + dply tier') }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Provider infra') }}</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">{{ $providerFormatted }}</p>
                <p class="mt-1 text-[11px] text-brand-moss">
                    @if (($report['provider']['source'] ?? '') === 'catalog')
                        {{ __('Catalog estimate') }}
                    @elseif (($report['provider']['source'] ?? '') === 'note')
                        {{ __('Saved cost note') }}
                    @else
                        {{ __('Needs cost note') }}
                    @endif
                </p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Dply tier fee') }}</p>
                @if ($summary['charges_tier_fee'] ?? true)
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">{{ $report['dply']['formatted'] ?? '—' }}</p>
                    <p class="mt-1 text-[11px] text-brand-moss">{{ __(':tier from detected specs', ['tier' => $report['dply']['tier_label'] ?? '—']) }}</p>
                @else
                    <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">$0.00<span class="text-xs font-normal text-brand-moss">/mo</span></p>
                    <p class="mt-1 text-[11px] text-brand-moss">{{ __('No tier fee — :hosting', ['hosting' => $server->hostingBackendLabel()]) }}</p>
                @endif
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Per site (est.)') }}</p>
                <p class="mt-1 font-mono text-2xl font-semibold tabular-nums text-brand-ink">
                    @if (($summary['per_site_cents'] ?? null) !== null)
                        ${{ number_format($summary['per_site_cents'] / 100, 2) }}<span class="text-xs font-normal text-brand-moss">/mo</span>
                    @else
                        <span class="text-lg">—</span>
                    @endif
                </p>
                <p class="mt-1 text-[11px] text-brand-moss">{{ __('Equal share of stack total') }}</p>
            </div>
        </div>

        @if ($stackCents > 0)
            <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-7">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Stack split') }}</p>
                <div class="mt-3 flex h-3 overflow-hidden rounded-full bg-brand-sand/50 ring-1 ring-brand-ink/10">
                    @if ($breakdown['provider_pct'] > 0)
                        <span class="bg-brand-forest/70" style="width: {{ $breakdown['provider_pct'] }}%"></span>
                    @endif
                    @if ($breakdown['dply_pct'] > 0)
                        <span class="bg-brand-sage/80" style="width: {{ $breakdown['dply_pct'] }}%"></span>
                    @endif
                </div>
                <div class="mt-2 flex flex-wrap gap-4 text-xs text-brand-moss">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full bg-brand-forest/70"></span>
                        {{ __('Provider :amount (:pct%)', ['amount' => $providerFormatted, 'pct' => number_format($breakdown['provider_pct'], 0)]) }}
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full bg-brand-sage/80"></span>
                        {{ __('Dply :amount (:pct%)', ['amount' => $report['dply']['formatted'] ?? '—', 'pct' => number_format($breakdown['dply_pct'], 0)]) }}
                    </span>
                </div>
            </div>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="{{ $card }} overflow-hidden p-0">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Hardware & billing tier') }}</p>
                <h4 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Detected specs') }}</h4>
            </div>
            <dl class="grid gap-4 px-6 py-6 sm:grid-cols-2 sm:px-7">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('vCPU') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ $hardware['cpu_count'] ?? __('Unknown') }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Memory') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ $hardware['mem_formatted'] ?? __('Unknown') }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Provider / plan') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-brand-ink">
                        {{ $hardware['provider'] ?? '—' }}
                        @if (! empty($hardware['plan']))
                            <span class="font-normal text-brand-moss">· {{ $hardware['plan'] }}</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Dply tier') }}</dt>
                    <dd class="mt-1">
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $tonePalette['forest'] }}">
                            {{ $hardware['tier_label'] ?? '—' }}
                        </span>
                    </dd>
                </div>
            </dl>
            <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-7">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Tier ladder (monthly)') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($report['tiers'] ?? [] as $tierRow)
                        <span @class([
                            'inline-flex flex-col rounded-xl border px-3 py-2 text-center',
                            'border-brand-forest bg-brand-sage/15 ring-1 ring-brand-forest/30' => $tierRow['current'],
                            'border-brand-ink/10 bg-white' => ! $tierRow['current'],
                        ])>
                            <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ $tierRow['label'] }}</span>
                            <span class="mt-0.5 font-mono text-sm font-semibold tabular-nums text-brand-ink">{{ $tierRow['formatted'] }}</span>
                        </span>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="{{ $card }} overflow-hidden p-0">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Utilization') }}</p>
                <h4 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Capacity headroom') }}</h4>
                <p class="mt-1 text-sm text-brand-moss">
                    @if ($metricsAt)
                        {{ __('Last snapshot :time', ['time' => $metricsAt->diffForHumans()]) }}
                    @else
                        {{ __('Waiting for first monitor snapshot') }}
                    @endif
                </p>
            </div>
            <dl class="grid gap-4 px-6 py-6 sm:grid-cols-2 sm:px-7">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('CPU utilization') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ $report['capacity']['cpu_pct'] !== null ? number_format($report['capacity']['cpu_pct'], 0).'%' : __('Pending') }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Memory utilization') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-brand-ink">{{ $report['capacity']['mem_pct'] !== null ? number_format((float) $report['capacity']['mem_pct'], 0).'%' : __('Pending') }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Headroom estimate') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-brand-ink">
                        @if ($report['capacity']['headroom_sites'] !== null)
                            {{ trans_choice('~:count more small site|~:count more small sites', $report['capacity']['headroom_sites'], ['count' => $report['capacity']['headroom_sites']]) }}
                        @else
                            {{ __('Metrics pending') }}
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('vs Forge Hobby') }}</dt>
                    <dd class="mt-1 text-sm font-semibold text-brand-ink">${{ number_format($forgeBaseline / 100, 2) }}/mo</dd>
                    <dd class="mt-0.5 text-xs text-brand-moss">
                        @if ($deltaVsForge !== 0)
                            {{ __('Dply stack :delta vs Forge + same infra', ['delta' => ($deltaVsForge >= 0 ? '+' : '-').'$'.number_format(abs($deltaVsForge) / 100, 2)]) }}
                        @else
                            {{ __('Same stack total as Forge + provider') }}
                        @endif
                    </dd>
                </div>
            </dl>
            <div class="border-t border-brand-ink/10 px-6 py-4 sm:px-7">
                <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline">
                    {{ __('Open Monitor for live metrics') }}
                    <x-heroicon-m-arrow-up-right class="h-3 w-3" aria-hidden="true" />
                </a>
            </div>
        </div>
    </div>

    <div class="{{ $card }} overflow-hidden p-0">
        <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
            <h4 class="text-base font-semibold text-brand-ink">{{ __('Site allocation') }}</h4>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Even split of the full stack — useful for chargeback, not per-site invoicing.') }}</p>
        </div>
        @if (($summary['site_count'] ?? 0) === 0)
            <p class="px-6 py-8 text-center text-sm text-brand-moss sm:px-7">{{ __('No sites on this server yet.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-xs">
                    <thead class="bg-brand-sand/30 text-brand-moss">
                        <tr>
                            <th class="px-3 py-2 font-semibold">{{ __('Site') }}</th>
                            <th class="px-3 py-2 font-semibold text-right">{{ __('Est. share /mo') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5 bg-white">
                        @foreach ($report['site_rows'] ?? [] as $siteRow)
                            <tr>
                                <td class="px-3 py-2">
                                    @if ($siteRow['href'])
                                        <a href="{{ $siteRow['href'] }}" wire:navigate class="font-medium text-brand-forest hover:underline">{{ $siteRow['name'] }}</a>
                                    @else
                                        {{ $siteRow['name'] }}
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right font-mono tabular-nums">{{ $siteRow['formatted'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p class="border-t border-brand-ink/10 px-6 py-4 text-xs leading-relaxed text-brand-moss sm:px-7">{{ $report['disclaimer'] ?? '' }}</p>
    </div>
</div>
