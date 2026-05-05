@props([
    /** @var list<array{at: int, min: float, avg: float, max: float}> */
    'series' => [],
    /** Numeric scale ceiling (max y-axis value). Auto-fits when null. */
    'yMax' => null,
    /** Numeric scale floor. Defaults to 0. */
    'yMin' => 0,
    /** When set, draws a dashed reference line at this y-value (e.g. warn threshold). */
    'thresholdWarn' => null,
    /** Tailwind text-* color class for the avg line + min/max band fill. */
    'colorClass' => 'text-brand-forest',
    /** Single-line height in tailwind class form. */
    'heightClass' => 'h-32',
    /** Optional formatter spec the JS tooltip can read: 'percent' | 'load' | 'bytes-per-sec'. */
    'format' => 'percent',
])

@php
    $points = is_iterable($series) ? collect($series)->values()->all() : [];
    $hasData = ! empty($points);

    // Internal viewBox: 1000 wide × 100 tall — y normalized to %; we map values
    // into 0..100 via [yMin, yMax]. Avg line is rendered as a polyline; min/max
    // band as a polygon (max top → min bottom round trip).
    $w = 1000;
    $h = 100;

    if ($hasData) {
        $values = collect($points)->flatMap(fn ($p) => [$p['min'], $p['avg'], $p['max']])->all();
        $autoMax = max($values) ?: 1.0;
        $resolvedMax = $yMax !== null ? (float) $yMax : $autoMax * 1.1;
        $resolvedMin = (float) $yMin;
        if ($resolvedMax <= $resolvedMin) {
            $resolvedMax = $resolvedMin + 1;
        }

        $firstAt = $points[0]['at'];
        $lastAt = $points[array_key_last($points)]['at'];
        $span = max(1, $lastAt - $firstAt);

        $normalize = function (float $value) use ($resolvedMin, $resolvedMax, $h): float {
            $clamped = max($resolvedMin, min($resolvedMax, $value));
            $ratio = ($clamped - $resolvedMin) / ($resolvedMax - $resolvedMin);

            return $h - ($ratio * $h);
        };

        $xFor = function (int $at) use ($firstAt, $span, $w): float {
            $ratio = ($at - $firstAt) / $span;

            return $ratio * $w;
        };

        $avgPoints = '';
        $minPoints = '';
        $maxPoints = '';
        foreach ($points as $p) {
            $x = round($xFor((int) $p['at']), 2);
            $avgPoints .= $x.','.round($normalize((float) $p['avg']), 2).' ';
            $minPoints .= $x.','.round($normalize((float) $p['min']), 2).' ';
            $maxPoints .= $x.','.round($normalize((float) $p['max']), 2).' ';
        }
        // Polygon: max top edge then min bottom edge in reverse.
        $bandPoints = trim($maxPoints).' ';
        $reverseMin = collect(explode(' ', trim($minPoints)))->reverse()->implode(' ');
        $bandPoints .= $reverseMin;

        $thresholdY = null;
        if ($thresholdWarn !== null) {
            $thresholdY = round($normalize((float) $thresholdWarn), 2);
        }
    }
@endphp

<div
    {{ $attributes->class(['relative w-full', $heightClass]) }}
    @if ($hasData)
        x-data="{
            tip: null,
            tipX: 0,
            tipY: 0,
            points: @js(array_map(fn ($p) => ['at' => (int) $p['at'], 'avg' => (float) $p['avg'], 'min' => (float) $p['min'], 'max' => (float) $p['max']], $points)),
            format: @js($format),
            formatValue(v) {
                if (this.format === 'load') return Number(v).toFixed(2);
                if (this.format === 'bytes-per-sec') {
                    if (v === 0) return '0 B/s';
                    const units = ['B/s','KB/s','MB/s','GB/s'];
                    const i = Math.floor(Math.log(v) / Math.log(1024));
                    return (v / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
                }
                return Number(v).toFixed(1) + '%';
            },
            formatTime(unix) {
                const d = new Date(unix * 1000);
                return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            },
            onMove(e) {
                const rect = this.$refs.svg.getBoundingClientRect();
                const ratio = (e.clientX - rect.left) / rect.width;
                const idx = Math.max(0, Math.min(this.points.length - 1, Math.round(ratio * (this.points.length - 1))));
                this.tip = this.points[idx];
                this.tipX = ((idx) / Math.max(1, this.points.length - 1)) * 100;
            },
        }"
        x-on:mouseleave="tip = null"
    @endif
>
    @if (! $hasData)
        <div class="flex h-full w-full items-center justify-center rounded-lg border border-dashed border-brand-ink/10 bg-brand-sand/10 text-xs text-brand-mist">
            {{ __('No data in this range yet.') }}
        </div>
    @else
        <svg
            x-ref="svg"
            x-on:mousemove.throttle.30ms="onMove($event)"
            viewBox="0 0 {{ $w }} {{ $h }}"
            preserveAspectRatio="none"
            class="absolute inset-0 h-full w-full {{ $colorClass }}"
            aria-hidden="true"
        >
            {{-- Grid: 25/50/75% lines --}}
            @foreach ([0.25, 0.5, 0.75] as $g)
                <line x1="0" x2="{{ $w }}" y1="{{ $h * (1 - $g) }}" y2="{{ $h * (1 - $g) }}"
                      stroke="currentColor" stroke-opacity="0.06" stroke-width="0.4"
                      vector-effect="non-scaling-stroke" />
            @endforeach

            @if ($thresholdY !== null)
                <line x1="0" x2="{{ $w }}" y1="{{ $thresholdY }}" y2="{{ $thresholdY }}"
                      stroke="rgb(180 83 9)" stroke-width="0.6" stroke-dasharray="4 3"
                      stroke-opacity="0.55" vector-effect="non-scaling-stroke" />
            @endif

            {{-- Min/max band --}}
            <polygon points="{{ $bandPoints }}" fill="currentColor" fill-opacity="0.12" />

            {{-- Avg line --}}
            <polyline points="{{ $avgPoints }}"
                      fill="none" stroke="currentColor" stroke-width="0.9"
                      vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />

            {{-- Hover crosshair --}}
            <line x-show="tip"
                  :x1="tipX * 10" :x2="tipX * 10" y1="0" y2="{{ $h }}"
                  stroke="currentColor" stroke-width="0.5"
                  stroke-opacity="0.45" vector-effect="non-scaling-stroke" />
        </svg>

        {{-- Tooltip --}}
        <div
            x-show="tip" x-cloak
            class="pointer-events-none absolute top-1 -translate-x-1/2 rounded-md border border-brand-ink/10 bg-white px-2 py-1 text-[10px] leading-tight text-brand-ink shadow-md"
            :style="`left: ${tipX}%`"
        >
            <div class="font-mono whitespace-nowrap" x-text="tip ? formatTime(tip.at) : ''"></div>
            <div class="mt-0.5 font-semibold tabular-nums" x-text="tip ? formatValue(tip.avg) : ''"></div>
            <div class="text-[9px] text-brand-mist tabular-nums whitespace-nowrap"
                 x-text="tip ? `${formatValue(tip.min)} … ${formatValue(tip.max)}` : ''"></div>
        </div>
    @endif
</div>
