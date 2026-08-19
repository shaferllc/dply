@php
    /**
     * One deploy step row + its auto-expanding output console. Shared by the
     * full Deploy-tab timeline and the combined Sync console so both render
     * steps — and their running/auto-expand behaviour — identically.
     *
     * @var array<string, mixed> $step        From SiteDeployTimeline step view.
     * @var string               $stepKeyBase Unique wire:key base for this step
     *                                         (status token is appended here), so
     *                                         the console re-initialises (and
     *                                         auto-collapses) on a running → done
     *                                         transition across poll renders.
     */
    $stepRunning = $step['running'] ?? false;
    $stepPending = ($step['pending'] ?? false) && ! $stepRunning;
    $stepFailed = ! ($step['ok'] ?? false) && ! ($step['skipped'] ?? false) && ! $stepPending && ! $stepRunning;
    $hasOutput = trim((string) ($step['output'] ?? '')) !== '';
    $statusToken = $stepFailed ? 'failed' : ($stepRunning ? 'running' : ($stepPending ? 'pending' : 'ok'));
    // Detail page "Step output" toggle opens every console that has content.
    $forceShowOutput = (bool) ($showOutput ?? false);
    $autoOpen = $stepRunning || $stepFailed || ($forceShowOutput && $hasOutput);
    $outputKey = $forceShowOutput ? 'out' : 'hid';
@endphp

<li>
    <div class="flex items-center gap-2.5 text-xs">
        <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $step['glyph_classes'] }}">
            @if ($stepRunning)
                <x-heroicon-m-arrow-path class="h-3 w-3 animate-spin" aria-hidden="true" />
            @else
                {{ $step['glyph'] }}
            @endif
        </span>
        <span @class([
            'min-w-0 truncate',
            'font-medium text-rose-800' => $stepFailed,
            'font-semibold text-amber-800' => $stepRunning,
            'text-brand-mist' => $stepPending,
            'text-brand-ink' => ! $stepFailed && ! $stepPending && ! $stepRunning,
        ])>{{ $step['label'] }}</span>
        @if ($stepRunning)
            <span class="rounded-md bg-amber-100 px-1.5 py-0.5 text-xs font-semibold uppercase tracking-[0.1em] text-amber-800 ring-1 ring-inset ring-amber-200/70">{{ __('running') }}</span>
        @elseif ($stepPending)
            <span class="rounded-md bg-brand-sand/40 px-1.5 py-0.5 text-xs font-semibold uppercase tracking-[0.1em] text-brand-mist">{{ __('queued') }}</span>
        @elseif ($step['skipped'])
            <span class="rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-semibold uppercase tracking-[0.1em] text-amber-800 ring-1 ring-inset ring-amber-200/70">{{ __('skipped') }}</span>
        @elseif (($step['duration_ms'] ?? 0) > 0)
            <span class="font-mono tabular-nums text-brand-mist">{{ $step['duration_ms'] >= 1000 ? number_format($step['duration_ms'] / 1000, 1).'s' : $step['duration_ms'].'ms' }}</span>
        @endif
    </div>
    @if ($hasOutput || $stepRunning)
        <div wire:key="{{ $stepKeyBase }}-{{ $statusToken }}-{{ $outputKey }}" x-data="{ open: @js($autoOpen) }" class="mt-1.5 pl-8">
            <button type="button" x-on:click="open = ! open"
                class="inline-flex items-center gap-1 text-xs font-semibold {{ $stepFailed ? 'text-rose-700' : ($stepRunning ? 'text-amber-800' : 'text-brand-moss') }} hover:underline">
                <x-heroicon-m-chevron-down class="h-3.5 w-3.5 transition-transform duration-200" x-bind:class="open && 'rotate-180'" aria-hidden="true" />
                <span x-text="open ? @js(__('Hide output')) : @js(__('Show output'))"></span>
            </button>
            <pre x-show="open" x-cloak class="mt-2 max-h-96 overflow-auto rounded-xl bg-brand-ink p-3.5 font-mono text-xs leading-relaxed {{ $stepFailed ? 'text-rose-100/95' : 'text-brand-cream/90' }}">@if ($hasOutput){{ $step['output'] }}@else<span class="inline-flex items-center gap-1.5 text-amber-300/90"><span class="inline-block h-1.5 w-1.5 animate-ping rounded-full bg-amber-300"></span>{{ __('Running… output appears when this step finishes.') }}</span>@endif</pre>
        </div>
    @endif
</li>
