{{--
    Skeleton for a Services tab while setServicesWorkspaceTab() round-trips, and
    the body of the lazy first paint.

    The page previously painted ONE generic stub for both tabs — an icon, two
    text lines, then four pill+text rows — which matched neither. Inventory
    arrives as managed-service tiles plus a bulk-select unit table; Activity
    arrives as an event feed. Switching to Activity stubbed something that never
    showed up, so the panel resized on arrival.

    Receives: $tab (inventory|activity), $rows (rows to stub, default 6).
--}}
@php
    $tab = $tab ?? 'inventory';
    $rows = max(3, min(8, (int) ($rows ?? 6)));
    $bar = 'animate-pulse rounded bg-brand-ink/10';
@endphp

<div aria-hidden="true">
    @if ($tab === 'activity')
        {{-- Activity: a head carrying the event count, then the event feed —
             each row a status ring, a unit name, the change, and a timestamp. --}}
        <div class="border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
            <div class="flex items-start gap-3">
                <span class="h-9 w-9 shrink-0 rounded-xl {{ $bar }}"></span>
                <div class="min-w-0 flex-1 space-y-1.5">
                    <div class="h-3 w-32 {{ $bar }}"></div>
                    <div class="h-2.5 w-72 max-w-full {{ $bar }}"></div>
                    <div class="h-2 w-40 {{ $bar }}"></div>
                </div>
            </div>

            <div class="mt-4 divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10">
                @foreach (range(1, $rows) as $event)
                    <div class="flex items-start gap-3 bg-white px-3 py-2.5">
                        <span class="h-7 w-7 shrink-0 rounded-full {{ $bar }}"></span>
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="h-2.5 w-40 max-w-full {{ $bar }}"></span>
                                <span class="h-4 w-16 rounded-full {{ $bar }}"></span>
                            </div>
                            <div class="h-2 w-2/3 {{ $bar }}"></div>
                        </div>
                        <span class="h-2 w-16 shrink-0 {{ $bar }}"></span>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        {{-- Inventory: the managed-service tiles, then the System services card —
             head with its two actions, then a row per systemd unit (checkbox,
             name + description, state pill, actions). --}}
        <div class="border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="h-3 w-36 {{ $bar }}"></span>
                <span class="h-2.5 w-40 {{ $bar }}"></span>
            </div>
            <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (range(1, 6) as $tile)
                    <div class="flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-3 py-3">
                        <span class="h-8 w-8 shrink-0 rounded-lg {{ $bar }}"></span>
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="h-2.5 w-24 max-w-full {{ $bar }}"></div>
                            <div class="h-2 w-32 max-w-full {{ $bar }}"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div class="min-w-0 space-y-1.5">
                <div class="h-3 w-32 {{ $bar }}"></div>
                <div class="h-2 w-44 {{ $bar }}"></div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="h-7 w-32 rounded-lg {{ $bar }}"></span>
                <span class="h-7 w-24 rounded-lg {{ $bar }}"></span>
            </div>
        </div>

        <div class="divide-y divide-brand-ink/10">
            @foreach (range(1, $rows) as $unit)
                <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
                    <span class="h-4 w-4 shrink-0 rounded {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-3 w-44 max-w-full {{ $bar }}"></div>
                        <div class="h-2 w-1/2 max-w-sm {{ $bar }}"></div>
                    </div>
                    <span class="h-5 w-16 shrink-0 rounded-full {{ $bar }}"></span>
                    <span class="hidden h-6 w-16 shrink-0 rounded-md {{ $bar }} sm:block"></span>
                </div>
            @endforeach
        </div>
    @endif
</div>
