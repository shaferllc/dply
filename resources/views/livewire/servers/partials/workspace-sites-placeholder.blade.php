{{--
    Lazy-load skeleton for Sites. Mirrors the merged page (hide-hero + single
    card with the dense identity head and site directory rows), so the geometry
    matches what replaces it.
--}}
@php
    $isContainerHost = in_array($server->hostKind(), [\App\Models\Server::HOST_KIND_DOCKER, \App\Models\Server::HOST_KIND_KUBERNETES], true);
@endphp

<x-server-workspace-layout
    :server="$server"
    active="sites"
    :title="__('Sites')"
    hide-hero
>
    <div class="dply-card min-w-0 overflow-hidden p-0" aria-busy="true" aria-live="polite">
        <span class="sr-only">{{ __('Loading sites…') }}</span>

        {{-- Dense head, matching the merged page. --}}
        <x-workspace-panel-head
            dense
            :icon="$isContainerHost ? 'heroicon-o-cube-transparent' : 'heroicon-o-globe-alt'"
            :title="$isContainerHost ? __('Container apps') : __('Sites')"
            :note="__('Manage sites on this server — deploys, env, and settings per workspace.')"
            class="border-b border-brand-ink/10"
        />

        <div class="border-b border-brand-ink/10 px-4 py-2 sm:px-5" aria-hidden="true">
            <div class="flex items-center gap-2">
                <x-heroicon-o-rectangle-stack class="h-4 w-4 text-brand-mist" aria-hidden="true" />
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">
                    {{ $isContainerHost ? __('Container apps') : __('Site directory') }}
                </p>
            </div>
            <div class="mt-2 h-3 w-48 animate-pulse rounded bg-brand-ink/10"></div>
        </div>

        <ul class="divide-y divide-brand-ink/10" aria-hidden="true">
            @foreach (range(1, 3) as $row)
                <li class="flex items-center gap-3 px-4 py-3 sm:px-5">
                    <span class="inline-flex h-9 w-9 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                    <span class="h-6 w-14 animate-pulse rounded-md bg-brand-ink/10"></span>
                    <span class="h-6 w-16 animate-pulse rounded-md bg-brand-ink/15"></span>
                </li>
            @endforeach
        </ul>
    </div>
</x-server-workspace-layout>
