{{--
    Skeleton for a Caches tab while setWorkspaceTab() round-trips, and the body
    of the lazy first paint.

    The page previously painted one generic stub for every tab — an icon, two
    text lines, then three pill+text rows. Overview arrives as engine cards,
    an engine tab as a status strip + connection details + sub-panels, and
    Advanced as a settings list, so that stub matched none of them.

    Receives: $tab (overview|engine|advanced), $rows (cards/rows to stub).
--}}
@php
    $tab = $tab ?? 'overview';
    $rows = max(2, min(6, (int) ($rows ?? 4)));
    $bar = 'animate-pulse rounded bg-brand-ink/10';
@endphp

<div aria-hidden="true">
    @if ($tab === 'engine')
        {{-- Engine: the sub-tab strip, then the "<engine> status" card — a title
             line over a two-column label/value grid (Status, Probe, Version,
             Port…) — then the action row. Shaped from the real panel: it does
             NOT open with a dense head or a hairline stat strip. --}}
        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            @foreach ([92, 80, 88, 104, 76] as $i => $width)
                <span @class([
                    'inline-flex h-6 shrink-0 rounded-lg',
                    'bg-brand-ink/15' => $i === 0,
                    $bar => $i !== 0,
                ]) style="width: {{ $width }}px;"></span>
            @endforeach
        </div>

        <div class="border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
            <div class="h-3.5 w-32 {{ $bar }}"></div>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                @foreach (range(1, 6) as $fact)
                    <div class="space-y-1.5">
                        <div class="h-2 w-20 {{ $bar }}"></div>
                        <div class="h-5 w-24 rounded-full {{ $bar }}"></div>
                    </div>
                @endforeach
            </dl>
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ([64, 56, 72, 60] as $action)
                    <span class="h-7 rounded-lg {{ $bar }}" style="width: {{ $action }}px;"></span>
                @endforeach
            </div>
        </div>
    @elseif ($tab === 'advanced')
        {{-- Advanced: a settings list with per-row controls. --}}
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-32 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        </div>
        <div class="space-y-2 px-4 py-3.5 sm:px-5">
            @foreach (range(1, 3) as $row)
                <div class="flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5">
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-2.5 w-40 max-w-full {{ $bar }}"></div>
                        <div class="h-2 w-2/3 {{ $bar }}"></div>
                    </div>
                    <span class="h-7 w-24 shrink-0 rounded-lg {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @else
        {{-- Overview: one full-width, hairline-separated section per installed
             cache service — engine name + status pill on the title row, then its
             connection facts and actions. Stacked sections, not a card grid. --}}
        @foreach (range(1, $rows) as $engine)
            <div class="border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
                <div class="flex flex-wrap items-center gap-3">
                    <span class="h-3.5 w-24 shrink-0 {{ $bar }}"></span>
                    <span class="h-5 w-16 shrink-0 rounded-full {{ $bar }}"></span>
                    <span class="ml-auto h-7 w-20 shrink-0 rounded-lg {{ $bar }}"></span>
                </div>
                <dl class="mt-3 grid gap-3 sm:grid-cols-3">
                    @foreach (range(1, 3) as $fact)
                        <div class="space-y-1.5">
                            <div class="h-2 w-16 {{ $bar }}"></div>
                            <div class="h-2.5 w-28 max-w-full {{ $bar }}"></div>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endforeach
    @endif
</div>
