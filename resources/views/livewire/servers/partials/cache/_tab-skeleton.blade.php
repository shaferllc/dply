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
        {{-- Engine: sub-tab strip, the status stat strip, then the connection
             details card and its per-engine panels. --}}
        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            @foreach ([46, 40, 44, 52, 38] as $i => $width)
                <span @class([
                    'inline-flex h-6 shrink-0 rounded-lg',
                    'bg-brand-ink/15' => $i === 0,
                    $bar => $i !== 0,
                ]) style="width: {{ $width * 2 }}px;"></span>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-24 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-16 shrink-0 rounded-full {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
            <span class="h-6 w-20 shrink-0 rounded-md {{ $bar }}"></span>
        </div>

        <div class="grid grid-cols-2 border-b border-brand-ink/10 sm:grid-cols-4">
            @foreach (range(0, 3) as $cell)
                <div @class([
                    'space-y-1.5 border-brand-ink/8 px-4 py-2 sm:px-5',
                    'border-l' => $cell % 2 !== 0,
                    'sm:border-l' => $cell % 4 !== 0,
                    'border-t' => $cell >= 2,
                    'sm:border-t-0' => true,
                ])>
                    <div class="h-2 w-16 {{ $bar }}"></div>
                    <div class="h-3 w-12 {{ $bar }}"></div>
                </div>
            @endforeach
        </div>

        <div class="space-y-2 px-4 py-3.5 sm:px-5">
            @foreach (range(1, 4) as $line)
                <div class="flex items-center gap-3 rounded-lg border border-brand-ink/8 bg-white px-3 py-2">
                    <span class="h-2 w-24 shrink-0 {{ $bar }}"></span>
                    <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                    <span class="h-6 w-14 shrink-0 rounded-md {{ $bar }}"></span>
                </div>
            @endforeach
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
        {{-- Overview: a card per engine — icon, name, status pill, and either an
             install button or its connection summary. --}}
        <div class="grid gap-2 px-4 py-3.5 sm:grid-cols-2 sm:px-5">
            @foreach (range(1, $rows) as $engine)
                <div class="space-y-2.5 rounded-xl border border-brand-ink/10 bg-white p-3">
                    <div class="flex items-center gap-2">
                        <span class="h-8 w-8 shrink-0 rounded-lg {{ $bar }}"></span>
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="h-2.5 w-24 {{ $bar }}"></div>
                            <div class="h-2 w-32 max-w-full {{ $bar }}"></div>
                        </div>
                        <span class="h-5 w-16 shrink-0 rounded-full {{ $bar }}"></span>
                    </div>
                    <div class="h-7 w-full rounded-lg {{ $bar }}"></div>
                </div>
            @endforeach
        </div>
    @endif
</div>
