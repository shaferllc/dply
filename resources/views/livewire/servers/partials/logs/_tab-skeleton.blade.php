{{--
    Skeleton for a Logs sub-tab while setLogsWorkspaceTab() round-trips.

    All six tabs previously shared `sites.partials._panel-skeleton` — one generic
    list stub. Activity in particular arrives as an audit timeline (head with a
    Feed/Trends switch, a filter row, a trend bar, then event rows), so the
    generic stub bore no relation to it and the panel resized on arrival.

    Receives: $tab (viewer|overview|sources|shipping|alerts|notifications|activity).
--}}
@php
    $tab = $tab ?? 'overview';
    $bar = 'animate-pulse rounded bg-brand-ink/10';
@endphp

<div aria-hidden="true">
    {{-- Shared dense head stub. Activity carries a segmented Feed/Trends switch
         in its actions slot; the others carry a single action at most. --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
        <span class="h-3.5 w-36 shrink-0 {{ $bar }}"></span>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        @if ($tab === 'activity')
            <span class="h-7 w-32 shrink-0 rounded-lg {{ $bar }}"></span>
        @else
            <span class="h-6 w-20 shrink-0 rounded-md {{ $bar }}"></span>
        @endif
    </div>

    @if ($tab === 'activity')
        {{-- Filter row: category chips, actor picker, range. --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-4 py-2.5 sm:px-5">
            @foreach ([16, 20, 14, 18, 16] as $chip)
                <span class="h-6 rounded-full {{ $bar }}" style="width: {{ $chip * 4 }}px;"></span>
            @endforeach
            <span class="ml-auto h-7 w-32 rounded-lg {{ $bar }}"></span>
        </div>

        {{-- Trend bars over the selected range. --}}
        <div class="border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
            <div class="flex h-16 items-end gap-1">
                @foreach (range(1, 24) as $bucket)
                    <span class="flex-1 rounded-t {{ $bar }}" style="height: {{ 15 + (($bucket * 29) % 80) }}%;"></span>
                @endforeach
            </div>
        </div>

        {{-- Event feed: actor avatar, action + subject, diff summary, timestamp. --}}
        <div class="divide-y divide-brand-ink/10">
            @foreach (range(1, 8) as $event)
                <div class="flex items-start gap-3 px-4 py-2.5 sm:px-5">
                    <span class="h-7 w-7 shrink-0 rounded-full {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="h-2.5 w-48 max-w-full {{ $bar }}"></span>
                            <span class="h-4 w-16 rounded-full {{ $bar }}"></span>
                        </div>
                        <div class="h-2 w-2/3 {{ $bar }}"></div>
                    </div>
                    <span class="h-2 w-16 shrink-0 {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @elseif ($tab === 'viewer')
        {{-- Viewer: the source picker, then the tail pane. Keeps its dark surface
             while loading — a light block would flash the panel white. --}}
        <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 px-4 py-2.5 sm:px-5">
            <span class="h-8 w-56 max-w-full rounded-lg {{ $bar }}"></span>
            <span class="h-6 w-20 rounded-md {{ $bar }}"></span>
            <span class="ml-auto h-6 w-24 rounded-md {{ $bar }}"></span>
        </div>
        <div class="space-y-2 border-t border-brand-ink/10 bg-brand-ink/95 px-4 py-3 sm:px-5">
            @foreach (range(1, 12) as $line)
                <div class="h-2.5 animate-pulse rounded bg-emerald-100/10" style="width: {{ 32 + (($line * 17) % 62) }}%;"></div>
            @endforeach
        </div>
    @elseif ($tab === 'overview')
        {{-- Overview: the stat strip, then the largest-logs table. --}}
        <div class="grid grid-cols-2 border-b border-brand-ink/10 sm:grid-cols-4">
            @foreach (range(0, 3) as $cell)
                <div @class([
                    'space-y-1.5 border-brand-ink/8 px-4 py-2 sm:px-5',
                    'border-l' => $cell % 2 !== 0,
                    'sm:border-l' => $cell % 4 !== 0,
                    'border-t' => $cell >= 2,
                    'sm:border-t-0' => true,
                ])>
                    <div class="h-2 w-20 {{ $bar }}"></div>
                    <div class="h-3 w-12 {{ $bar }}"></div>
                </div>
            @endforeach
        </div>
        <div class="divide-y divide-brand-ink/10">
            @foreach (range(1, 5) as $row)
                <div class="flex items-center gap-3 px-4 py-2.5 sm:px-5">
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-2.5 w-56 max-w-full {{ $bar }}"></div>
                        <div class="h-2 w-1/3 {{ $bar }}"></div>
                    </div>
                    <span class="h-2.5 w-16 shrink-0 {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @else
        {{-- Sources / shipping / alerts / notifications: a row per configured entry. --}}
        <div class="space-y-2 px-4 py-3.5 sm:px-5">
            @foreach (range(1, 5) as $row)
                <div class="flex items-center gap-3 rounded-lg border border-brand-ink/8 bg-white px-3 py-2">
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-2.5 w-48 max-w-full {{ $bar }}"></div>
                        <div class="h-2 w-2/3 {{ $bar }}"></div>
                    </div>
                    <span class="h-5 w-16 shrink-0 rounded-full {{ $bar }}"></span>
                    <span class="h-6 w-14 shrink-0 rounded-md {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @endif
</div>
