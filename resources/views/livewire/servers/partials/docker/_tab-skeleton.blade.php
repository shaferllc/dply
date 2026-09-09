{{--
    Skeleton for a Docker tab while setWorkspaceTab() round-trips.

    This page had no tab-switch treatment at all — not even the old dim-and-lock:
    clicking a tab left the previous panel fully rendered until the response
    landed, so the page looked unresponsive and then jumped.

    One wrapper per tab, each targeting the call WITH its argument — Livewire
    matches wire:target params, so only the tab being opened paints.
    $workspace_tab still holds the OUTGOING tab during the request, so it can't
    shape a single shared skeleton.

    Receives: $tab (overview|containers|images|volumes|networks|compose|maintenance).
--}}
@php
    $tab = $tab ?? 'overview';
    $bar = 'animate-pulse rounded bg-brand-ink/10';

    // Column widths (4px units) per list tab, so each table stub matches the
    // real one rather than every tab sharing a generic grid.
    $columns = match ($tab) {
        'containers' => [22, 20, 16, 14, 18],
        'images' => [24, 14, 16, 12, 16],
        'volumes' => [24, 18, 20, 14],
        'networks' => [24, 16, 16, 14],
        'compose' => [26, 16, 20, 14],
        'maintenance' => [20, 12, 12, 18, 18],
        default => [22, 18, 16, 14],
    };
@endphp

<div aria-hidden="true">
    {{-- Every tab opens with the shared dense head. --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
        <span class="h-3.5 w-28 shrink-0 {{ $bar }}"></span>
        @if ($tab !== 'overview' && $tab !== 'maintenance')
            <span class="h-4 w-10 shrink-0 rounded-full {{ $bar }}"></span>
        @endif
        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        @if ($tab === 'images')
            <span class="h-6 w-32 shrink-0 rounded-md {{ $bar }}"></span>
        @endif
        <span class="h-6 w-20 shrink-0 rounded-md {{ $bar }}"></span>
    </div>

    @if ($tab === 'overview')
        {{-- Overview: engine head is above, then the four-cell stat strip, the
             install note, and the six capability tiles. --}}
        <div class="grid grid-cols-2 border-b border-brand-ink/10 sm:grid-cols-4">
            @foreach (range(0, 3) as $cell)
                <div @class([
                    'space-y-1.5 border-brand-ink/8 px-4 py-2 sm:px-5',
                    'border-l' => $cell % 2 !== 0,
                    'sm:border-l' => $cell % 4 !== 0,
                    'sm:border-l-0' => $cell % 4 === 0,
                    'border-t' => $cell >= 2,
                    'sm:border-t-0' => true,
                ])>
                    <div class="h-2 w-20 {{ $bar }}"></div>
                    <div class="h-3 w-12 {{ $bar }}"></div>
                </div>
            @endforeach
        </div>
        <div class="grid gap-2 px-4 py-3.5 sm:grid-cols-2 sm:px-5 lg:grid-cols-3">
            @foreach (range(1, 6) as $tile)
                <div class="space-y-1.5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3">
                    <div class="h-3 w-24 {{ $bar }}"></div>
                    <div class="h-2.5 w-40 max-w-full {{ $bar }}"></div>
                </div>
            @endforeach
        </div>
    @else
        @if ($tab === 'images')
            {{-- Images also carries the pull row under its head. --}}
            <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-white px-4 py-2.5 sm:px-5">
                <span class="h-2.5 w-16 shrink-0 {{ $bar }}"></span>
                <span class="h-8 min-w-[12rem] flex-1 rounded-lg {{ $bar }}"></span>
                <span class="h-8 w-16 shrink-0 rounded-lg {{ $bar }}"></span>
            </div>
        @endif

        <div class="px-4 py-3.5 sm:px-5">
            <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                <div class="flex items-center gap-3 bg-brand-sand/30 px-3 py-2">
                    @foreach ($columns as $width)
                        <span class="h-2 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                    @endforeach
                </div>
                <div class="divide-y divide-brand-ink/10 bg-white">
                    @foreach (range(1, 4) as $row)
                        <div class="flex items-center gap-3 px-3 py-2">
                            @foreach ($columns as $width)
                                <span class="h-2.5 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        @if ($tab === 'maintenance')
            {{-- Maintenance's second panel: the amber prune head + button row. --}}
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-y border-brand-ink/10 bg-amber-50/60 px-3 py-2 sm:px-4">
                <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                <span class="h-3.5 w-32 shrink-0 {{ $bar }}"></span>
                <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
            </div>
            <div class="flex flex-wrap gap-2 px-4 py-3.5 sm:px-5">
                @foreach ([28, 28, 30] as $width)
                    <span class="h-7 rounded-lg {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                @endforeach
            </div>
        @endif
    @endif
</div>
