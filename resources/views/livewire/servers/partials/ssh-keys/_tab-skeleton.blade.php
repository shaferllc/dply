{{--
    Skeleton for an SSH-keys tab while setSshWorkspaceTab() round-trips, and the
    body of the lazy first paint. Shaped per tab so the loading state resembles
    the panel that's arriving.

    Every tab opens with a dense panel head, so that part is shared; only the
    body differs. Head metrics track x-workspace-panel-head dense, so the card
    doesn't resize when the real render swaps in.

    Receives: $tab (keys|preview|advanced|activity|notifications).
--}}
@php
    $tab = $tab ?? 'keys';
    $bar = 'animate-pulse rounded bg-brand-ink/10';

    // Actions carried on the right of each tab's first head.
    $headActions = match ($tab) {
        'keys' => [20, 18, 20],
        'preview' => [18, 20],
        'notifications' => [24],
        default => [],
    };
@endphp

<div aria-hidden="true">
    {{-- Dense head stub: icon, title, optional count pill, divider, note, actions. --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
        <span class="h-3.5 w-36 shrink-0 {{ $bar }}"></span>
        @if (in_array($tab, ['keys', 'activity', 'notifications'], true))
            <span class="h-4 w-16 shrink-0 rounded-full {{ $bar }}"></span>
        @endif
        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        @foreach ($headActions as $w)
            <span class="h-6 shrink-0 rounded-md {{ $bar }}" style="width: {{ $w * 4 }}px;"></span>
        @endforeach
    </div>

    @if ($tab === 'preview')
        {{-- Drift: two stacked add/remove groups of key rows. --}}
        <div class="space-y-3 px-4 py-3.5 sm:px-5">
            @foreach (range(1, 2) as $group)
                <div class="overflow-hidden rounded-lg border border-brand-ink/10 bg-white">
                    <div class="flex items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2">
                        <span class="h-3.5 w-3.5 shrink-0 rounded-full {{ $bar }}"></span>
                        <span class="h-2.5 w-28 shrink-0 {{ $bar }}"></span>
                    </div>
                    <div class="divide-y divide-brand-ink/5">
                        @foreach (range(1, 2) as $row)
                            <div class="flex items-center gap-3 px-3 py-2">
                                <span class="h-2.5 w-36 shrink-0 {{ $bar }}"></span>
                                <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                                <span class="h-2.5 w-16 shrink-0 {{ $bar }}"></span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @elseif ($tab === 'advanced')
        {{-- Advanced: two checkbox rows, a labelled input, then the save button. --}}
        <div class="max-w-xl space-y-3.5 px-4 py-3.5 sm:px-5">
            @foreach (range(1, 2) as $check)
                <div class="flex items-start gap-2.5">
                    <span class="mt-0.5 h-4 w-4 shrink-0 rounded {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-3 w-64 max-w-full {{ $bar }}"></div>
                        <div class="h-2.5 w-80 max-w-full {{ $bar }}"></div>
                    </div>
                </div>
            @endforeach
            <div class="space-y-1.5">
                <div class="h-3 w-40 {{ $bar }}"></div>
                <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
                <div class="h-2.5 w-3/4 {{ $bar }}"></div>
            </div>
            <div class="h-8 w-40 rounded-lg {{ $bar }}"></div>
        </div>
    @elseif ($tab === 'activity')
        {{-- Activity: audit rows — dot, two text lines, timestamp. One row per
             event the first page will hold, so the list doesn't resize. --}}
        <div class="space-y-1.5 px-4 py-3.5 sm:px-5">
            @foreach (range(1, \App\Livewire\Servers\WorkspaceSshKeys::ACTIVITY_PER_PAGE) as $ev)
                <div class="flex items-center gap-2.5 rounded-lg border border-brand-ink/8 bg-white px-3 py-2">
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-2.5 w-48 max-w-full {{ $bar }}"></div>
                        <div class="h-2 w-2/3 {{ $bar }}"></div>
                    </div>
                    <span class="h-2 w-16 shrink-0 {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @elseif ($tab === 'notifications')
        {{-- Notifications: info strip, then channel/event subscription rows. --}}
        <div class="flex items-start gap-2.5 border-b border-brand-ink/10 bg-brand-sand/15 px-4 py-3 sm:px-5">
            <span class="mt-0.5 h-4 w-4 shrink-0 {{ $bar }}"></span>
            <div class="min-w-0 flex-1 space-y-1.5">
                <div class="h-2.5 w-full {{ $bar }}"></div>
                <div class="h-2.5 w-2/3 {{ $bar }}"></div>
            </div>
        </div>
        <div class="space-y-2 px-4 py-3.5 sm:px-5">
            @foreach (range(1, 3) as $sub)
                <div class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3">
                    <span class="h-8 w-8 shrink-0 rounded-lg {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-3 w-40 {{ $bar }}"></div>
                        <div class="h-2.5 w-1/2 {{ $bar }}"></div>
                    </div>
                    <span class="h-7 w-20 shrink-0 rounded-lg {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @else
        {{-- Keys: the "no personal key" strip the tab usually carries, a second
             dense head for "Keys on this server", then the key rows. --}}
        <div class="px-4 py-3 sm:px-5">
            <div class="h-10 w-full rounded-lg {{ $bar }}"></div>
        </div>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-y border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-40 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-12 shrink-0 rounded-full {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        </div>
        <div class="divide-y divide-brand-ink/5">
            @foreach (range(1, 4) as $key)
                <div class="flex items-center gap-3 px-4 py-2.5 sm:px-5">
                    <span class="h-8 w-8 shrink-0 rounded-lg {{ $bar }}"></span>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <div class="h-3 w-44 max-w-full {{ $bar }}"></div>
                        <div class="h-2.5 w-64 max-w-full {{ $bar }}"></div>
                    </div>
                    <span class="h-2.5 w-20 shrink-0 {{ $bar }}"></span>
                    <span class="h-6 w-16 shrink-0 rounded-md {{ $bar }}"></span>
                </div>
            @endforeach
        </div>
    @endif
</div>
