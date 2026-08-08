{{--
    Skeleton for a Workers sub-tab while setDaemonsWorkspaceTab() round-trips,
    and the panel body of the lazy first paint. Replaces the previous treatment,
    which just dimmed the outgoing panel to 60% opacity for the whole request.

    Every tab opens with a dense panel head, so that part is shared; only the
    body differs. Head metrics track x-workspace-panel-head dense, so the card
    doesn't resize when the real render swaps in.

    Receives: $tab (programs|service|sync|logs|inspect|activity).
--}}
@php
    $tab = $tab ?? 'programs';
    $bar = 'animate-pulse rounded bg-brand-ink/10';

    // Actions carried on the right of each tab's head, in button widths.
    $headActions = match ($tab) {
        'programs' => [18, 22, 22, 14, 26],
        'service' => [20, 20, 20],
        'sync' => [16, 22],
        'logs' => [18, 20],
        'activity' => [18],
        default => [],
    };
@endphp

<div aria-hidden="true">
    {{-- Dense head stub: icon, title, optional count pill, divider, note, actions. --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
        <span class="h-3.5 w-40 shrink-0 {{ $bar }}"></span>
        @if (in_array($tab, ['programs', 'logs', 'activity'], true))
            <span class="h-4 w-12 shrink-0 rounded-full {{ $bar }}"></span>
        @endif
        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        @foreach ($headActions as $w)
            <span class="h-6 shrink-0 rounded-md {{ $bar }}" style="width: {{ $w * 4 }}px;"></span>
        @endforeach
    </div>

    @if ($tab === 'service')
        {{-- Service: supervisor state tiles, then the start/stop/restart controls. --}}
        <div class="grid grid-cols-2 border-b border-brand-ink/10 sm:grid-cols-4">
            @foreach (range(1, 4) as $cell)
                <div class="space-y-1.5 border-brand-ink/8 px-4 py-2 sm:px-5 {{ $cell > 1 ? 'border-l' : '' }}">
                    <div class="h-2 w-16 {{ $bar }}"></div>
                    <div class="h-3 w-12 {{ $bar }}"></div>
                </div>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2 px-4 py-3 sm:px-5">
            @foreach ([24, 22, 26, 20] as $w)
                <span class="h-8 rounded-lg {{ $bar }}" style="width: {{ $w * 4 }}px;"></span>
            @endforeach
        </div>
    @elseif ($tab === 'sync')
        {{-- Sync: the drift explainer strip, then config diff blocks. --}}
        <div class="flex items-start gap-2.5 border-b border-brand-ink/10 bg-brand-sand/15 px-4 py-3 sm:px-5">
            <span class="mt-0.5 h-4 w-4 shrink-0 {{ $bar }}"></span>
            <div class="min-w-0 flex-1 space-y-1.5">
                <div class="h-2.5 w-full {{ $bar }}"></div>
                <div class="h-2.5 w-2/3 {{ $bar }}"></div>
            </div>
        </div>
        <div class="space-y-3 px-4 py-3.5 sm:px-5">
            @foreach (range(1, 2) as $block)
                <div class="overflow-hidden rounded-lg border border-brand-ink/10 bg-white">
                    <div class="flex items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2">
                        <span class="h-3.5 w-3.5 shrink-0 rounded-full {{ $bar }}"></span>
                        <span class="h-2.5 w-32 shrink-0 {{ $bar }}"></span>
                    </div>
                    <div class="space-y-1.5 px-3 py-2.5">
                        @foreach (range(1, 3) as $line)
                            <div class="h-2 {{ $bar }}" style="width: {{ [90, 70, 55][$line - 1] }}%;"></div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @elseif ($tab === 'logs')
        {{-- Logs: the program picker row, then the tail pane. --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-4 py-2.5 sm:px-5">
            <span class="h-8 w-52 max-w-full rounded-lg {{ $bar }}"></span>
            <span class="h-8 w-20 rounded-lg {{ $bar }}"></span>
            <span class="ml-auto h-6 w-28 rounded-md {{ $bar }}"></span>
        </div>
        <div class="px-4 py-3.5 sm:px-5">
            <div class="space-y-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-3">
                @foreach (range(1, 10) as $line)
                    <div class="h-2 {{ $bar }}" style="width: {{ 40 + (($line * 17) % 55) }}%;"></div>
                @endforeach
            </div>
        </div>
    @elseif ($tab === 'inspect')
        {{-- Inspect: a labelled command field and the output pane it fills. --}}
        <div class="space-y-2 px-4 py-3.5 sm:px-5">
            <div class="h-2.5 w-32 {{ $bar }}"></div>
            <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
            <div class="h-2.5 w-2/3 {{ $bar }}"></div>
            <div class="mt-3 space-y-1.5 rounded-lg border border-brand-ink/10 bg-white px-3 py-3">
                @foreach (range(1, 6) as $line)
                    <div class="h-2 {{ $bar }}" style="width: {{ 35 + (($line * 23) % 60) }}%;"></div>
                @endforeach
            </div>
        </div>
    @elseif ($tab === 'activity')
        {{-- Activity: audit rows — dot, two text lines, timestamp. --}}
        <div class="space-y-1.5 px-4 py-3.5 sm:px-5">
            @foreach (range(1, 6) as $event)
                <div class="flex items-center gap-2.5 rounded-lg border border-brand-ink/8 bg-white px-3 py-2">
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-2.5 w-48 max-w-full {{ $bar }}"></div>
                        <div class="h-2 w-2/3 {{ $bar }}"></div>
                    </div>
                    <span class="h-2 w-16 shrink-0 {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @else
        {{-- Programs: the supervisor program rows — icon, name + command, state
             pill, and the per-row action buttons. --}}
        <div class="divide-y divide-brand-ink/8">
            @foreach (range(1, $rows ?? 4) as $row)
                <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                    <span class="h-8 w-8 shrink-0 rounded-lg {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-3 w-40 max-w-full {{ $bar }}"></div>
                        <div class="h-2.5 w-64 max-w-full {{ $bar }}"></div>
                    </div>
                    <span class="h-4 w-16 shrink-0 rounded-full {{ $bar }}"></span>
                    <span class="h-6 w-14 shrink-0 rounded-md {{ $bar }}"></span>
                    <span class="h-6 w-14 shrink-0 rounded-md {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @endif
</div>
