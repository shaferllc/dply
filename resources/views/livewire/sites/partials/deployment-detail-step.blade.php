@php
    // Steps come in two shapes: VM deploy steps carry {command, output,
    // step_type, skipped}; serverless steps carry {key, label, detail,
    // state}. Normalise both so one partial renders the whole pipeline.
    $stepLabel = trim((string) ($step['label'] ?? $step['command'] ?? $step['key'] ?? ''));
    $stepKind = (string) ($step['step_type'] ?? $step['key'] ?? 'step');
    $stepCommand = trim((string) ($step['command'] ?? ''));
    $stepOutput = trim((string) ($step['output'] ?? $step['detail'] ?? ''));
    $stepDuration = (int) ($step['duration_ms'] ?? 0);
    $stepState = (string) ($step['state'] ?? '');
    $stepOk = ($step['ok'] ?? false) === true;
    $stepSkipped = ($step['skipped'] ?? false) === true || $stepState === 'skipped';
    $shouldRenderOutput = $stepOutput !== '' && ($showOutput || (! $stepOk && ! $stepSkipped));
    // Avoid printing the command twice when it already serves as the label.
    $showCommandLine = $stepCommand !== '' && $stepCommand !== $stepLabel;
@endphp
<li class="flex gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
    <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold {{ $glyphClasses }}">{{ $glyph }}</span>
    <div class="min-w-0 flex-1">
        <p class="flex flex-wrap items-baseline gap-x-2 text-xs">
            <span class="font-semibold uppercase tracking-[0.12em] text-slate-500">{{ str_replace(['_', '-'], ' ', $stepKind) }}</span>
            @if ($stepSkipped)
                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-[0.1em] text-amber-900">{{ __('skipped') }}</span>
            @endif
            @if ($stepDuration > 0)
                <span class="font-mono text-slate-400">{{ $stepDuration >= 1000 ? number_format($stepDuration / 1000, 1).'s' : $stepDuration.'ms' }}</span>
            @endif
        </p>
        @if ($stepLabel !== '')
            <p class="mt-1 break-words text-sm text-slate-800">{{ $stepLabel }}</p>
        @endif
        @if ($showCommandLine)
            <p class="mt-1 break-all font-mono text-xs text-slate-600">{{ $stepCommand }}</p>
        @endif
        @if ($stepOutput !== '')
            <div x-data="{ open: @js($shouldRenderOutput) }" class="mt-2">
                <button type="button" x-on:click="open = ! open"
                    class="inline-flex items-center gap-1 text-[11px] font-semibold text-slate-600 hover:text-slate-900">
                    <span class="font-mono" x-text="open ? '▾' : '▸'"></span>
                    <span x-text="open ? @js(__('Hide output')) : @js(__('Show output'))"></span>
                </button>
                <pre x-show="open" x-cloak class="mt-1 max-h-96 overflow-auto rounded bg-slate-900 p-3 font-mono text-[11px] text-slate-100">{{ $stepOutput }}</pre>
            </div>
        @endif
    </div>
</li>
