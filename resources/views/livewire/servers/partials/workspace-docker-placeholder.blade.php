{{--
    Lazy-load skeleton for Docker. Mirrors the merged page (hide-hero + single
    card with identity, tabs, and overview stubs) — dense head, hairline stat
    strip, compact tiles, so the geometry matches what replaces it.
--}}
@php $bar = 'animate-pulse rounded bg-brand-ink/10'; @endphp
<x-server-workspace-layout
    :server="$server"
    active="docker"
    :title="__('Docker')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading Docker…') }}</span>

        <x-workspace-panel-head
            dense
            icon="heroicon-o-square-3-stack-3d"
            :title="__('Docker')"
            :note="__('Manage Docker Engine on this server — containers, images, volumes, networks, compose projects, and cleanup.')"
            class="border-b border-brand-ink/10"
        />

        <div class="flex flex-wrap gap-1.5 border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-hidden="true">
            @foreach ([__('Overview'), __('Containers'), __('Images'), __('Volumes'), __('Networks'), __('Compose'), __('Maintenance')] as $i => $label)
                <span @class([
                    'inline-flex h-6 items-center rounded-lg px-2.5 text-[11px] font-semibold leading-none',
                    'bg-brand-ink text-brand-cream shadow-sm' => $i === 0,
                    'animate-pulse bg-brand-ink/10 text-transparent' => $i !== 0,
                ])>{{ $label }}</span>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4" aria-hidden="true">
            <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
            <span class="h-3.5 w-28 shrink-0 {{ $bar }}"></span>
            <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
            <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
            <span class="h-6 w-20 shrink-0 rounded-full {{ $bar }}"></span>
            <span class="h-6 w-24 shrink-0 rounded-md {{ $bar }}"></span>
        </div>
        <dl class="grid grid-cols-2 border-b border-brand-ink/10 sm:grid-cols-4" aria-hidden="true">
            @foreach (range(0, 3) as $cell)
                <div @class([
                    'space-y-1.5 border-brand-ink/8 px-4 py-2 sm:px-5',
                    'border-l' => $cell % 2 !== 0,
                    'sm:border-l' => $cell % 4 !== 0,
                    'sm:border-l-0' => $cell % 4 === 0,
                    'border-t' => $cell >= 2,
                    'sm:border-t-0' => true,
                ])>
                    <div class="h-2 w-20 {{ $bar }}"></div>
                    <div class="h-3 w-12 {{ $bar }}"></div>
                </div>
            @endforeach
        </dl>

        <div class="grid gap-2 px-4 py-3.5 sm:grid-cols-2 sm:px-5 lg:grid-cols-3" aria-hidden="true">
            @foreach (range(1, 6) as $tile)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3">
                    <div class="space-y-1.5">
                        <div class="h-3 w-24 {{ $bar }}"></div>
                        <div class="h-2.5 w-40 max-w-full {{ $bar }}"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
</x-server-workspace-layout>
