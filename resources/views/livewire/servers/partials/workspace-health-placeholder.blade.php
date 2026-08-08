{{--
    Lazy-load skeleton for Health. Mirrors the merged page (hide-hero + single
    card with the dense head, tabs, overview alert rows, and the figure strip).
--}}
<x-server-workspace-layout
    :server="$server"
    active="health"
    :title="__('Health')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading health…') }}</span>

        {{-- Dense head, matching the merged page — the verdict pill is the only
             part still unknown at this point, so it alone pulses. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-heart"
            :title="__('Health')"
            :note="__('Capacity, releases, deploy failures, certificates, and daemon drift — one cockpit for this server.')"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                <span class="inline-flex h-6 w-24 animate-pulse rounded-full bg-brand-ink/10" aria-hidden="true"></span>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
            @foreach ([__('Overview'), __('Capacity'), __('Releases'), __('Reliability'), __('Notifications')] as $i => $label)
                <span @class([
                    'inline-flex h-6 items-center rounded-lg px-2.5 text-[11px] font-semibold leading-none',
                    'bg-brand-ink text-brand-cream shadow-sm' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4" aria-hidden="true">
            <span class="h-4 w-4 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
            <span class="h-3.5 w-20 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 animate-pulse rounded bg-brand-ink/10"></span>
            <span class="h-6 w-24 shrink-0 animate-pulse rounded-md bg-brand-ink/10"></span>
        </div>

        <ul class="divide-y divide-brand-ink/10" aria-hidden="true">
            @foreach (range(1, 3) as $row)
                <li class="flex items-center gap-2 px-4 py-2 sm:px-5">
                    <span class="h-4 w-14 shrink-0 animate-pulse rounded-full bg-brand-ink/10"></span>
                    <span class="h-2.5 w-40 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="h-2.5 min-w-0 flex-1 animate-pulse rounded bg-brand-ink/10"></span>
                    <span class="h-2.5 w-20 shrink-0 animate-pulse rounded bg-brand-ink/10"></span>
                </li>
            @endforeach
        </ul>

        <div class="grid grid-cols-2 border-t border-brand-ink/10 sm:grid-cols-4" aria-hidden="true">
            @foreach (range(1, 4) as $cell)
                <div class="space-y-1.5 px-4 py-2 sm:px-5">
                    <div class="h-2 w-14 animate-pulse rounded bg-brand-ink/10"></div>
                    <div class="h-3 w-10 animate-pulse rounded bg-brand-ink/10"></div>
                </div>
            @endforeach
        </div>
    </section>
</x-server-workspace-layout>
