@php
    $stepOk = ($step['ok'] ?? false) === true;
    $stepSkipped = ($step['skipped'] ?? false) === true;
    $stepCommand = (string) ($step['command'] ?? '<no command>');
    $stepOutput = trim((string) ($step['output'] ?? ''));
    $stepDuration = (int) ($step['duration_ms'] ?? 0);
    $glyphClasses = $stepSkipped
        ? 'bg-amber-100 text-amber-900'
        : ($stepOk ? 'bg-emerald-100 text-emerald-900' : 'bg-rose-100 text-rose-900');
    $glyph = $stepSkipped ? '·' : ($stepOk ? '✓' : '✗');
@endphp
<li class="flex items-start gap-2 text-xs">
    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full {{ $glyphClasses }} text-[9px] font-bold">{{ $glyph }}</span>
    <div class="min-w-0 flex-1">
        <p class="break-all font-mono text-[11px] text-slate-800">{{ $stepCommand }}</p>
        <p class="font-mono text-[10px] text-slate-500">{{ $stepDuration }}ms</p>
        @if (! $stepOk && ! $stepSkipped && $stepOutput !== '')
            <pre class="mt-1 max-h-32 overflow-auto rounded bg-rose-50 p-2 font-mono text-[10px] text-rose-900">{{ $stepOutput }}</pre>
        @endif
    </div>
</li>
