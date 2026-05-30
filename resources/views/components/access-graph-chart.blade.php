@props([
    /** @var list<array{at: int, total: float, you: float}> */
    'series' => [],
    'heightClass' => 'h-40',
])

@php
    $points = is_iterable($series) ? collect($series)->values()->all() : [];
    $hasData = ! empty($points);

    $w = 1000;
    $h = 100;

    if ($hasData) {
        $values = collect($points)->flatMap(fn ($p) => [(float) $p['total'], (float) $p['you']])->all();
        $resolvedMax = max($values) ?: 1.0;
        $resolvedMax = max(1.0, $resolvedMax * 1.15);

        $firstAt = (int) $points[0]['at'];
        $lastAt = (int) $points[array_key_last($points)]['at'];
        $span = max(1, $lastAt - $firstAt);

        $normalize = function (float $value) use ($resolvedMax, $h): float {
            $ratio = max(0, min(1, $value / $resolvedMax));

            return $h - ($ratio * $h);
        };

        $xFor = function (int $at) use ($firstAt, $span, $w): float {
            return (($at - $firstAt) / $span) * $w;
        };

        $totalPoints = '';
        $youPoints = '';
        foreach ($points as $p) {
            $x = round($xFor((int) $p['at']), 2);
            $totalPoints .= $x.','.round($normalize((float) $p['total']), 2).' ';
            $youPoints .= $x.','.round($normalize((float) $p['you']), 2).' ';
        }
    }
@endphp

<div
    {{ $attributes->class(['relative w-full', $heightClass]) }}
    @if ($hasData)
        x-data="{
            tip: null,
            tipX: 0,
            points: @js(array_map(fn ($p) => ['at' => (int) $p['at'], 'total' => (float) $p['total'], 'you' => (float) $p['you']], $points)),
            formatTime(unix) {
                const d = new Date(unix * 1000);
                return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            },
            onMove(e) {
                const rect = this.$refs.svg.getBoundingClientRect();
                const ratio = (e.clientX - rect.left) / rect.width;
                const idx = Math.max(0, Math.min(this.points.length - 1, Math.round(ratio * (this.points.length - 1))));
                this.tip = this.points[idx];
                this.tipX = (idx / Math.max(1, this.points.length - 1)) * 100;
            },
        }"
        x-on:mouseleave="tip = null"
    @endif
>
    @if (! $hasData)
        <div class="flex h-full w-full items-center justify-center rounded-lg border border-dashed border-brand-ink/10 bg-brand-sand/10 text-xs text-brand-mist">
            {{ __('No access history in this range yet.') }}
        </div>
    @else
        <svg
            x-ref="svg"
            x-on:mousemove.throttle.30ms="onMove($event)"
            viewBox="0 0 {{ $w }} {{ $h }}"
            preserveAspectRatio="none"
            class="absolute inset-0 h-full w-full"
            aria-hidden="true"
        >
            @foreach ([0.25, 0.5, 0.75] as $g)
                <line x1="0" x2="{{ $w }}" y1="{{ $h * (1 - $g) }}" y2="{{ $h * (1 - $g) }}"
                      stroke="rgb(15 23 42)" stroke-opacity="0.06" stroke-width="0.4"
                      vector-effect="non-scaling-stroke" />
            @endforeach

            <polyline points="{{ $totalPoints }}"
                      fill="none" stroke="rgb(47 95 79)" stroke-width="0.9"
                      vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />

            <polyline points="{{ $youPoints }}"
                      fill="none" stroke="rgb(180 83 9)" stroke-width="1.1"
                      vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />

            <line x-show="tip"
                  :x1="tipX * 10" :x2="tipX * 10" y1="0" y2="{{ $h }}"
                  stroke="rgb(47 95 79)" stroke-width="0.5"
                  stroke-opacity="0.45" vector-effect="non-scaling-stroke" />
        </svg>

        <div
            x-show="tip" x-cloak
            class="pointer-events-none absolute top-1 -translate-x-1/2 rounded-md border border-brand-ink/10 bg-white px-2 py-1 text-[10px] leading-tight text-brand-ink shadow-md"
            :style="`left: ${tipX}%`"
        >
            <div class="font-mono whitespace-nowrap" x-text="tip ? formatTime(tip.at) : ''"></div>
            <div class="mt-0.5 font-semibold tabular-nums text-brand-forest" x-text="tip ? `${tip.total} total` : ''"></div>
            <div class="text-[9px] text-amber-700 tabular-nums whitespace-nowrap" x-text="tip ? `${tip.you} you` : ''"></div>
        </div>
    @endif
</div>
