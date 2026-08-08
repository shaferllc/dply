{{--
    Skeleton for a Snapshots sub-tab while setSnapshotsTab() round-trips, and the
    panel body of the lazy first paint. Replaces the previous treatment, which
    dimmed the outgoing panel to 60% opacity for the whole request — the old
    tab's rows stayed legible under a different tab's load, which reads as
    "this is your data".

    Every tab opens with a dense panel head, so that part is shared; only the
    body differs. Head metrics track x-workspace-panel-head dense, so the card
    doesn't resize when the real render swaps in.

    Receives: $tab (images|cache|databases|volumes|notifications),
    $rows (history rows to stub).
--}}
@php
    $tab = $tab ?? 'images';
    $bar = 'animate-pulse rounded bg-brand-ink/10';
    $rows = max(1, min(6, (int) ($rows ?? 4)));

    // Columns in each tab's history table — the widths below drive the stub.
    $columns = match ($tab) {
        'cache' => [18, 14, 16, 12, 20, 8],
        'databases' => [16, 18, 14, 16, 10, 12, 10, 14],
        default => [16, 22, 16, 10, 12, 12, 8],
    };
@endphp

<div aria-hidden="true">
    @if ($tab === 'notifications')
        {{-- Notifications: head with the Settings escape hatch, the always-on
             explainer line, the routed-channel list, then the add form. --}}
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-32 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-20 shrink-0 rounded-full {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
            <span class="h-6 w-32 shrink-0 rounded-lg {{ $bar }}"></span>
        </div>
        <div class="flex items-start gap-2.5 border-b border-brand-ink/10 bg-brand-sand/15 px-4 py-2.5 sm:px-5">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <div class="min-w-0 flex-1 space-y-1.5">
                <div class="h-2.5 w-full {{ $bar }}"></div>
                <div class="h-2.5 w-3/4 {{ $bar }}"></div>
            </div>
        </div>
        <div class="space-y-2 px-4 py-3.5 sm:px-5">
            @foreach (range(1, 2) as $channel)
                <div class="flex items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-2.5">
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-2.5 w-36 max-w-full {{ $bar }}"></div>
                        <div class="h-2 w-16 {{ $bar }}"></div>
                    </div>
                    <span class="h-5 w-24 shrink-0 rounded-full {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @elseif ($tab === 'volumes')
        {{-- Volumes: one head and the coming-soon panel. --}}
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-36 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        </div>
        <div class="px-4 py-3.5 sm:px-5">
            <div class="space-y-2 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-8 text-center">
                <span class="mx-auto block h-9 w-9 rounded-xl {{ $bar }}"></span>
                <span class="mx-auto block h-3 w-28 {{ $bar }}"></span>
                <span class="mx-auto block h-2.5 w-64 max-w-full {{ $bar }}"></span>
            </div>
        </div>
    @else
        {{-- Capture form: head, one field row, one submit. Cache adds a second
             section for schedules before its history table. --}}
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-44 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        </div>
        <div class="border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1 space-y-1.5">
                    <div class="h-2.5 w-20 {{ $bar }}"></div>
                    <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
                </div>
                <span class="h-9 w-32 shrink-0 rounded-xl {{ $bar }}"></span>
            </div>
        </div>

        @if ($tab === 'cache')
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                <span class="h-3.5 w-40 shrink-0 {{ $bar }}"></span>
                <span class="h-4 w-8 shrink-0 rounded-full {{ $bar }}"></span>
                <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
            </div>
            <div class="border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
                <div class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10">
                    @foreach (range(1, 2) as $schedule)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                            <div class="min-w-0 flex-1 space-y-1.5">
                                <div class="h-3 w-48 max-w-full {{ $bar }}"></div>
                                <div class="h-2 w-2/3 {{ $bar }}"></div>
                            </div>
                            <span class="h-7 w-20 shrink-0 rounded-lg {{ $bar }}"></span>
                            <span class="h-7 w-20 shrink-0 rounded-lg {{ $bar }}"></span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- History: a head, then the table — a header row and $rows body rows,
             stubbed at the real cell padding so the swap doesn't jump. --}}
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-40 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-8 shrink-0 rounded-full {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        </div>
        <div class="px-4 py-3.5 sm:px-5">
            <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                <div class="flex items-center gap-3 bg-brand-sand/40 px-3 py-2">
                    @foreach ($columns as $width)
                        <span class="h-2 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                    @endforeach
                </div>
                <div class="divide-y divide-brand-ink/10 bg-white">
                    @foreach (range(1, $rows) as $row)
                        <div class="flex items-center gap-3 px-3 py-2">
                            @foreach ($columns as $width)
                                <span class="h-2.5 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
