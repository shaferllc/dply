{{--
    Skeleton for a Schedule sub-tab while setScheduleWorkspaceTab() round-trips,
    and the panel body of the lazy first paint. Replaces the shared
    `sites.partials._panel-skeleton`, which painted the same generic list shape
    for all four tabs — so Logs and Overview both resized on arrival.

    Every tab opens with a dense panel head, so that part is shared; only the
    body differs. Head metrics track x-workspace-panel-head dense, so the card
    doesn't resize when the real render swaps in.

    Receives: $tab (schedulers|overview|logs|activity), $rows (scheduler count).
--}}
@php
    $tab = $tab ?? 'schedulers';
    $bar = 'animate-pulse rounded bg-brand-ink/10';
    $rows = max(1, min(6, (int) ($rows ?? 4)));

    // Actions carried on the right of each tab's head, in button widths.
    $headActions = match ($tab) {
        'schedulers' => [26],
        'logs' => [20],
        default => [],
    };
@endphp

<div aria-hidden="true">
    {{-- Dense head stub: icon, title, optional count pill, divider, note, actions. --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
        <span class="h-3.5 w-36 shrink-0 {{ $bar }}"></span>
        @if (in_array($tab, ['schedulers', 'activity'], true))
            <span class="h-4 w-14 shrink-0 rounded-full {{ $bar }}"></span>
        @endif
        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        @foreach ($headActions as $w)
            <span class="h-6 shrink-0 rounded-md {{ $bar }}" style="width: {{ $w * 4 }}px;"></span>
        @endforeach
    </div>

    @if ($tab === 'overview')
        {{-- Overview: health cells, then a row per scheduler needing attention. --}}
        <div class="grid grid-cols-2 border-b border-brand-ink/10 sm:grid-cols-4">
            @foreach (range(0, 3) as $cell)
                <div class="space-y-1.5 border-brand-ink/8 px-4 py-2 sm:px-5 {{ $cell % 4 !== 0 ? 'sm:border-l' : '' }} {{ $cell % 2 !== 0 ? 'border-l' : '' }}">
                    <div class="h-2 w-16 {{ $bar }}"></div>
                    <div class="h-3 w-10 {{ $bar }}"></div>
                </div>
            @endforeach
        </div>
        <div class="space-y-1.5 px-4 py-3.5 sm:px-5">
            @foreach (range(1, 3) as $row)
                <div class="flex items-center gap-2.5 rounded-lg border border-brand-ink/8 bg-white px-3 py-2">
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-2.5 w-44 max-w-full {{ $bar }}"></div>
                        <div class="h-2 w-2/3 {{ $bar }}"></div>
                    </div>
                    <span class="h-4 w-16 shrink-0 rounded-full {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @elseif ($tab === 'logs')
        {{-- Logs: the scheduler picker row, then the tick-output pane. It keeps
             its dark surface while loading — a light block here would flash the
             card's lower half white. --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-4 py-2.5 sm:px-5">
            <span class="h-8 w-56 max-w-full rounded-lg {{ $bar }}"></span>
            <span class="ml-auto h-6 w-24 rounded-md {{ $bar }}"></span>
        </div>
        <div class="px-4 py-3.5 sm:px-5">
            <div class="space-y-2 rounded-xl bg-zinc-950 px-4 py-3">
                @foreach (range(1, 8) as $line)
                    <div class="h-2.5 animate-pulse rounded bg-zinc-100/10" style="width: {{ 38 + (($line * 19) % 55) }}%;"></div>
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
        {{-- Schedulers: a row per monitored scheduler — icon, site + command,
             health pill, cadence, and the run/pause controls. --}}
        <div class="divide-y divide-brand-ink/8">
            @foreach (range(1, $rows) as $row)
                <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                    <span class="h-8 w-8 shrink-0 rounded-lg {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-3 w-44 max-w-full {{ $bar }}"></div>
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
