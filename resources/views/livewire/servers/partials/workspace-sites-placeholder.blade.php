{{--
    Lazy-load skeleton for Sites. Mirrors the merged page (hide-hero + single
    card with identity header and site directory rows).
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

        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6" aria-hidden="true">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        @if ($isContainerHost)
                            <x-heroicon-o-cube-transparent class="h-5 w-5" aria-hidden="true" />
                        @else
                            <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                        @endif
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold tracking-tight text-brand-ink">
                            {{ $isContainerHost ? __('Container apps') : __('Sites') }}
                        </h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Manage sites on this server — deploys, env, and settings per workspace.') }}
                        </p>
                        <div class="mt-2 h-3 w-36 animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                </div>
                <span class="inline-flex h-8 w-24 animate-pulse rounded-lg bg-brand-ink/15"></span>
            </div>
        </div>

        <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6" aria-hidden="true">
            <div class="flex items-center gap-2">
                <x-heroicon-o-rectangle-stack class="h-4 w-4 text-brand-mist" aria-hidden="true" />
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                    {{ $isContainerHost ? __('Container apps') : __('Site directory') }}
                </p>
            </div>
            <div class="mt-2 h-3 w-48 animate-pulse rounded bg-brand-ink/10"></div>
        </div>

        <ul class="divide-y divide-brand-ink/10" aria-hidden="true">
            @foreach (range(1, 3) as $row)
                <li class="flex items-center gap-4 px-5 py-4 sm:px-6">
                    <span class="inline-flex h-9 w-9 shrink-0 animate-pulse rounded-lg bg-brand-ink/10"></span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <div class="h-3.5 w-40 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                        <div class="h-2.5 w-56 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                    </div>
                    <span class="h-6 w-14 animate-pulse rounded-md bg-brand-ink/10"></span>
                    <span class="h-8 w-16 animate-pulse rounded-lg bg-brand-ink/15"></span>
                </li>
            @endforeach
        </ul>
    </div>
</x-server-workspace-layout>
