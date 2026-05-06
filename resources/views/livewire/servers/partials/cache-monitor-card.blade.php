@props([
    'engine',
    'engineLabel',
    'row',
    'runId',
    'duration' => 10,
    'payload' => null,
    'replUnlocked' => false,
    'card' => 'dply-card overflow-hidden',
])

@php
    $running = $runId !== '';
    $lines = $payload['lines'] ?? [];
    $status = $payload['status'] ?? null;
    $error = $payload['error'] ?? null;
@endphp

<div class="{{ $card }} p-6 sm:p-8" wire:key="cache-monitor-{{ $engine }}">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h3 class="text-lg font-semibold text-brand-ink">{{ __(':engine — live MONITOR', ['engine' => $engineLabel]) }}</h3>
            <p class="mt-2 text-sm text-brand-moss">{{ __('Tails redis-cli MONITOR for a bounded window so you can watch traffic against this instance live. Auto-stops when the window ends.') }}</p>
        </div>
        @if (! $running && ($payload !== null))
            <button
                type="button"
                wire:click="clearMonitorOutput"
                class="inline-flex shrink-0 items-center gap-2 self-start whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
            >
                <x-heroicon-o-trash class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Clear') }}
            </button>
        @endif
    </div>

    <x-explainer class="mt-4" tone="warn">
        <p>{{ __('MONITOR is read-only — it doesn\'t change keys — but it forces the engine to copy every command across all connections to this client. On a hot cache that costs a meaningful slice of CPU, so use a short window (5–30 s).') }}</p>
        <p>{{ __('Output is bounded at 500 lines (oldest dropped). The window stops itself even if the browser tab is closed; the audit log records the started + completed event with the line count.') }}</p>
        <p>{{ __('Requires the unlock toggle in the Console sub-tab so the cost is an explicit choice.') }}</p>
    </x-explainer>

    @if (! $running)
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <span class="text-xs text-brand-mist">{{ __('Window') }}</span>
            @foreach ([5, 10, 30] as $opt)
                <button
                    type="button"
                    wire:click="startMonitor({{ $opt }})"
                    @disabled(! $replUnlocked)
                    @class([
                        'inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium',
                        'border-brand-forest/30 bg-brand-forest/10 text-brand-forest hover:bg-brand-forest/15' => $replUnlocked,
                        'cursor-not-allowed border-brand-ink/15 bg-brand-sand/30 text-brand-mist' => ! $replUnlocked,
                    ])
                >
                    <x-heroicon-o-play class="h-3 w-3" />
                    {{ __(':n s', ['n' => $opt]) }}
                </button>
            @endforeach
            @if (! $replUnlocked)
                <span class="text-xs text-brand-mist">{{ __('— unlock the Console toggle to enable.') }}</span>
            @endif
        </div>
    @else
        <div wire:poll.1000ms="pollMonitorOutput" class="mt-4 flex flex-wrap items-center gap-2 rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
            <x-spinner variant="forest" />
            <span>{{ __('MONITOR running for :n seconds — chunks stream below as Redis emits them.', ['n' => $duration]) }}</span>
        </div>
    @endif

    @if ($payload !== null)
        <div class="mt-4 max-h-96 overflow-auto rounded-xl border border-brand-ink/10 bg-brand-ink/95 p-3 font-mono text-[11px] leading-relaxed text-emerald-100"
             x-data x-init="$el.scrollTop = $el.scrollHeight" x-effect="$el.scrollTop = $el.scrollHeight">
            @if (empty($lines))
                <p class="text-brand-mist/80 px-1">{{ __('No commands captured yet…') }}</p>
            @else
                @foreach ($lines as $line)
                    <div class="break-all">{{ $line }}</div>
                @endforeach
            @endif
        </div>

        @if ($error)
            <p class="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs leading-relaxed text-rose-800">{{ $error }}</p>
        @elseif ($status === 'completed')
            <p class="mt-3 text-xs text-brand-moss">{{ __('Window ended. Captured :n lines.', ['n' => count($lines)]) }}</p>
        @endif
    @endif
</div>
