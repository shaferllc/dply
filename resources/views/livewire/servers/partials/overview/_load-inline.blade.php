{{-- Compact system-load line incorporated into the identity header (CPU / mem /
     disk percentages + 1m load), with the full bars a click away in Monitor.
     Relies on parent-scope $latestMetricSnapshot + $server. --}}
@php
    $metricPayload = is_object($latestMetricSnapshot) && is_array($latestMetricSnapshot->payload ?? null)
        ? $latestMetricSnapshot->payload
        : [];
    $metricCpu = isset($metricPayload['cpu_pct']) && is_numeric($metricPayload['cpu_pct']) ? (float) $metricPayload['cpu_pct'] : null;
    $metricMem = isset($metricPayload['mem_pct']) && is_numeric($metricPayload['mem_pct']) ? (float) $metricPayload['mem_pct'] : null;
    $metricDisk = isset($metricPayload['disk_pct']) && is_numeric($metricPayload['disk_pct']) ? (float) $metricPayload['disk_pct'] : null;
    $metricLoad1m = isset($metricPayload['load_1m']) && is_numeric($metricPayload['load_1m']) ? (float) $metricPayload['load_1m'] : null;
    $metricHasAny = $metricCpu !== null || $metricMem !== null || $metricDisk !== null;
    $metricCapturedAt = is_object($latestMetricSnapshot) ? $latestMetricSnapshot->captured_at : null;
    $metricStale = $metricCapturedAt && $metricCapturedAt->lt(now()->subMinutes(10));
    $metricTone = function (?float $pct): string {
        if ($pct === null) {
            return 'text-brand-mist';
        }
        if ($pct >= 95) {
            return 'text-rose-600';
        }
        if ($pct >= 85) {
            return 'text-amber-700';
        }

        return 'text-brand-ink';
    };
    $metricStat = function (string $label, ?float $pct) use ($metricTone): string {
        $val = $pct === null ? '—' : number_format($pct, 0).'%';

        return '<span class="inline-flex items-baseline gap-1">'
            .'<span class="text-[10px] uppercase tracking-[0.16em] text-brand-mist">'.e($label).'</span>'
            .'<span class="font-mono font-semibold '.$metricTone($pct).'">'.e($val).'</span>'
            .'</span>';
    };
@endphp
<div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
    @if ($metricHasAny)
        {!! $metricStat(__('CPU'), $metricCpu) !!}
        {!! $metricStat(__('MEM'), $metricMem) !!}
        {!! $metricStat(__('DISK'), $metricDisk) !!}
        @if ($metricLoad1m !== null)
            <span class="text-brand-mist">·</span>
            <span class="inline-flex items-baseline gap-1">
                <span class="text-[10px] uppercase tracking-[0.16em] text-brand-mist">{{ __('Load') }}</span>
                <span class="font-mono font-semibold text-brand-ink">{{ number_format($metricLoad1m, 2) }}</span>
            </span>
        @endif
    @else
        <span class="text-brand-mist">{{ __('Awaiting first metrics snapshot') }}</span>
    @endif
    @if ($metricStale)
        <span class="font-semibold uppercase tracking-wide text-amber-700">{{ __('Stale') }}</span>
    @endif
    <a href="{{ route('servers.monitor', $server) }}" wire:navigate class="inline-flex items-center gap-1 font-semibold text-brand-forest transition hover:text-brand-sage hover:underline">
        {{ __('Monitor') }}
        <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
    </a>
</div>
