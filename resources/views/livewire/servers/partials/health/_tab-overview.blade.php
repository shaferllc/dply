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

@if ($report['capacity']['has_samples'] ?? false)
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-chart-bar-square class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Summary') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Capacity snapshot') }}</h3>
                @if ($report['capacity']['captured_at'])
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Sampled :ago', ['ago' => $report['capacity']['captured_at']->diffForHumans()]) }}</p>
                @endif
            </div>
        </div>
        <div class="grid gap-3 px-6 py-5 sm:grid-cols-2 sm:px-7 xl:grid-cols-4">
            @foreach ([
                __('CPU') => $report['capacity']['metrics']['cpu_pct'] ?? null,
                __('Memory') => $report['capacity']['metrics']['mem_pct'] ?? null,
                __('Root disk') => $report['capacity']['metrics']['disk_pct'] ?? null,
            ] as $label => $pct)
                <div @class([
                    'rounded-2xl border p-4 shadow-sm',
                    'border-rose-200/80 bg-rose-50/40' => $pct !== null && $pct >= 95,
                    'border-amber-200/80 bg-amber-50/40' => $pct !== null && $pct >= 85 && $pct < 95,
                    'border-brand-ink/10 bg-white' => $pct === null || $pct < 85,
                ])>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ $label }}</p>
                    <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">
                        {{ $pct === null ? '—' : number_format($pct, 0).'%' }}
                    </p>
                </div>
            @endforeach
            <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Load (1m)') }}</p>
                <p class="mt-2 text-3xl font-semibold tabular-nums text-brand-ink">
                    {{ isset($report['capacity']['metrics']['load_1m']) ? number_format((float) $report['capacity']['metrics']['load_1m'], 2) : '—' }}
                </p>
            </div>
        </div>
        <div class="border-t border-brand-ink/10 px-6 py-4 sm:px-7">
            <button
                type="button"
                wire:click="setHealthWorkspaceTab('capacity')"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                {{ __('View capacity details') }}
                <x-heroicon-m-chevron-right class="h-3.5 w-3.5" aria-hidden="true" />
            </button>
        </div>
    </section>
@endif
