@props([
    /** @var \App\Models\ServerMetricSnapshot|null */
    'snapshot' => null,
    /** Compact mode: tighter padding, no labels (for tight server-row contexts). */
    'compact' => false,
])

@php
    $payload = is_object($snapshot) && is_array($snapshot->payload ?? null) ? $snapshot->payload : [];
    $cpu = isset($payload['cpu_pct']) && is_numeric($payload['cpu_pct']) ? (float) $payload['cpu_pct'] : null;
    $mem = isset($payload['mem_pct']) && is_numeric($payload['mem_pct']) ? (float) $payload['mem_pct'] : null;
    $disk = isset($payload['disk_pct']) && is_numeric($payload['disk_pct']) ? (float) $payload['disk_pct'] : null;

    $statusColor = function (?float $value, float $warn = 85.0, float $critical = 95.0): string {
        if ($value === null) {
            return 'text-brand-mist';
        }
        if ($value >= $critical) {
            return 'bg-red-100 text-red-900';
        }
        if ($value >= $warn) {
            return 'bg-amber-100 text-amber-900';
        }

        return 'text-brand-moss';
    };

    $hasAny = $cpu !== null || $mem !== null || $disk !== null;
    $sampleAge = is_object($snapshot) && $snapshot->captured_at ? $snapshot->captured_at->diffInMinutes(now()) : null;
    $stale = $sampleAge !== null && $sampleAge > 10;
@endphp

@if (! $hasAny)
    <span {{ $attributes->class(['inline-flex items-center text-2xs font-medium uppercase tracking-wide text-brand-mist']) }} title="{{ __('No metrics yet — install monitor on this server.') }}">
        <x-heroicon-o-minus-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span class="ml-1">{{ __('No metrics') }}</span>
    </span>
@else
    <div {{ $attributes->class(['inline-flex items-center gap-1.5 font-mono tabular-nums', $stale ? 'opacity-60' : '']) }}
         title="{{ $sampleAge !== null ? __('Sampled :ago', ['ago' => $snapshot->captured_at->diffForHumans()]) : '' }}">
        <span class="inline-flex items-baseline gap-0.5 rounded px-1 py-0.5 text-xxs font-semibold leading-none {{ $statusColor($cpu) }}">
            <span class="font-bold uppercase tracking-wider opacity-60">{{ __('CPU') }}</span>
            <span class="tabular-nums">{{ $cpu !== null ? number_format($cpu, 0).'%' : '—' }}</span>
        </span>
        <span class="inline-flex items-baseline gap-0.5 rounded px-1 py-0.5 text-xxs font-semibold leading-none {{ $statusColor($mem) }}">
            <span class="font-bold uppercase tracking-wider opacity-60">{{ __('MEM') }}</span>
            <span class="tabular-nums">{{ $mem !== null ? number_format($mem, 0).'%' : '—' }}</span>
        </span>
        <span class="inline-flex items-baseline gap-0.5 rounded px-1 py-0.5 text-xxs font-semibold leading-none {{ $statusColor($disk) }}">
            <span class="font-bold uppercase tracking-wider opacity-60">{{ __('DISK') }}</span>
            <span class="tabular-nums">{{ $disk !== null ? number_format($disk, 0).'%' : '—' }}</span>
        </span>
    </div>
@endif
