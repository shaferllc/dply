@props([
    'engine',
    'engineLabel',
    'row',
    'samples',
    'loaded',
    'error' => null,
    'card' => 'dply-card overflow-hidden',
])

@php
    $latest = ! empty($samples) ? end($samples) : null;
    $memorySeries = array_map(static fn ($s) => (float) ($s['used_memory'] ?? 0), $samples);
    $hitRateSeries = array_values(array_filter(
        array_map(static fn ($s) => $s['hit_rate_window'] ?? null, $samples),
        static fn ($v) => $v !== null,
    ));

    $renderSpark = static function (array $values): ?string {
        $n = count($values);
        if ($n < 2) {
            return null;
        }
        $min = min($values);
        $max = max($values);
        $den = max(0.0001, $max - $min);
        $w = 100.0;
        $h = 24.0;
        $pad = 1.0;
        $pts = [];
        foreach (array_values($values) as $i => $v) {
            $x = $pad + ($i / ($n - 1)) * ($w - 2 * $pad);
            $y = $h - $pad - (($v - $min) / $den) * ($h - 2 * $pad);
            $pts[] = round($x, 2).','.round($y, 2);
        }

        return implode(' ', $pts);
    };

    $memoryPoints = $renderSpark($memorySeries);
    $hitRatePoints = $renderSpark($hitRateSeries);
@endphp

<div class="{{ $card }}" wire:key="cache-keyspace-{{ $engine }}">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Keyspace') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __(':engine — keyspace dashboard', ['engine' => $engineLabel]) }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Live INFO sampling. Memory and clients are absolute; ops/sec and hit-rate are computed from the delta between the two latest samples.') }}</p>
        </div>
        <div class="flex shrink-0 flex-wrap gap-2 self-start whitespace-nowrap">
            @if (! $loaded && $error === null)
                <button
                    type="button"
                    wire:click="loadKeyspaceDashboard"
                    wire:loading.attr="disabled"
                    wire:target="loadKeyspaceDashboard"
                    class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    <x-heroicon-o-chart-bar class="h-3.5 w-3.5" aria-hidden="true" />
                    <span wire:loading.remove wire:target="loadKeyspaceDashboard">{{ __('Show dashboard') }}</span>
                    <span wire:loading wire:target="loadKeyspaceDashboard">{{ __('Loading…') }}</span>
                </button>
            @else
                <button
                    type="button"
                    wire:click="hideKeyspaceDashboard"
                    class="inline-flex items-center gap-2 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-eye-slash class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Hide') }}
                </button>
            @endif
        </div>
    </div>

    <div class="px-6 py-6 sm:px-7">
    <x-explainer class="mt-4">
        <p>{{ __('Each sample runs INFO over SSH, parses the cumulative counters Redis reports, and computes deltas against the previous sample to show throughput and hit-rate over the last sampling window.') }}</p>
        <p>{{ __('Sampling continues at 10s intervals while this card is open; closing the card pauses sampling and discards the buffer. The first sample shows windowed values as "—" because there\'s nothing to delta against yet.') }}</p>
    </x-explainer>

    @if ($error)
        <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $error }}</p>
    @elseif ($loaded && $latest)
        <div wire:poll.10000ms="pollKeyspaceDashboard" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Memory') }}</p>
                <p class="mt-1 font-mono text-base text-brand-ink">{{ $latest['used_memory_human'] ?: '—' }}</p>
                @if ($memoryPoints)
                    <svg viewBox="0 0 100 24" preserveAspectRatio="none" class="mt-2 h-6 w-full text-brand-forest" aria-hidden="true">
                        <polyline fill="none" stroke="currentColor" stroke-width="1.2" vector-effect="non-scaling-stroke" points="{{ $memoryPoints }}" />
                    </svg>
                @endif
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Connected clients') }}</p>
                <p class="mt-1 font-mono text-base text-brand-ink">{{ $latest['connected_clients'] }}</p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Ops / sec (window)') }}</p>
                <p class="mt-1 font-mono text-base text-brand-ink">
                    @if ($latest['ops_per_second_window'] !== null)
                        {{ number_format($latest['ops_per_second_window'], 1) }}
                    @else
                        —
                    @endif
                </p>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Hit rate (window)') }}</p>
                <p class="mt-1 font-mono text-base text-brand-ink">
                    @if ($latest['hit_rate_window'] !== null)
                        {{ number_format($latest['hit_rate_window'] * 100, 1) }}%
                    @else
                        —
                    @endif
                </p>
                @if ($hitRatePoints)
                    <svg viewBox="0 0 100 24" preserveAspectRatio="none" class="mt-2 h-6 w-full text-brand-forest" aria-hidden="true">
                        <polyline fill="none" stroke="currentColor" stroke-width="1.2" vector-effect="non-scaling-stroke" points="{{ $hitRatePoints }}" />
                    </svg>
                @endif
            </div>
        </div>
        <p class="mt-3 text-xs text-brand-mist">{{ __('Samples in buffer: :count / :max — refreshing every 10s.', ['count' => count($samples), 'max' => \App\Livewire\Servers\WorkspaceCaches::KEYSPACE_SAMPLE_LIMIT]) }}</p>
    @endif
    </div>
</div>
