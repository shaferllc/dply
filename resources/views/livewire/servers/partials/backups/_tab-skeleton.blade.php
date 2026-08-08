{{--
    Skeleton for a Backups sub-tab while setBackupsWorkspaceTab() round-trips,
    and the panel body of the lazy first paint. Replaces the previous treatment,
    which just dimmed the outgoing panel to 60% opacity for the whole request —
    the old rows stayed legible under a different tab's load, which reads as
    "this is your data".

    Each tab opens with a different shape (Overview leads with the run-mode
    toggle, History with two side-by-side panels), so unlike Cron there's no
    shared head stub — every branch paints its own. Head metrics track
    x-workspace-panel-head dense, so the card doesn't resize on arrival.

    Receives: $tab (overview|schedules|history|notifications),
    $rows (row count to stub).
--}}
@php
    $tab = $tab ?? 'overview';
    $bar = 'animate-pulse rounded bg-brand-ink/10';
    $rows = max(1, min(6, (int) ($rows ?? 4)));
@endphp

<div aria-hidden="true">
    @if ($tab === 'schedules')
        {{-- Schedules: head, preset row + add-schedule form, then a row per
             recurring schedule with its cadence and the run/pause controls. --}}
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-40 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-16 shrink-0 rounded-full {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        </div>
        <div class="space-y-3 px-4 py-3.5 sm:px-5">
            <div class="flex flex-wrap items-center gap-2">
                <span class="h-2.5 w-20 {{ $bar }}"></span>
                @foreach ([22, 30, 16, 24] as $preset)
                    <span class="h-6 rounded-md {{ $bar }}" style="width: {{ $preset * 4 }}px;"></span>
                @endforeach
            </div>
            <div class="grid gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-3 sm:grid-cols-5">
                <span class="h-9 rounded-lg {{ $bar }} sm:col-span-1"></span>
                <span class="h-9 rounded-lg {{ $bar }} sm:col-span-2"></span>
                <span class="h-9 rounded-lg {{ $bar }} sm:col-span-1"></span>
                <span class="h-9 rounded-lg {{ $bar }} sm:col-span-1"></span>
                <span class="h-9 rounded-lg {{ $bar }} sm:col-span-5"></span>
            </div>
            <div class="divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10">
                @foreach (range(1, $rows) as $schedule)
                    <div class="flex items-start gap-3 bg-white px-4 py-2.5">
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="h-3 w-40 max-w-full {{ $bar }}"></span>
                                <span class="h-4 w-16 rounded-md {{ $bar }}"></span>
                            </div>
                            <div class="h-2.5 w-2/3 {{ $bar }}"></div>
                        </div>
                        @foreach ([14, 16, 12, 14] as $action)
                            <span class="hidden h-6 shrink-0 rounded-lg {{ $bar }} sm:block" style="width: {{ $action * 4 }}px;"></span>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    @elseif ($tab === 'history')
        {{-- History: two panels side by side — database runs and site-file runs,
             each a head and a list of runs with size, status, and controls. --}}
        <div class="grid lg:grid-cols-2 lg:divide-x lg:divide-brand-ink/10">
            @foreach (range(1, 2) as $panel)
                <div class="min-w-0 border-b border-brand-ink/10 lg:border-b-0">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                        <span class="h-3.5 w-40 shrink-0 {{ $bar }}"></span>
                        <span class="h-4 w-8 shrink-0 rounded-full {{ $bar }}"></span>
                        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                    </div>
                    <div class="divide-y divide-brand-ink/10">
                        @foreach (range(1, $rows) as $run)
                            <div class="flex items-center gap-3 px-4 py-2.5 sm:px-5">
                                <div class="min-w-0 flex-1 space-y-1.5">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="h-3 w-32 max-w-full {{ $bar }}"></span>
                                        <span class="h-4 w-16 rounded-md {{ $bar }}"></span>
                                    </div>
                                    <div class="h-2 w-2/3 {{ $bar }}"></div>
                                </div>
                                <span class="h-6 w-20 shrink-0 rounded-lg {{ $bar }}"></span>
                                <span class="h-6 w-16 shrink-0 rounded-lg {{ $bar }}"></span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @elseif ($tab === 'notifications')
        {{-- Notifications: head, the always-on explainer line, the routed-channel
             list, then the add-a-channel form. --}}
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-28 shrink-0 {{ $bar }}"></span>
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
            <div class="grid gap-3 pt-1 sm:grid-cols-2">
                <div class="space-y-1.5">
                    <div class="h-2.5 w-16 {{ $bar }}"></div>
                    <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
                </div>
                <div class="space-y-1.5">
                    <div class="h-2.5 w-14 {{ $bar }}"></div>
                    @foreach (range(1, 3) as $event)
                        <div class="h-2.5 w-32 max-w-full {{ $bar }}"></div>
                    @endforeach
                </div>
            </div>
        </div>
    @else
        {{-- Overview: the save/download mode toggle, the storage-settings head
             and form, then the two on-demand run panels side by side. --}}
        <div class="border-b border-brand-ink/10 px-4 py-2.5 sm:px-5">
            <span class="block h-8 w-64 max-w-full rounded-lg {{ $bar }}"></span>
        </div>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-52 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        </div>
        <div class="grid gap-3 border-b border-brand-ink/10 px-4 py-3.5 sm:grid-cols-2 sm:px-5">
            @foreach (range(1, 2) as $field)
                <div class="space-y-1.5">
                    <div class="h-2.5 w-24 {{ $bar }}"></div>
                    <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
                </div>
            @endforeach
        </div>
        <div class="grid lg:grid-cols-2 lg:divide-x lg:divide-brand-ink/10">
            @foreach (range(1, 2) as $runner)
                <div class="min-w-0 border-b border-brand-ink/10 lg:border-b-0">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                        <span class="h-3.5 w-36 shrink-0 {{ $bar }}"></span>
                        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                    </div>
                    <div class="space-y-2.5 px-4 py-3.5 sm:px-5">
                        <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
                        <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
                        <span class="block h-9 w-48 max-w-full rounded-xl {{ $bar }}"></span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
