@props([
    'snapshots',
    'valueKey',
    'variant' => 'percent',
    'strokeClass' => 'text-sky-600',
])

@php
    $vals = $snapshots->map(fn ($s) => (float) ($s->payload[$valueKey] ?? 0))->values()->all();
    $n = count($vals);
    $w = 100;
    $h = 36;
    $pad = 1.5;
    if ($variant === 'percent') {
        $max = 100.0;
        $min = 0.0;
    } else {
        $min = 0.0;
        $maxVal = $n > 0 ? max($vals) : 0.0;
        $max = max(1.0, $maxVal * 1.15);
    }
    $points = '';
    if ($n === 0) {
        // leave empty
    } elseif ($n === 1) {
        $x = $w / 2;
        $den = $max - $min;
        $y = $den > 0 ? $h - $pad - (($vals[0] - $min) / $den) * ($h - 2 * $pad) : $h / 2;
        $points = $x.','.$y.' '.$x.','.$y;
    } else {
        $pts = [];
        $den = $max - $min;
        foreach ($vals as $i => $v) {
            $x = $pad + ($i / ($n - 1)) * ($w - 2 * $pad);
            $y = $den > 0 ? $h - $pad - (($v - $min) / $den) * ($h - 2 * $pad) : $h / 2;
            $pts[] = round($x, 3).','.round($y, 3);
        }
        $points = implode(' ', $pts);
    }
@endphp
<div {{ $attributes->merge(['class' => 'relative w-full']) }}>
    @if ($n === 0)
        <div class="flex h-28 items-center justify-center rounded-lg bg-brand-sand/40 text-sm text-brand-mist">
            {{ __('No data') }}
        </div>
    @else
        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-28 w-full" preserveAspectRatio="none" aria-hidden="true">
            <polyline
                fill="none"
                stroke="currentColor"
                stroke-width="0.9"
                vector-effect="non-scaling-stroke"
                points="{{ $points }}"
                class="{{ $strokeClass }}"
            />
        </svg>
    @endif
</div>
