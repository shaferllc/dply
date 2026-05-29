@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
        'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    ];

    $overallTone = match ($report['overall']) {
        'critical' => $tonePalette['rose'],
        'warning' => $tonePalette['amber'],
        default => $tonePalette['emerald'],
    };

    $metricBar = function (?float $pct): array {
        if ($pct === null) {
            return ['width' => 0, 'color' => 'bg-brand-mist/40'];
        }
        $clamped = max(0.0, min(100.0, $pct));
        if ($pct >= 95) {
            $color = 'bg-rose-500';
        } elseif ($pct >= 85) {
            $color = 'bg-amber-500';
        } else {
            $color = 'bg-emerald-500';
        }

        return ['width' => $clamped, 'color' => $color];
    };

    $metricRow = function (string $label, ?float $pct) use ($metricBar): string {
        $bar = $metricBar($pct);
        $val = $pct === null ? '—' : number_format($pct, 0).'%';

        return view('livewire.servers.partials._overview-metric-row', [
            'label' => $label,
            'value' => $val,
            'barColor' => $bar['color'],
            'barWidth' => $bar['width'],
        ])->render();
    };
@endphp

<x-server-workspace-layout
    :server="$server"
    active="health"
    :title="__('Health')"
    :description="__('Capacity, release pressure, deploy failures, certificates, and daemon drift — one cockpit for this server.')"
>
    @if ($report['monitoring']['agent_reporting'] ?? false)
        <div wire:poll.{{ $pollSeconds }}s class="hidden" aria-hidden="true"></div>
    @endif

    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer>
        <p>{{ __('The health cockpit rolls up guest metrics, atomic release counts, recent failed deploys, certificate expiry, and inactive supervisor programs. It is read-only — use the linked workspace tabs to fix issues.') }}</p>
    </x-explainer>

    <div class="space-y-6">
        <section class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-heart class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Overall') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">
                                @switch($report['overall'])
                                    @case('critical') {{ __('Needs attention') }} @break
                                    @case('warning') {{ __('Watch closely') }} @break
                                    @default {{ __('Healthy') }}
                                @endswitch
                            </h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                {{ trans_choice(':count open alert|:count open alerts', $report['alert_count'], ['count' => $report['alert_count']]) }}
                                · {{ __('Headroom') }}:
                                <span class="font-semibold text-brand-ink">{{ ucfirst((string) ($report['capacity']['headroom'] ?? 'unknown')) }}</span>
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                        {{ __('Full metrics') }}
                    </a>
            </div>

            @if (count($report['alerts']) > 0)
                <ul class="divide-y divide-brand-ink/10 px-6 py-2 sm:px-7">
                    @foreach ($report['alerts'] as $alert)
                        <li class="flex flex-wrap items-start justify-between gap-3 py-3 text-sm">
                            <div>
                                <span @class([
                                    'rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                    'bg-rose-100 text-rose-800' => $alert['severity'] === 'critical',
                                    'bg-amber-100 text-amber-900' => $alert['severity'] === 'warning',
                                ])>{{ $alert['severity'] }}</span>
                                <p class="mt-1 font-semibold text-brand-ink">{{ $alert['title'] }}</p>
                                <p class="mt-0.5 text-brand-moss">{{ $alert['message'] }}</p>
                            </div>
                            @if ($alert['href'] && $alert['link_label'])
                                <a href="{{ $alert['href'] }}" wire:navigate class="shrink-0 text-xs font-semibold text-brand-forest hover:underline">{{ $alert['link_label'] }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="px-6 py-5 text-sm text-brand-moss sm:px-7">{{ __('No active health alerts on this server.') }}</p>
            @endif
        </section>

        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Capacity') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Guest metrics snapshot') }}</h3>
                    @if ($report['capacity']['captured_at'])
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Sampled :ago', ['ago' => $report['capacity']['captured_at']->diffForHumans()]) }}</p>
                    @endif
                </div>
            </div>
            <div class="p-6 sm:p-7">
                @if (! ($report['capacity']['has_samples'] ?? false))
                    <p class="text-sm text-brand-moss">{{ __('Install the monitor agent to populate capacity signals.') }}</p>
                @else
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        {!! $metricRow(__('CPU'), $report['capacity']['metrics']['cpu_pct'] ?? null) !!}
                        {!! $metricRow(__('Memory'), $report['capacity']['metrics']['mem_pct'] ?? null) !!}
                        {!! $metricRow(__('Root disk'), $report['capacity']['metrics']['disk_pct'] ?? null) !!}
                        <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Load (1m)') }}</p>
                            <p class="mt-1 text-lg font-semibold tabular-nums text-brand-ink">
                                {{ isset($report['capacity']['metrics']['load_1m']) ? number_format((float) $report['capacity']['metrics']['load_1m'], 2) : '—' }}
                            </p>
                        </div>
                    </div>
                @endif
            </div>
        </section>

        @if (count($report['disks']) > 0)
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Disk') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Mount points') }}</h3>
                    </div>
                </div>
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($report['disks'] as $disk)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 text-sm sm:px-7">
                            <span class="font-mono text-brand-ink">{{ $disk['mount'] }}</span>
                            <span @class([
                                'font-semibold tabular-nums',
                                'text-rose-700' => ($disk['pct'] ?? 0) >= 90,
                                'text-amber-800' => ($disk['pct'] ?? 0) >= 75 && ($disk['pct'] ?? 0) < 90,
                                'text-brand-forest' => ($disk['pct'] ?? 0) < 75,
                            ])>{{ $disk['pct'] !== null ? number_format((float) $disk['pct'], 1).'%' : '—' }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Releases') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Atomic releases') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Stored release folders vs each site\'s keep setting.') }}</p>
                    </div>
                </div>
                @if (($report['releases']['atomic_site_count'] ?? 0) === 0)
                    <p class="px-6 py-5 text-sm text-brand-moss sm:px-7">{{ __('No atomic deploy sites on this server.') }}</p>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($report['releases']['rows'] as $row)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 text-sm sm:px-7">
                                <span class="font-semibold text-brand-ink">{{ $row['site_name'] }}</span>
                                <span @class([
                                    'tabular-nums font-semibold',
                                    'text-amber-800' => $row['stored'] > $row['keep'],
                                    'text-brand-moss' => $row['stored'] <= $row['keep'],
                                ])>{{ $row['stored'] }} / {{ $row['keep'] }} {{ __('kept') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploys') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Failed deploys') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Last :days days', ['days' => $report['deployments']['lookback_days'] ?? 7]) }}</p>
                    </div>
                </div>
                @if (($report['deployments']['failed_count'] ?? 0) === 0)
                    <p class="px-6 py-5 text-sm text-brand-moss sm:px-7">{{ __('No failed deploys in the lookback window.') }}</p>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($report['deployments']['recent'] as $failure)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 text-sm sm:px-7">
                                <span class="font-semibold text-brand-ink">{{ $failure['site_name'] }}</span>
                                <a href="{{ $failure['href'] }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ $failure['at']?->diffForHumans() }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('TLS') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Certificates') }}</h3>
                    </div>
                </div>
                @if (count($report['certificates']['items']) === 0)
                    <p class="px-6 py-5 text-sm text-brand-moss sm:px-7">{{ __('No expiring or failed certificates in the warning window.') }}</p>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($report['certificates']['items'] as $cert)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 text-sm sm:px-7">
                                <div>
                                    <p class="font-semibold text-brand-ink">{{ $cert['site_name'] }}</p>
                                    <p class="text-xs text-brand-moss">{{ $cert['domain'] ?: $cert['status'] }}</p>
                                </div>
                                @if ($cert['href'])
                                    <a href="{{ $cert['href'] }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">
                                        @if ($cert['days_left'] !== null)
                                            {{ trans_choice(':days day|:days days', max(0, $cert['days_left']), ['days' => max(0, $cert['days_left'])]) }}
                                        @else
                                            {{ __('Open') }}
                                        @endif
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-cpu-chip class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Workers') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Daemons') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Supervisor programs marked inactive.') }}</p>
                    </div>
                </div>
                @if (($report['daemons']['inactive_count'] ?? 0) === 0)
                    <p class="px-6 py-5 text-sm text-brand-moss sm:px-7">{{ __('All :count configured programs are active.', ['count' => $report['daemons']['total'] ?? 0]) }}</p>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($report['daemons']['inactive'] as $daemon)
                            <li class="px-6 py-3 text-sm sm:px-7">
                                <span class="font-mono font-semibold text-brand-ink">{{ $daemon['slug'] }}</span>
                                @if ($daemon['site_name'])
                                    <span class="text-brand-moss"> · {{ $daemon['site_name'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <div class="border-t border-brand-ink/10 px-6 py-3 sm:px-7">
                        <a href="{{ route('servers.daemons', $server) }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Open daemons') }}</a>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-server-workspace-layout>
