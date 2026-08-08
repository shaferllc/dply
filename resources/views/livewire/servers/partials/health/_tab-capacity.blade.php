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

{{-- Nested inside the merged Health card — dense heads, no second outer card.
     The bars stay (they carry more than a bare figure would), the chrome
     around them does not. --}}
<div>
    <x-workspace-panel-head
        dense
        icon="heroicon-o-chart-bar"
        :title="__('Guest metrics snapshot')"
        :note="($report['capacity']['has_samples'] ?? false)
            ? ($report['capacity']['captured_at']
                ? __('Sampled :ago', ['ago' => $report['capacity']['captured_at']->diffForHumans()])
                : __('Latest guest sample from the monitor agent.'))
            : __('Install the monitor agent to populate capacity signals.')"
        class="border-b border-brand-ink/10"
    >
        <x-slot:actions>
            <a
                href="{{ route('servers.monitor', $server) }}"
                wire:navigate
                class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
            >
                {{ __('Full metrics') }}
                <x-heroicon-m-arrow-right class="h-3 w-3 shrink-0" aria-hidden="true" />
            </a>
        </x-slot:actions>
    </x-workspace-panel-head>

    @if ($report['capacity']['has_samples'] ?? false)
        <div @class(['px-4 py-3 sm:px-5', 'border-b border-brand-ink/10' => count($report['disks']) > 0])>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                    {!! $metricRow(__('CPU'), $report['capacity']['metrics']['cpu_pct'] ?? null) !!}
                </div>
                <div class="rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                    {!! $metricRow(__('Memory'), $report['capacity']['metrics']['mem_pct'] ?? null) !!}
                </div>
                <div class="rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                    {!! $metricRow(__('Root disk'), $report['capacity']['metrics']['disk_pct'] ?? null) !!}
                </div>
                <div class="rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                    <div class="flex items-baseline justify-between">
                        <span class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Load (1m)') }}</span>
                        <span class="font-mono text-lg font-semibold tabular-nums text-brand-ink">{{ isset($report['capacity']['metrics']['load_1m']) ? number_format((float) $report['capacity']['metrics']['load_1m'], 2) : '—' }}</span>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if (count($report['disks']) > 0)
        <x-workspace-panel-head
            dense
            icon="heroicon-o-circle-stack"
            :title="__('Mount points')"
            :count="count($report['disks'])"
            :note="__('Used space per mounted filesystem.')"
            class="border-b border-brand-ink/10"
        />
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($report['disks'] as $disk)
                <li class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-1.5 text-xs sm:px-5">
                    <span class="min-w-0 truncate font-mono text-brand-ink">{{ $disk['mount'] }}</span>
                    <span @class([
                        'ml-auto shrink-0 font-mono font-semibold tabular-nums',
                        'text-rose-700' => ($disk['pct'] ?? 0) >= 90,
                        'text-amber-800' => ($disk['pct'] ?? 0) >= 75 && ($disk['pct'] ?? 0) < 90,
                        'text-brand-forest' => ($disk['pct'] ?? 0) < 75,
                    ])>{{ $disk['pct'] !== null ? number_format((float) $disk['pct'], 1).'%' : '—' }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</div>
