{{--
    Skeleton for a Webserver sub-tab while setWorkspaceTab() round-trips, and the
    panel body of the lazy first paint. Replaces x-workspace-tab-panel-loading on
    this page, which dimmed the outgoing panel to 60% and floated a "Loading…"
    card over it — the old tab's content stayed legible under a different tab's
    load, which reads as "this is your data".

    Every tab is one or more cards inside the merged Webserver card, so each
    branch paints the shape that tab actually arrives with. Metrics track
    x-workspace-panel-head dense, so the card doesn't resize on the swap.

    Receives: $tab (overview|change|health|engine|advanced|notifications).
--}}
@php
    $tab = $tab ?? 'overview';
    $bar = 'animate-pulse rounded bg-brand-ink/10';
@endphp

<div aria-hidden="true">
    @if ($tab === 'change')
        {{-- Change: the explainer, then a card per switchable engine. --}}
        <div class="space-y-2 border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
            <div class="h-3.5 w-40 {{ $bar }}"></div>
            <div class="h-2.5 w-full max-w-2xl {{ $bar }}"></div>
            <div class="grid gap-2 pt-1.5 sm:grid-cols-2">
                @foreach (range(1, 4) as $engine)
                    <div class="space-y-2.5 rounded-xl border border-brand-ink/10 bg-white p-3">
                        <div class="flex items-center gap-2">
                            <span class="h-5 w-5 shrink-0 {{ $bar }}"></span>
                            <span class="h-3 w-24 {{ $bar }}"></span>
                        </div>
                        <div class="h-8 w-full rounded-lg {{ $bar }}"></div>
                    </div>
                @endforeach
            </div>
        </div>
    @elseif ($tab === 'health')
        {{-- Health: three stacked probe cards — live certs, smoke test, drift.
             Each is a head with an action and a table body. --}}
        @foreach (range(1, 3) as $probe)
            <div class="border-b border-brand-ink/10">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 bg-brand-sand/20 px-3 py-2 sm:px-4">
                    <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                    <span class="h-3.5 w-44 shrink-0 {{ $bar }}"></span>
                    <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                    <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                    <span class="h-6 w-28 shrink-0 rounded-md {{ $bar }}"></span>
                </div>
                <div class="divide-y divide-brand-ink/5 border-t border-brand-ink/10">
                    @foreach (range(1, 3) as $row)
                        <div class="flex items-center gap-3 px-4 py-2 sm:px-5">
                            <span class="h-2.5 w-32 shrink-0 {{ $bar }}"></span>
                            <span class="h-2 min-w-0 flex-1 {{ $bar }}"></span>
                            <span class="h-4 w-14 shrink-0 rounded-full {{ $bar }}"></span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @elseif ($tab === 'engine')
        {{-- Engine: the sub-tab strip, then the sub-tab body. Which sub-tab
             opens is engine state we don't have here, so this paints the
             common shape — a panel head and a config/live-state body. --}}
        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            @foreach (range(0, 5) as $subtab)
                <span @class([
                    'inline-flex h-6 shrink-0 rounded-lg',
                    'bg-brand-ink/15' => $subtab === 0,
                    $bar => $subtab !== 0,
                ]) style="width: {{ [56, 44, 48, 52, 60, 44][$subtab] }}px;"></span>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-36 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-14 shrink-0 rounded-full {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
            <span class="h-6 w-20 shrink-0 rounded-md {{ $bar }}"></span>
        </div>
        <div class="space-y-2 border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
            @foreach (range(1, 4) as $row)
                <div class="flex items-center gap-3 rounded-lg border border-brand-ink/8 bg-white px-3 py-2">
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-2.5 w-48 max-w-full {{ $bar }}"></div>
                        <div class="h-2 w-2/3 {{ $bar }}"></div>
                    </div>
                    <span class="h-5 w-16 shrink-0 rounded-full {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @elseif ($tab === 'advanced')
        {{-- Advanced: PHP-FPM table, certbot table, switch history list. --}}
        @foreach (range(1, 2) as $table)
            <div class="space-y-2.5 border-b border-brand-ink/10 px-4 py-3.5 sm:px-5">
                <div class="h-3.5 w-32 {{ $bar }}"></div>
                <div class="h-2.5 w-full max-w-2xl {{ $bar }}"></div>
                <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                    <div class="flex items-center gap-3 bg-brand-sand/30 px-4 py-2">
                        @foreach ([16, 14, 14, 12] as $width)
                            <span class="h-2 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                        @endforeach
                    </div>
                    <div class="divide-y divide-brand-ink/5 bg-white">
                        @foreach (range(1, 3) as $row)
                            <div class="flex items-center gap-3 px-4 py-2">
                                @foreach ([16, 14, 14, 12] as $width)
                                    <span class="h-2.5 shrink-0 {{ $bar }}" style="width: {{ $width * 4 }}px;"></span>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    @elseif ($tab === 'notifications')
        {{-- Notifications: head with the Settings escape hatch, the routed
             channel list, then the add-a-channel form. --}}
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-32 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-20 shrink-0 rounded-full {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
            <span class="h-6 w-32 shrink-0 rounded-lg {{ $bar }}"></span>
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
        {{-- Overview: the active-engine head with its status pill, the lifecycle
             action rows, then the three navigation tiles. --}}
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-24 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-16 shrink-0 rounded-full {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        </div>
        <div class="divide-y divide-brand-ink/5 border-b border-brand-ink/10">
            @foreach (range(1, 2) as $group)
                <div class="flex flex-wrap items-center justify-between gap-3 bg-white px-4 py-3 sm:px-5">
                    <div class="min-w-0 space-y-1.5">
                        <div class="h-2 w-20 {{ $bar }}"></div>
                        <div class="h-2.5 w-56 max-w-full {{ $bar }}"></div>
                    </div>
                    <div class="flex gap-2">
                        @foreach (range(1, 3) as $action)
                            <span class="h-7 w-20 rounded-lg {{ $bar }}"></span>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        <div class="grid gap-2 px-4 py-3.5 sm:grid-cols-2 sm:px-5">
            @foreach (range(1, 3) as $tile)
                <div @class(['rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3', 'sm:col-span-2' => $tile === 3])>
                    <div class="flex items-start gap-3">
                        <span class="h-9 w-9 shrink-0 rounded-xl {{ $bar }}"></span>
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="h-3 w-32 max-w-full {{ $bar }}"></div>
                            <div class="h-2.5 w-48 max-w-full {{ $bar }}"></div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
