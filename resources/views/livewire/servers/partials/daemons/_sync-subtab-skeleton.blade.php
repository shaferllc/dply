{{--
    Skeleton for a Sync sub-tab while setDaemonsSyncSubtab() round-trips.

    All three sub-tabs are the same shape — dense head, an optional load button,
    then the terminal-style output pane — so only the button and the pane's
    height differ. Metrics track the real panels so the card doesn't resize when
    the render swaps in.

    Receives: $sub (preview|drift|output).
--}}
@php
    $sub = $sub ?? 'preview';
    $bar = 'animate-pulse rounded bg-brand-ink/10';
    // Only Preview and Drift carry a load button; Last output is read-only.
    $hasButton = $sub !== 'output';
@endphp

<div aria-hidden="true">
    {{-- Dense head stub: icon, title, divider, note. --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
        <span class="h-3.5 w-28 shrink-0 {{ $bar }}"></span>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
    </div>

    <div class="space-y-3 px-4 py-3.5 sm:px-5">
        @if ($hasButton)
            <span class="block h-7 w-28 rounded-md {{ $bar }}"></span>
        @endif
        {{-- The output pane keeps its dark surface while loading — a light grey
             block here would flash the card's whole lower half white. --}}
        <div class="space-y-2 rounded-xl bg-zinc-950 px-4 py-3">
            @foreach (range(1, $hasButton ? 3 : 6) as $line)
                <div class="h-2.5 animate-pulse rounded bg-zinc-100/10" style="width: {{ [62, 41, 78, 35, 55, 47][$line - 1] }}%;"></div>
            @endforeach
        </div>
    </div>
</div>
