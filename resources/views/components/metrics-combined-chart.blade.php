@props([
    'snapshots',
])

@php
    $snapshots = $snapshots->values();
    $n = $snapshots->count();
    $w = 100;
    $h = 38;
    $pad = 2;

    $cpu = $snapshots->map(fn ($s) => isset($s->payload['cpu_pct']) ? (float) $s->payload['cpu_pct'] : null)->all();
    $mem = $snapshots->map(fn ($s) => isset($s->payload['mem_pct']) ? (float) $s->payload['mem_pct'] : null)->all();
    $disk = $snapshots->map(fn ($s) => isset($s->payload['disk_pct']) ? (float) $s->payload['disk_pct'] : null)->all();
    $load = $snapshots->map(fn ($s) => isset($s->payload['load_1m']) ? (float) $s->payload['load_1m'] : null)->all();

    $maxLoad = $n > 0 ? max(1.0, max(array_map(fn ($value) => $value ?? 0.0, $load)) * 1.15) : 1.0;

    $fmtBytes = function (?int $b): string {
        if ($b === null || $b < 0) {
            return '—';
        }
        if ($b === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $b;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return number_format($v, $i > 0 ? 1 : 0).' '.$units[$i];
    };
    $fmtRate = function (?float $bytesPerSecond) use ($fmtBytes): string {
        if ($bytesPerSecond === null || $bytesPerSecond < 0) {
            return '—';
        }

        return $fmtBytes((int) round($bytesPerSecond)).'/s';
    };
    $fmtDuration = function (?int $seconds): string {
        if ($seconds === null || $seconds < 0) {
            return '—';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h";
        }
        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    };

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
            $y = $yFn((float) ($vals[0] ?? 0));

            return $x.','.$y.' '.$x.','.$y;
        }
        $pts = [];
        foreach ($vals as $i => $v) {
            if ($v === null) {
                continue;
            }
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

    $points = $snapshots->values()->map(function ($snapshot, $index) use ($n, $w, $pad, $yPercent, $yLoad, $fmtBytes, $fmtRate, $fmtDuration): array {
        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $x = $n === 1 ? $w / 2 : $pad + ($index / ($n - 1)) * ($w - 2 * $pad);
        $xPct = $n === 1 ? 50.0 : ($index / max($n - 1, 1)) * 100;

        $memoryAvailableBytes = isset($payload['mem_available_kb']) ? (int) $payload['mem_available_kb'] * 1024 : null;
        $memoryTotalBytes = isset($payload['mem_total_kb']) ? (int) $payload['mem_total_kb'] * 1024 : null;
        $swapTotalBytes = isset($payload['swap_total_kb']) ? (int) $payload['swap_total_kb'] * 1024 : null;
        $swapUsedBytes = isset($payload['swap_used_kb']) ? (int) $payload['swap_used_kb'] * 1024 : null;

        return [
            'label' => $snapshot->captured_at?->timezone(config('app.timezone'))->format('M j, Y H:i:s T'),
            'x' => round($x, 3),
            'x_pct' => round($xPct, 3),
            'guideline_left' => min(max($xPct, 8), 92),
            'values' => [
                'cpu' => isset($payload['cpu_pct']) ? number_format((float) $payload['cpu_pct'], 1).'%' : '—',
                'mem' => isset($payload['mem_pct']) ? number_format((float) $payload['mem_pct'], 1).'%' : '—',
                'disk' => isset($payload['disk_pct']) ? number_format((float) $payload['disk_pct'], 1).'%' : '—',
                'load' => isset($payload['load_1m']) ? number_format((float) $payload['load_1m'], 2) : '—',
                'load_detail' => isset($payload['load_5m'], $payload['load_15m'])
                    ? number_format((float) $payload['load_5m'], 2).' / '.number_format((float) $payload['load_15m'], 2)
                    : '—',
                'memory_available' => $fmtBytes($memoryAvailableBytes),
                'memory_total' => $fmtBytes($memoryTotalBytes),
                'disk_used' => $fmtBytes(isset($payload['disk_used_bytes']) ? (int) $payload['disk_used_bytes'] : null),
                'disk_total' => $fmtBytes(isset($payload['disk_total_bytes']) ? (int) $payload['disk_total_bytes'] : null),
                'disk_free' => $fmtBytes(isset($payload['disk_free_bytes']) ? (int) $payload['disk_free_bytes'] : null),
                'swap_used' => $fmtBytes($swapUsedBytes),
                'swap_total' => $fmtBytes($swapTotalBytes),
                'inode_pct_root' => isset($payload['inode_pct_root']) ? number_format((float) $payload['inode_pct_root'], 1).'%' : '—',
                'cpu_count' => isset($payload['cpu_count']) ? (string) ((int) $payload['cpu_count']) : '—',
                'load_per_cpu_1m' => isset($payload['load_per_cpu_1m']) ? number_format((float) $payload['load_per_cpu_1m'], 2) : '—',
                'uptime' => $fmtDuration(isset($payload['uptime_seconds']) ? (int) $payload['uptime_seconds'] : null),
                'rx' => $fmtRate(isset($payload['rx_bytes_per_sec']) ? (float) $payload['rx_bytes_per_sec'] : null),
                'tx' => $fmtRate(isset($payload['tx_bytes_per_sec']) ? (float) $payload['tx_bytes_per_sec'] : null),
            ],
            'y' => [
                'cpu' => isset($payload['cpu_pct']) ? round($yPercent((float) $payload['cpu_pct']), 3) : null,
                'mem' => isset($payload['mem_pct']) ? round($yPercent((float) $payload['mem_pct']), 3) : null,
                'disk' => isset($payload['disk_pct']) ? round($yPercent((float) $payload['disk_pct']), 3) : null,
                'load' => isset($payload['load_1m']) ? round($yLoad((float) $payload['load_1m']), 3) : null,
            ],
        ];
    })->all();
@endphp

<div
    {{ $attributes->merge(['class' => 'relative w-full']) }}
    x-data="{
        points: @js($points),
        activeIndex: null,
        get activePoint() {
            return this.activeIndex === null ? null : (this.points[this.activeIndex] ?? null);
        }
    }"
>
    @if ($n === 0)
        <div class="flex h-36 items-center justify-center rounded-lg bg-brand-sand/40 text-sm text-brand-mist">
            {{ __('No data') }}
        </div>
    @else
        <div class="relative rounded-xl bg-brand-sand/30 p-3" @mouseleave="activeIndex = null">
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
                <template x-if="activePoint !== null">
                    <g>
                        <line
                            :x1="activePoint ? activePoint.x : 0"
                            y1="{{ $pad }}"
                            :x2="activePoint ? activePoint.x : 0"
                            y2="{{ $h - $pad }}"
                            stroke="currentColor"
                            stroke-width="0.25"
                            vector-effect="non-scaling-stroke"
                            class="text-brand-ink/30"
                        ></line>
                        <circle
                            x-show="activePoint && activePoint.y.cpu !== null"
                            :cx="activePoint ? activePoint.x : 0"
                            :cy="activePoint ? activePoint.y.cpu : 0"
                            r="0.85"
                            fill="currentColor"
                            class="text-sky-600"
                        ></circle>
                        <circle
                            x-show="activePoint && activePoint.y.mem !== null"
                            :cx="activePoint ? activePoint.x : 0"
                            :cy="activePoint ? activePoint.y.mem : 0"
                            r="0.85"
                            fill="currentColor"
                            class="text-rose-600"
                        ></circle>
                        <circle
                            x-show="activePoint && activePoint.y.disk !== null"
                            :cx="activePoint ? activePoint.x : 0"
                            :cy="activePoint ? activePoint.y.disk : 0"
                            r="0.85"
                            fill="currentColor"
                            class="text-amber-600"
                        ></circle>
                        <circle
                            x-show="activePoint && activePoint.y.load !== null"
                            :cx="activePoint ? activePoint.x : 0"
                            :cy="activePoint ? activePoint.y.load : 0"
                            r="0.85"
                            fill="currentColor"
                            class="text-brand-forest"
                        ></circle>
                    </g>
                </template>
            </svg>
            <div class="absolute inset-x-3 top-3 h-40">
                <div class="flex h-full">
                    @foreach ($points as $index => $point)
                        <button
                            type="button"
                            class="h-full min-w-0 flex-1 bg-transparent focus:outline-none"
                            @mouseenter="activeIndex = {{ $index }}"
                            @focus="activeIndex = {{ $index }}"
                            @blur="activeIndex = null"
                            aria-label="{{ __('Inspect sample from :time', ['time' => $point['label']]) }}"
                        ></button>
                    @endforeach
                </div>
            </div>
            <div
                x-cloak
                x-show="activePoint !== null"
                x-transition.opacity.duration.100ms
                class="pointer-events-none absolute top-4 z-10 w-72 rounded-xl border border-brand-ink/10 bg-white/95 p-4 shadow-lg backdrop-blur"
                :style="activePoint ? `left: ${activePoint.guideline_left}%; transform: translateX(-50%);` : ''"
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Sample') }}</p>
                <p class="mt-1 text-sm font-semibold text-brand-ink" x-text="activePoint ? activePoint.label : ''"></p>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-xs text-brand-ink">
                    <div>
                        <dt class="text-brand-moss">{{ __('CPU') }}</dt>
                        <dd class="font-medium" x-text="activePoint ? activePoint.values.cpu : ''"></dd>
                    </div>
                    <div>
                        <dt class="text-brand-moss">{{ __('Load') }}</dt>
                        <dd class="font-medium">
                            <span x-text="activePoint ? activePoint.values.load : ''"></span>
                            <span class="text-brand-mist"> / </span>
                            <span x-text="activePoint ? activePoint.values.load_detail : ''"></span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-brand-moss">{{ __('Memory') }}</dt>
                        <dd class="font-medium">
                            <span x-text="activePoint ? activePoint.values.mem : ''"></span>
                            <span class="text-brand-mist"> · </span>
                            <span x-text="activePoint ? activePoint.values.memory_available : ''"></span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-brand-moss">{{ __('Swap') }}</dt>
                        <dd class="font-medium">
                            <span x-text="activePoint ? activePoint.values.swap_used : ''"></span>
                            <span class="text-brand-mist"> / </span>
                            <span x-text="activePoint ? activePoint.values.swap_total : ''"></span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-brand-moss">{{ __('Disk') }}</dt>
                        <dd class="font-medium">
                            <span x-text="activePoint ? activePoint.values.disk : ''"></span>
                            <span class="text-brand-mist"> · </span>
                            <span x-text="activePoint ? activePoint.values.disk_free : ''"></span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-brand-moss">{{ __('Inodes') }}</dt>
                        <dd class="font-medium" x-text="activePoint ? activePoint.values.inode_pct_root : ''"></dd>
                    </div>
                    <div>
                        <dt class="text-brand-moss">{{ __('CPU fit') }}</dt>
                        <dd class="font-medium">
                            <span x-text="activePoint ? activePoint.values.load_per_cpu_1m : ''"></span>
                            <span class="text-brand-mist"> · </span>
                            <span x-text="activePoint ? activePoint.values.cpu_count : ''"></span>
                            <span class="text-brand-mist">{{ __(' cores') }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-brand-moss">{{ __('Uptime') }}</dt>
                        <dd class="font-medium" x-text="activePoint ? activePoint.values.uptime : ''"></dd>
                    </div>
                    <div>
                        <dt class="text-brand-moss">{{ __('Inbound') }}</dt>
                        <dd class="font-medium" x-text="activePoint ? activePoint.values.rx : ''"></dd>
                    </div>
                    <div>
                        <dt class="text-brand-moss">{{ __('Outbound') }}</dt>
                        <dd class="font-medium" x-text="activePoint ? activePoint.values.tx : ''"></dd>
                    </div>
                </dl>
            </div>
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
                {{ __('CPU, memory, and disk share the 0–100% scale (horizontal lines at 25%, 50%, 75%). Load uses its own vertical scale in this window (top ≈ :max). Hover the graph for fuller sample details.', ['max' => number_format($maxLoad, 2)]) }}
            </p>
        </div>
    @endif
</div>
