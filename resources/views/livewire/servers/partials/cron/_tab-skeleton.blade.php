{{--
    Skeleton for a Cron sub-tab while setCronWorkspaceTab() round-trips, and the
    panel body of the lazy first paint. Replaces the previous treatment, which
    just dimmed the outgoing panel to 60% opacity for the whole request.

    Every tab opens with a dense panel head, so that part is shared; only the
    body differs. Head metrics track x-workspace-panel-head dense, so the card
    doesn't resize when the real render swaps in.

    Receives: $tab (jobs|history|inspect|templates|maintenance),
    $rows (job/entry count to stub).
--}}
@php
    $tab = $tab ?? 'jobs';
    $bar = 'animate-pulse rounded bg-brand-ink/10';
    $rows = max(1, min(6, (int) ($rows ?? 4)));

    // Actions carried on the right of each tab's head, in button widths.
    $headActions = match ($tab) {
        'jobs' => [24, 26],
        'templates' => [26],
        'inspect' => [22],
        default => [],
    };
@endphp

<div aria-hidden="true">
    {{-- Dense head stub: icon, title, optional count pill, divider, note, actions. --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
        <span class="h-3.5 w-32 shrink-0 {{ $bar }}"></span>
        @if (in_array($tab, ['jobs', 'history', 'templates'], true))
            <span class="h-4 w-14 shrink-0 rounded-full {{ $bar }}"></span>
        @endif
        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        @foreach ($headActions as $w)
            <span class="h-6 shrink-0 rounded-md {{ $bar }}" style="width: {{ $w * 4 }}px;"></span>
        @endforeach
    </div>

    @if ($tab === 'history')
        {{-- History: run rows — status dot, command, timestamp, duration. --}}
        <div class="space-y-1.5 px-4 py-3.5 sm:px-5">
            @foreach (range(1, 6) as $run)
                <div class="flex items-center gap-2.5 rounded-lg border border-brand-ink/8 bg-white px-3 py-2">
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-2.5 w-52 max-w-full {{ $bar }}"></div>
                        <div class="h-2 w-2/3 {{ $bar }}"></div>
                    </div>
                    <span class="h-2 w-12 shrink-0 {{ $bar }}"></span>
                    <span class="h-2 w-16 shrink-0 {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @elseif ($tab === 'inspect')
        {{-- Inspect: the read-crontab button, then the terminal-style pane. It
             keeps its dark surface while loading — a light block here would
             flash the card's lower half white. --}}
        <div class="space-y-3 px-4 py-3.5 sm:px-5">
            <span class="block h-7 w-32 rounded-md {{ $bar }}"></span>
            <div class="space-y-2 rounded-xl bg-zinc-950 px-4 py-3">
                @foreach (range(1, 6) as $line)
                    <div class="h-2.5 animate-pulse rounded bg-zinc-100/10" style="width: {{ [64, 43, 77, 36, 58, 49][$line - 1] }}%;"></div>
                @endforeach
            </div>
        </div>
    @elseif ($tab === 'templates')
        {{-- Templates: preset cards, each a title, a mono command, and Use. --}}
        <div class="grid gap-2 px-4 py-3.5 sm:grid-cols-2 sm:px-5">
            @foreach (range(1, 4) as $tpl)
                <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5">
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-2.5 w-32 max-w-full {{ $bar }}"></div>
                        <div class="h-2 w-full {{ $bar }}"></div>
                    </div>
                    <span class="h-6 w-12 shrink-0 rounded-md {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @elseif ($tab === 'maintenance')
        {{-- Cron pause: a short explainer, a datetime field, a note, and save. --}}
        <div class="max-w-xl space-y-3 px-4 py-3.5 sm:px-5">
            <div class="h-2.5 w-full {{ $bar }}"></div>
            <div class="h-2.5 w-3/4 {{ $bar }}"></div>
            <div class="space-y-1.5">
                <div class="h-2.5 w-28 {{ $bar }}"></div>
                <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
            </div>
            <div class="space-y-1.5">
                <div class="h-2.5 w-20 {{ $bar }}"></div>
                <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
            </div>
            <span class="block h-8 w-32 rounded-lg {{ $bar }}"></span>
        </div>
    @else
        {{-- Jobs: the filter field, then a row per managed crontab line —
             description, schedule + user + badges, and the mono command. --}}
        <div class="border-b border-brand-ink/10 px-4 py-2.5 sm:px-5">
            <div class="h-8 w-full rounded-lg {{ $bar }}"></div>
        </div>
        <div class="divide-y divide-brand-ink/8">
            @foreach (range(1, $rows) as $job)
                <div class="flex items-start gap-3 px-4 py-3 sm:px-5">
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="h-3 w-48 max-w-full {{ $bar }}"></span>
                            <span class="h-4 w-16 rounded-full {{ $bar }}"></span>
                            <span class="h-4 w-20 rounded-full {{ $bar }}"></span>
                        </div>
                        <div class="h-2.5 w-2/3 {{ $bar }}"></div>
                    </div>
                    <span class="h-5 w-5 shrink-0 rounded {{ $bar }}"></span>
                    <span class="h-5 w-5 shrink-0 rounded {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @endif
</div>
