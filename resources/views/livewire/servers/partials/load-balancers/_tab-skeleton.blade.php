{{--
    Skeleton for a load-balancer tab while setLbWorkspaceTab() round-trips, and
    the body of the lazy first paint. Shaped per tab so the loading state
    resembles the panel that's arriving.

    Both tabs open with a dense panel head, so that part is shared; only the body
    differs. Head metrics track x-workspace-panel-head dense, so the card doesn't
    resize when the real render swaps in.

    Receives: $tab (load_balancers|notifications).
--}}
@php
    $tab = $tab ?? 'load_balancers';
    $bar = 'animate-pulse rounded bg-brand-ink/10';
@endphp

<div aria-hidden="true">
    {{-- Dense head stub: icon, title, divider, note, and the action each tab
         carries on the right. --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
        <span class="h-3.5 w-40 shrink-0 {{ $bar }}"></span>
        @if ($tab === 'notifications')
            <span class="h-4 w-20 shrink-0 rounded-full {{ $bar }}"></span>
        @endif
        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        <span class="h-7 w-36 shrink-0 rounded-lg {{ $bar }}"></span>
    </div>

    @if ($tab === 'notifications')
        {{-- Info strip, then channel/event subscription rows. --}}
        <div class="flex items-start gap-2.5 border-b border-brand-ink/10 bg-brand-sand/15 px-6 py-3 sm:px-8">
            <span class="mt-0.5 h-4 w-4 shrink-0 {{ $bar }}"></span>
            <div class="min-w-0 flex-1 space-y-1.5">
                <div class="h-2.5 w-full {{ $bar }}"></div>
                <div class="h-2.5 w-2/3 {{ $bar }}"></div>
            </div>
        </div>
        <div class="space-y-2 px-6 py-4 sm:px-8">
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
        {{-- Balancer cards: name + status pill, address rows, target list. --}}
        <div class="space-y-3 px-6 py-4 sm:px-7">
            @foreach (range(1, 2) as $lb)
                <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                    <div class="flex flex-wrap items-center gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                        <span class="h-8 w-8 shrink-0 rounded-lg {{ $bar }}"></span>
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="h-3 w-40 {{ $bar }}"></div>
                            <div class="h-2.5 w-56 max-w-full {{ $bar }}"></div>
                        </div>
                        <span class="h-5 w-20 shrink-0 rounded-full {{ $bar }}"></span>
                    </div>
                    <div class="space-y-2 px-4 py-3">
                        @foreach (range(1, 2) as $target)
                            <div class="flex items-center gap-3">
                                <span class="h-2.5 w-32 shrink-0 {{ $bar }}"></span>
                                <span class="h-2.5 w-24 shrink-0 {{ $bar }}"></span>
                                <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                                <span class="h-6 w-16 shrink-0 rounded-lg {{ $bar }}"></span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
