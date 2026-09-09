{{--
    Lazy-load skeleton for Networking. Mirrors the merged page (hide-hero +
    single card with identity, tabs, and map stubs).
--}}
<x-server-workspace-layout
    :server="$server"
    active="networking"
    :title="__('Networking')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading networking…') }}</span>

        {{-- The same dense head the real page renders — keep the two in step or
             the card resizes when the render swaps in. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-share"
            :title="__('Networking')"
            :note="__('Servers sharing this server\'s private network — their private IPs, running services, and which databases are open to the network.')"
            class="border-b border-brand-ink/10"
        />

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-4 py-2.5" aria-hidden="true">
            {{-- Routes included: it renders whenever the server is on a Hetzner
                 private network, and omitting it made the strip jump a tab wider
                 on hydrate. --}}
            @foreach ([__('Servers'), __('Access'), __('Attached'), __('Routes'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-8 items-center rounded-lg px-3 text-xs font-semibold',
                    'bg-brand-ink text-white' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        {{-- Servers tab body: a dense section head, then one row per server in the
             workspace map. The stub head used to be the tall icon-badge shape the
             page no longer renders, over a 3x3 grid of fat tiles that matched
             nothing — the card jumped on hydrate. --}}
        @php $bar = 'animate-pulse rounded bg-brand-ink/10'; @endphp
        <div aria-hidden="true">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4">
                <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                <span class="h-3.5 w-40 shrink-0 {{ $bar }}"></span>
                <span class="h-4 w-16 shrink-0 rounded-full {{ $bar }}"></span>
                <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
            </div>

            <div class="divide-y divide-brand-ink/8">
                @foreach (range(1, 3) as $srv)
                    <div class="flex items-center gap-3 px-6 py-3 sm:px-7">
                        <span class="h-8 w-8 shrink-0 rounded-lg {{ $bar }}"></span>
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="h-3 w-40 {{ $bar }}"></div>
                            <div class="h-2.5 w-56 max-w-full {{ $bar }}"></div>
                        </div>
                        <span class="h-2.5 w-24 shrink-0 {{ $bar }}"></span>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
</x-server-workspace-layout>
