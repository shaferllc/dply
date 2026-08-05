{{--
    Skeleton for a firewall tab while setFirewallWorkspaceTab() round-trips, and
    the body of the lazy first paint. Shaped per tab so the loading state
    resembles the panel that's arriving rather than standing in generically.

    Every tab opens with a dense panel head, so that part is shared; only the
    body below differs. Head metrics track x-workspace-panel-head dense, so the
    card doesn't resize when the real render swaps in.

    Receives: $tab (rules|templates|activity|notifications).
--}}
@php
    $tab = $tab ?? 'rules';
    $bar = 'animate-pulse rounded bg-brand-ink/10';
@endphp

<div aria-hidden="true">
    {{-- Dense head stub: icon, title, count pill, divider, note, then any
         actions the real head carries on the right. --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
        <span class="h-3.5 w-32 shrink-0 {{ $bar }}"></span>
        <span class="h-4 w-16 shrink-0 rounded-full {{ $bar }}"></span>
        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
        @if ($tab === 'rules')
            <span class="h-7 w-24 shrink-0 rounded-lg {{ $bar }}"></span>
            <span class="h-7 w-24 shrink-0 rounded-lg {{ $bar }}"></span>
            <span class="h-7 w-16 shrink-0 rounded-lg {{ $bar }}"></span>
        @elseif ($tab === 'notifications')
            <span class="h-7 w-36 shrink-0 rounded-lg {{ $bar }}"></span>
        @endif
    </div>

    @switch ($tab)
        {{-- Policies selects, then the rules head, status strip, filter row, table. --}}
        @case ('rules')
            <div class="px-6 py-4 sm:px-8">
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach (range(1, 3) as $chain)
                        <div class="space-y-2">
                            <div class="h-2.5 w-24 {{ $bar }}"></div>
                            <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
                        </div>
                    @endforeach
                </div>
            </div>
            {{-- No apply-status strip stub: that row only exists once an apply has
                 run, and the never-applied case rides the head's count pill. --}}
            <div class="flex flex-wrap items-center gap-2 border-t border-brand-ink/10 px-6 py-3 sm:px-8">
                <span class="h-9 min-w-0 flex-1 rounded-lg {{ $bar }}"></span>
                <span class="h-8 w-44 shrink-0 rounded-lg {{ $bar }}"></span>
            </div>
            <div class="px-6 pb-5 sm:px-8">
                <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                    <div class="flex gap-6 border-b border-brand-ink/10 bg-brand-sand/30 px-3 py-2">
                        @foreach ([64, 56, 48, 40, 40, 48] as $w)
                            <span class="h-2.5 shrink-0 {{ $bar }}" style="width: {{ $w }}px"></span>
                        @endforeach
                    </div>
                    @foreach (range(1, 4) as $row)
                        <div class="flex items-center gap-6 border-b border-brand-ink/10 px-3 py-2.5 last:border-b-0">
                            @foreach ([64, 56, 48, 40, 40, 48] as $w)
                                <span class="h-3 shrink-0 {{ $bar }}" style="width: {{ $w }}px"></span>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
            @break

        {{-- Template cards in a two-up grid. --}}
        @case ('templates')
            <div class="grid gap-3 px-6 py-4 sm:grid-cols-2 sm:px-8">
                @foreach (range(1, 4) as $tpl)
                    <div class="space-y-2 rounded-xl border border-brand-ink/10 bg-white px-4 py-3">
                        <div class="h-3 w-32 {{ $bar }}"></div>
                        <div class="h-2.5 w-full {{ $bar }}"></div>
                        <div class="flex gap-2 pt-1">
                            <span class="h-7 w-20 rounded-lg {{ $bar }}"></span>
                            <span class="h-7 w-16 rounded-lg {{ $bar }}"></span>
                        </div>
                    </div>
                @endforeach
            </div>
            @break

        {{-- Chronological event rows: icon, two lines of text, a timestamp. --}}
        @case ('activity')
            <div class="space-y-2 px-6 py-4 sm:px-8">
                @foreach (range(1, 6) as $event)
                    <div class="flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3">
                        <span class="h-8 w-8 shrink-0 rounded-lg {{ $bar }}"></span>
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="h-3 w-1/3 {{ $bar }}"></div>
                            <div class="h-2.5 w-3/4 {{ $bar }}"></div>
                        </div>
                        <span class="h-2.5 w-20 shrink-0 {{ $bar }}"></span>
                    </div>
                @endforeach
            </div>
            @break

        {{-- Info strip, then channel/event subscription rows. --}}
        @case ('notifications')
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
            @break
    @endswitch
</div>
