@props([
    'snapshots',
])

@php
    $snapshots = $snapshots->values();
    $n = $snapshots->count();
    $w = 100;
    $h = 38;
    $pad = 2;

    $cpu = $snapshots->map(fn ($s) => (float) ($s->payload['cpu_pct'] ?? 0))->all();
    $mem = $snapshots->map(fn ($s) => (float) ($s->payload['mem_pct'] ?? 0))->all();
    $disk = $snapshots->map(fn ($s) => (float) ($s->payload['disk_pct'] ?? 0))->all();
    $load = $snapshots->map(fn ($s) => (float) ($s->payload['load_1m'] ?? 0))->all();

    $maxLoad = $n > 0 ? max(1.0, max($load) * 1.15) : 1.0;

    $yPercent = function (float $v) use ($h, $pad): float {
        $v = max(0.0, min(100.0, $v));

        return $h - $pad - ($v / 100.0) * ($h - 2 * $pad);
    };

    $yLoad = function (float $v) use ($h, $pad, $maxLoad): float {
        $v = max(0.0, $v);

        return $h - $pad - ($maxLoad > 0 ? ($v / $maxLoad) * ($h - 2 * $pad) : 0);
    };

    $buildPoly = function (array $vals, callable $yFn) use ($n, $w, $h, $pad): string {
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            $x = $w / 2;
            $y = $yFn($vals[0]);

            return $x.','.$y.' '.$x.','.$y;
        }
        $pts = [];
        foreach ($vals as $i => $v) {
            $x = $pad + ($i / ($n - 1)) * ($w - 2 * $pad);
            $y = $yFn($v);
            $pts[] = round($x, 3).','.round($y, 3);
        }

        return implode(' ', $pts);
    };

    $pointsCpu = $buildPoly($cpu, $yPercent);
    $pointsMem = $buildPoly($mem, $yPercent);
    $pointsDisk = $buildPoly($disk, $yPercent);
    $pointsLoad = $buildPoly($load, $yLoad);

    $gridYs = [];
    foreach ([25, 50, 75] as $pct) {
        $gridYs[] = $yPercent((float) $pct);
    }
@endphp

<div {{ $attributes->merge(['class' => 'relative w-full']) }}>
    @if ($n === 0)
        <div class="flex h-36 items-center justify-center rounded-lg bg-brand-sand/40 text-sm text-brand-mist">
            {{ __('No data') }}
        </div>
    @else
        <div class="rounded-xl bg-brand-sand/30 p-3">
            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-40 w-full" preserveAspectRatio="none" aria-hidden="true">
                @foreach ($gridYs as $gy)
                    <line
                        x1="{{ $pad }}"
                        y1="{{ $gy }}"
                        x2="{{ $w - $pad }}"
                        y2="{{ $gy }}"
                        stroke="currentColor"
                        stroke-width="0.15"
                        vector-effect="non-scaling-stroke"
                        class="text-brand-ink/10"
                    />
                @endforeach
                <polyline
                    fill="none"
                    stroke="currentColor"
                    stroke-width="0.65"
                    vector-effect="non-scaling-stroke"
                    points="{{ $pointsCpu }}"
                    class="text-sky-600"
                />
                <polyline
                    fill="none"
                    stroke="currentColor"
                    stroke-width="0.65"
                    vector-effect="non-scaling-stroke"
                    points="{{ $pointsMem }}"
                    class="text-rose-600"
                />
                <polyline
                    fill="none"
                    stroke="currentColor"
                    stroke-width="0.65"
                    vector-effect="non-scaling-stroke"
                    points="{{ $pointsDisk }}"
                    class="text-amber-600"
                />
                <polyline
                    fill="none"
                    stroke="currentColor"
                    stroke-width="0.75"
                    vector-effect="non-scaling-stroke"
                    points="{{ $pointsLoad }}"
                    class="text-brand-forest"
                />
            </svg>
            <div class="mt-3 flex flex-wrap gap-x-6 gap-y-2 text-xs text-brand-moss">
                <span class="inline-flex items-center gap-2">
                    <span class="h-0.5 w-5 shrink-0 rounded-full bg-sky-600" aria-hidden="true"></span>
                    {{ __('CPU %') }}
                </span>
                <span class="inline-flex items-center gap-2">
                    <span class="h-0.5 w-5 shrink-0 rounded-full bg-rose-600" aria-hidden="true"></span>
                    {{ __('Memory %') }}
                </span>
                <span class="inline-flex items-center gap-2">
                    <span class="h-0.5 w-5 shrink-0 rounded-full bg-amber-600" aria-hidden="true"></span>
                    {{ __('Disk %') }} ({{ __('root') }})
                </span>
                <span class="inline-flex items-center gap-2">
                    <span class="h-0.5 w-5 shrink-0 rounded-full bg-brand-forest" aria-hidden="true"></span>
                    {{ __('Load (1m)') }}
                </span>
            </div>
            <p class="mt-2 text-xs leading-relaxed text-brand-mist">
                {{ __('CPU, memory, and disk share the 0–100% scale (horizontal lines at 25%, 50%, 75%). Load uses its own vertical scale in this window (top ≈ :max).', ['max' => number_format($maxLoad, 2)]) }}
            </p>
        </div>
    @endif
</div>
