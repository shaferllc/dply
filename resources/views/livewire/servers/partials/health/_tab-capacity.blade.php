@php
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
        <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="inline-flex shrink-0 items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
            {{ __('Full metrics') }}
        </a>
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
