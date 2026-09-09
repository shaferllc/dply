{{--
    Skeleton for a System users sub-tab while setActiveTab() round-trips.
    Same job as the SSH-keys `_tab-skeleton`: the tab strip is instant, the
    panel under it is not, so paint the shape that's arriving instead of
    dropping the card to nothing.

    Both tabs open with a dense panel head, so that part is shared; only the
    body differs. Head metrics track x-workspace-panel-head dense, so the card
    doesn't resize when the real render swaps in.

    Receives: $tab (accounts|notifications), $rows (accounts row count to stub).
--}}
@php
    $tab = $tab ?? 'accounts';
    $bar = 'animate-pulse rounded bg-brand-ink/10';

    // Clamp to what the list actually holds so switching back to Accounts
    // doesn't inflate the card and then snap shut.
    $rows = max(1, min(6, (int) ($rows ?? 4)));

    // Actions carried on the right of the head: Accounts has Add + Sync,
    // Notifications has the single "Manage in Settings" escape hatch.
    $headActions = $tab === 'notifications' ? [30] : [22, 20];
@endphp

<div aria-hidden="true">
    {{-- Dense head stub: icon, title, count pill, divider, note, actions. --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
        <span class="h-3.5 w-32 shrink-0 {{ $bar }}"></span>
        <span class="h-4 w-16 shrink-0 rounded-full {{ $bar }}"></span>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        @foreach ($headActions as $w)
            <span class="h-6 shrink-0 rounded-md {{ $bar }}" style="width: {{ $w * 4 }}px;"></span>
        @endforeach
    </div>

    @if ($tab === 'notifications')
        {{-- Notifications: the "you already get in-app alerts" strip, the routed
             channel rows, then the add-a-channel form. --}}
        <div class="flex items-start gap-2.5 border-b border-brand-ink/10 bg-brand-sand/15 px-4 py-3 sm:px-5">
            <span class="mt-0.5 h-4 w-4 shrink-0 {{ $bar }}"></span>
            <div class="min-w-0 flex-1 space-y-1.5">
                <div class="h-2.5 w-full {{ $bar }}"></div>
                <div class="h-2.5 w-2/3 {{ $bar }}"></div>
            </div>
        </div>

        <div class="px-4 py-3.5 sm:px-5">
            <div class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                @foreach (range(1, 2) as $sub)
                    <div class="flex items-center gap-3 px-4 py-3">
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="h-3 w-40 max-w-full {{ $bar }}"></div>
                            <div class="h-2.5 w-20 {{ $bar }}"></div>
                        </div>
                        <span class="h-5 w-24 shrink-0 rounded-full {{ $bar }}"></span>
                        <span class="h-5 w-20 shrink-0 rounded-full {{ $bar }}"></span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="border-t border-brand-ink/10 px-4 py-3.5 sm:px-5">
            <div class="h-3 w-28 {{ $bar }}"></div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="space-y-2">
                    <div class="h-2.5 w-16 {{ $bar }}"></div>
                    <div class="h-10 w-full rounded-lg {{ $bar }}"></div>
                    <div class="h-2.5 w-48 max-w-full {{ $bar }}"></div>
                </div>
                <div class="space-y-2">
                    <div class="h-2.5 w-16 {{ $bar }}"></div>
                    @foreach (range(1, 3) as $event)
                        <div class="flex items-center gap-2">
                            <span class="h-4 w-4 shrink-0 rounded {{ $bar }}"></span>
                            <span class="h-2.5 w-32 {{ $bar }}"></span>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="mt-4 flex justify-end">
                <span class="h-9 w-36 rounded-lg {{ $bar }}"></span>
            </div>
        </div>
    @else
        {{-- Accounts: one stub per /etc/passwd row — avatar, name + badges,
             the meta line, the Show-details toggle, and the Remove button. --}}
        <div class="divide-y divide-brand-ink/8">
            @foreach (range(1, $rows) as $row)
                <div class="flex items-start gap-3 px-6 py-4 sm:px-8">
                    <span class="mt-0.5 hidden h-9 w-9 shrink-0 rounded-xl sm:block {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="h-3.5 w-24 {{ $bar }}"></span>
                            <span class="h-4 w-14 rounded-full {{ $bar }}"></span>
                            <span class="h-4 w-16 rounded-full {{ $bar }}"></span>
                        </div>
                        <div class="h-2.5 w-56 max-w-full {{ $bar }}"></div>
                        <div class="h-2.5 w-24 {{ $bar }}"></div>
                    </div>
                    <span class="h-8 w-24 shrink-0 rounded-lg {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @endif
</div>
