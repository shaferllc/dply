@props([
    /** Current fleet-average health, 0–100, or null when nothing has reported. */
    'healthScore' => null,
    /** @var iterable<int, array{day: \Illuminate\Support\Carbon, score: float}> oldest first */
    'healthSeries' => [],
    /** @var array{critical: int, warning: int, info: int} */
    'severity' => ['critical' => 0, 'warning' => 0, 'info' => 0],
    'totalOpen' => 0,
    /** @var iterable<int, string> deploy statuses, oldest first */
    'deployOutcomes' => [],
    'serverCount' => 0,
    'serversWithFindings' => 0,
])

{{--
    Four figures across the head of the dashboard: where fleet health is going,
    what the open findings are made of, how deploys have been landing, and how
    much of the fleet is asking for something.

    Every value is passed in already computed — this file only draws.
--}}

@php
    $scores = collect($healthSeries)->pluck('score')->map(fn ($v): float => (float) $v)->values();
    $outcomes = collect($deployOutcomes)->values();

    $healthTone = match (true) {
        $healthScore === null => 'text-brand-mist',
        $healthScore >= 80 => 'text-brand-sage',
        $healthScore >= 50 => 'text-amber-600',
        default => 'text-rose-600',
    };

    // Trend over the window we actually have, not a fixed period — see
    // dailyHealthSeries() for why the x-axis is uneven.
    $delta = $scores->count() >= 2 ? round($scores->last() - $scores->first(), 1) : null;
    $spanDays = collect($healthSeries)->count();

    // Sparkline geometry. Floor pinned to zero with 15% headroom over the
    // series max — the same scaling x-metrics-sparkline uses. A full 0–100
    // axis is defensible but flattens a mid-40s fleet onto the baseline, and
    // the figure beside the chart already carries the level; the chart's job
    // is the direction.
    $w = 120;
    $h = 26;
    $pad = 2.5;
    $line = null;
    $area = null;
    if ($scores->count() >= 2) {
        $n = $scores->count();
        $ceiling = min(100.0, max(1.0, $scores->max() * 1.15));
        $points = $scores->map(function (float $v, int $i) use ($n, $w, $h, $pad, $ceiling): array {
            $x = $pad + ($i / ($n - 1)) * ($w - 2 * $pad);
            $y = $h - $pad - (max(0.0, min($ceiling, $v)) / $ceiling) * ($h - 2 * $pad);

            return [round($x, 2), round($y, 2)];
        })->all();

        $line = collect($points)->map(fn (array $p): string => $p[0].','.$p[1])->implode(' ');
        $area = 'M'.$points[0][0].','.$h
            .' L'.$line
            .' L'.$points[$n - 1][0].','.$h.' Z';
    }

    $settled = $outcomes->count();
    $succeeded = $outcomes->filter(fn (string $s): bool => $s === 'success')->count();
    $successRate = $settled > 0 ? (int) round($succeeded / $settled * 100) : null;
    $deployTone = match (true) {
        $successRate === null => 'text-brand-mist',
        $successRate >= 90 => 'text-brand-sage',
        $successRate >= 50 => 'text-amber-600',
        default => 'text-rose-600',
    };

    $cell = 'flex min-w-0 flex-col gap-1.5 border-brand-ink/8 px-4 py-3';
    $label = 'truncate text-2xs font-semibold uppercase tracking-wide text-brand-mist';
    $value = 'font-mono text-2xl font-bold leading-none tracking-tight tabular-nums';
    $note = 'truncate font-mono text-2xs tabular-nums text-brand-mist';
@endphp

<dl {{ $attributes->class(['grid grid-cols-2 sm:grid-cols-4']) }}>

    {{-- 1 · Fleet health, with its own trend --}}
    <div class="{{ $cell }}">
        <dt class="{{ $label }}">{{ __('Fleet health') }}</dt>
        <dd class="flex items-baseline justify-between gap-2">
            <span class="{{ $value }} {{ $healthTone }}">{{ $healthScore === null ? '—' : (int) $healthScore }}</span>
            @if ($delta !== null)
                <span @class([
                    'shrink-0 font-mono text-2xs font-medium tabular-nums',
                    'text-rose-600' => $delta < 0,
                    'text-brand-sage' => $delta > 0,
                    'text-brand-mist' => $delta == 0,
                ])>
                    @if ($delta > 0)
                        &#9650; {{ $delta }}
                    @elseif ($delta < 0)
                        &#9660; {{ abs($delta) }}
                    @else
                        {{ __('flat') }}
                    @endif
                    <span class="text-brand-mist">· {{ trans_choice('{1}:count day|[2,*]:count days', $spanDays, ['count' => $spanDays]) }}</span>
                </span>
            @endif
        </dd>
        @if ($line !== null)
            <svg viewBox="0 0 {{ $w }} {{ $h }}" class="block h-8 w-full {{ $healthTone }}" preserveAspectRatio="none" role="img"
                 aria-label="{{ __('Fleet health across the last :count recorded days', ['count' => $spanDays]) }}">
                <path d="{{ $area }}" fill="currentColor" opacity="0.18" />
                <polyline points="{{ $line }}" fill="none" stroke="currentColor" stroke-width="1.5"
                          stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
            </svg>
        @else
            <p class="{{ $note }}">{{ __('Not enough history to chart') }}</p>
        @endif
    </div>

    {{-- 2 · What the open findings are made of --}}
    <div class="{{ $cell }} border-l">
        <dt class="{{ $label }}">{{ __('Open findings') }}</dt>
        <dd class="flex items-baseline justify-between gap-2">
            <span @class([$value, 'text-rose-600' => $severity['critical'] > 0, 'text-brand-ink' => $severity['critical'] === 0])>{{ $totalOpen }}</span>
            <span class="shrink-0 font-mono text-2xs tabular-nums text-brand-mist">
                {{ __(':count critical', ['count' => $severity['critical']]) }}
            </span>
        </dd>
        @if ($totalOpen > 0)
            <div class="flex h-1.5 overflow-hidden rounded-full bg-brand-ink/10" role="img"
                 aria-label="{{ __(':crit critical, :warn warning, :info info', ['crit' => $severity['critical'], 'warn' => $severity['warning'], 'info' => $severity['info']]) }}">
                @if ($severity['critical'] > 0)
                    <span class="block bg-rose-500" style="flex: {{ $severity['critical'] }}"></span>
                @endif
                @if ($severity['warning'] > 0)
                    <span class="block bg-amber-500" style="flex: {{ $severity['warning'] }}"></span>
                @endif
                @if ($severity['info'] > 0)
                    <span class="block bg-sky-500" style="flex: {{ $severity['info'] }}"></span>
                @endif
            </div>
            <p class="{{ $note }}">
                {{ $severity['warning'] }} {{ __('warning') }}
                <span aria-hidden="true" class="text-brand-mist/60">·</span>
                {{ $severity['info'] }} {{ __('info') }}
            </p>
        @else
            <p class="{{ $note }}">{{ __('Nothing open') }}</p>
        @endif
    </div>

    {{-- 3 · How the last handful of deploys landed --}}
    <div class="{{ $cell }} border-t border-l-0 sm:border-l sm:border-t-0">
        <dt class="{{ $label }}">{{ __('Deploy success') }}</dt>
        <dd class="flex items-baseline justify-between gap-2">
            <span class="{{ $value }} {{ $deployTone }}">{{ $successRate === null ? '—' : $successRate.'%' }}</span>
            @if ($settled > 0)
                <span class="shrink-0 font-mono text-2xs tabular-nums text-brand-mist">
                    {{ __(':ok of :total', ['ok' => $succeeded, 'total' => $settled]) }}
                </span>
            @endif
        </dd>
        @if ($settled > 0)
            <div class="flex h-6 items-end gap-[2px]" role="img"
                 aria-label="{{ __('Outcome of the last :count deploys, oldest first', ['count' => $settled]) }}">
                @foreach ($outcomes as $status)
                    {{-- One bar per deploy, all the same height: colour already
                         carries the outcome, so height would be decoration. --}}
                    <span @class([
                        'block h-full flex-1 rounded-[1px]',
                        'bg-brand-sage' => $status === 'success',
                        'bg-rose-500' => $status === 'failed',
                        'bg-brand-mist/60' => $status === 'skipped',
                    ])></span>
                @endforeach
            </div>
            <p class="{{ $note }}">{{ __('last :count deploys', ['count' => $settled]) }}</p>
        @else
            <p class="{{ $note }}">{{ __('No deploys yet') }}</p>
        @endif
    </div>

    {{-- 4 · How much of the fleet is asking for something --}}
    <div class="{{ $cell }} border-t border-l sm:border-t-0">
        <dt class="{{ $label }}">{{ __('Servers') }}</dt>
        <dd class="flex items-baseline justify-between gap-2">
            <span class="{{ $value }} text-brand-ink">{{ $serverCount }}</span>
            @if ($serversWithFindings > 0)
                <span class="shrink-0 font-mono text-2xs tabular-nums text-rose-600">
                    {{ __(':count need attention', ['count' => $serversWithFindings]) }}
                </span>
            @endif
        </dd>
        @if ($serverCount > 0)
            <div class="flex h-1.5 overflow-hidden rounded-full bg-brand-ink/10" role="img"
                 aria-label="{{ __(':count of :total servers have open findings', ['count' => $serversWithFindings, 'total' => $serverCount]) }}">
                @if ($serversWithFindings > 0)
                    <span class="block bg-rose-500" style="flex: {{ $serversWithFindings }}"></span>
                @endif
                @if ($serverCount - $serversWithFindings > 0)
                    <span class="block bg-brand-sage" style="flex: {{ $serverCount - $serversWithFindings }}"></span>
                @endif
            </div>
            <p class="{{ $note }}">
                {{ __(':count clean', ['count' => max(0, $serverCount - $serversWithFindings)]) }}
            </p>
        @endif
    </div>

</dl>
