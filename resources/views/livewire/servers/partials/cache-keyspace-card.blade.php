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

    {{-- In-body active-load indicator. The "Show dashboard" button only shows
         a spinner up top while loadKeyspaceDashboard is in flight; without
         this block the body stayed on the "Dashboard is paused" idle hint
         during the 1-3s SSH round-trip, making the click look like a no-op.
         When the response lands, wire:loading clears and the @if branch below
         takes over with real samples (or the "first sample in flight"
         skeleton for the initial render). --}}
    <div wire:loading wire:target="loadKeyspaceDashboard" class="mt-4">
        <div class="flex items-start gap-3 rounded-xl border border-sky-200 bg-sky-50/70 px-4 py-3 text-xs text-sky-900">
            <svg class="mt-0.5 h-4 w-4 shrink-0 animate-spin text-sky-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10" opacity="0.25" />
                <path d="M22 12a10 10 0 0 1-10 10" stroke-linecap="round" />
            </svg>
            <div class="min-w-0">
                <p class="font-semibold">{{ __('Starting the keyspace dashboard…') }}</p>
                <p class="mt-0.5 text-sky-800/90">{{ __('Captures the first INFO sample over SSH, then samples every 10s while this card stays open. Ops/sec and hit-rate need a second sample to compute deltas, so those tiles fill in on the next refresh.') }}</p>
            </div>
        </div>
        <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach (['Memory', 'Connected clients', 'Ops / sec (window)', 'Hit rate (window)'] as $label)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __($label) }}</p>
                    <div class="mt-2 h-5 w-20 animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="mt-3 h-4 w-full animate-pulse rounded bg-brand-ink/5"></div>
                </div>
            @endforeach
        </div>
    </div>

    <div wire:loading.remove wire:target="loadKeyspaceDashboard">
    @if ($error)
        <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $error }}</p>
    @elseif ($loaded && ! $latest)
        {{-- First sample in flight: render a 4-tile shimmer skeleton plus a
             status line so the operator knows we're waiting on the engine,
             not stuck. wire:poll keeps trying every 10s so when the first
             INFO response lands the skeleton swaps for real values without
             needing manual refresh. --}}
        <div wire:poll.10000ms="pollKeyspaceDashboard" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach (['Memory', 'Connected clients', 'Ops / sec (window)', 'Hit rate (window)'] as $label)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __($label) }}</p>
                    <div class="mt-2 h-5 w-20 animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="mt-3 h-4 w-full animate-pulse rounded bg-brand-ink/5"></div>
                </div>
            @endforeach
        </div>
        <p class="mt-3 flex items-center gap-1.5 text-xs text-brand-mist">
            <svg class="h-3 w-3 animate-spin text-brand-forest" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                <circle cx="12" cy="12" r="10" opacity="0.25" />
                <path d="M22 12a10 10 0 0 1-10 10" stroke-linecap="round" />
            </svg>
            <span>{{ __('Waiting for the first INFO sample from the engine — runs over SSH, ~1-3s typical.') }}</span>
        </p>
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
    @else
        {{-- Idle empty state. Matches the "no clients snapshot" pattern: dashed
             border + sand tint, centered icon avatar in the same sage chip the
             card header uses, bold title, helper copy that inlines the actual
             button styling so the operator sees what they're being told to
             click. --}}
        <div class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-8 text-center">
            <span class="mx-auto flex h-10 w-10 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
            </span>
            <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('Dashboard is paused') }}</p>
            <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                {{ __('Hit') }}
                <span class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-1.5 py-0.5 align-middle text-[11px] font-medium text-brand-ink">
                    <x-heroicon-o-chart-bar class="h-3 w-3" aria-hidden="true" />
                    {{ __('Show dashboard') }}
                </span>
                {{ __('above to start sampling INFO every 10s. Memory and connected-clients are absolute; ops/sec and hit-rate compute from deltas between samples, so those tiles light up after the second refresh.') }}
            </p>
        </div>
    @endif
    </div>{{-- /wire:loading.remove wrapper for keyspace body --}}
    </div>
</div>
