@props([
    'metrics' => null,
])

@php
    $cpu = is_array($metrics) && isset($metrics['cpu']) && is_numeric($metrics['cpu']) ? (float) $metrics['cpu'] : null;
    $mem = is_array($metrics) && isset($metrics['ram']) && is_numeric($metrics['ram']) ? (float) $metrics['ram'] : null;
    $disk = is_array($metrics) && isset($metrics['disk']) && is_numeric($metrics['disk']) ? (float) $metrics['disk'] : null;

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
    $capturedAt = is_array($metrics) && ! empty($metrics['captured_at'])
        ? \Illuminate\Support\Carbon::parse($metrics['captured_at'])
        : null;
    $sampleAge = $capturedAt?->diffInMinutes(now());
    $stale = $sampleAge !== null && $sampleAge > 10;
@endphp

@if (! $hasAny)
    <span class="inline-flex items-center text-[10px] font-medium uppercase tracking-wide text-brand-mist" title="{{ __('No metrics yet — install monitor on this server.') }}">
        <x-heroicon-o-minus-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span class="ml-1">{{ __('No metrics') }}</span>
    </span>
@else
    <div class="inline-flex items-center gap-1.5 font-mono tabular-nums {{ $stale ? 'opacity-60' : '' }}"
         title="{{ $capturedAt ? __('Sampled :ago', ['ago' => $capturedAt->diffForHumans()]) : '' }}">
        <span class="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $statusColor($cpu) }}">
            <span class="text-[8px] font-bold uppercase tracking-wider opacity-60">{{ __('CPU') }}</span>
            <span>{{ $cpu !== null ? number_format($cpu, 0).'%' : '—' }}</span>
        </span>
        <span class="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $statusColor($mem) }}">
            <span class="text-[8px] font-bold uppercase tracking-wider opacity-60">{{ __('MEM') }}</span>
            <span>{{ $mem !== null ? number_format($mem, 0).'%' : '—' }}</span>
        </span>
        <span class="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[10px] font-semibold {{ $statusColor($disk) }}">
            <span class="text-[8px] font-bold uppercase tracking-wider opacity-60">{{ __('DISK') }}</span>
            <span>{{ $disk !== null ? number_format($disk, 0).'%' : '—' }}</span>
        </span>
    </div>
@endif
