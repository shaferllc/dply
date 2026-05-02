@php
    $stepCommand = (string) ($step['command'] ?? '<no command>');
    $stepOutput = trim((string) ($step['output'] ?? ''));
    $stepDuration = (int) ($step['duration_ms'] ?? 0);
    $stepType = (string) ($step['step_type'] ?? 'step');
    $stepOk = ($step['ok'] ?? false) === true;
    $stepSkipped = ($step['skipped'] ?? false) === true;
    $shouldRenderOutput = $stepOutput !== '' && ($showOutput || (! $stepOk && ! $stepSkipped));
@endphp
<li class="flex gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
    <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[10px] font-bold {{ $glyphClasses }}">{{ $glyph }}</span>
    <div class="min-w-0 flex-1">
        <p class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{{ $stepType }} <span class="ml-2 font-mono text-slate-400">{{ $stepDuration }}ms</span></p>
        <p class="mt-1 break-all font-mono text-xs text-slate-800">{{ $stepCommand }}</p>
        @if ($shouldRenderOutput)
            <pre class="mt-2 max-h-64 overflow-auto rounded bg-slate-900 p-3 font-mono text-[11px] text-slate-100">{{ $stepOutput }}</pre>
        @endif
    </div>
</li>
