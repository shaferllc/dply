@php
    $webserverCatalog = \App\Support\Servers\WebserverWorkspaceViewData::webserverCatalog();
    $activeWebserverInfo = $webserverCatalog[$activeWebserver] ?? null;
@endphp

<div class="space-y-6">
    <div class="{{ $card }} p-6 sm:p-8">
        <div class="max-w-2xl">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Edge proxy on this server') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('An edge proxy is optional. When active, it binds :80 and routes hostnames to Caddy backends on ephemeral high ports. Removing an edge proxy restores :webserver on :port.', ['port' => 80, 'webserver' => $edgeProxyPreviousLabel ?? __('your previous webserver')]) }}
            </p>
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-3">
            @if ($activeEdgeProxy !== null)
                @php $activeInfo = $edgeProxyCatalog[$activeEdgeProxy] ?? null; @endphp
                <span class="inline-flex items-center gap-2 rounded-full bg-brand-sage/15 px-3 py-1.5 text-sm font-semibold text-brand-forest ring-1 ring-brand-sage/25">
                    <x-dynamic-component :component="$activeInfo['icon'] ?? 'heroicon-o-arrow-path-rounded-square'" class="h-4 w-4" />
                    {{ $activeInfo['label'] ?? ucfirst($activeEdgeProxy) }} {{ __('active on :80', ['port' => 80]) }}
                </span>
                <button
                    type="button"
                    wire:click="setWorkspaceTab('{{ $activeEdgeProxy }}')"
                    class="text-sm font-semibold text-brand-forest underline decoration-brand-forest/30 underline-offset-2 hover:text-brand-forest/80"
                >
                    {{ __('Open :name controls', ['name' => $activeInfo['label'] ?? ucfirst($activeEdgeProxy)]) }}
                </button>
            @else
                <span class="inline-flex items-center gap-2 rounded-full bg-brand-sand/50 px-3 py-1.5 text-sm font-medium text-brand-moss ring-1 ring-brand-ink/10">
                    {{ __('No edge proxy — webserver serves :80 directly', ['port' => 80]) }}
                </span>
            @endif
        </div>

        @if ($activeWebserverInfo !== null)
            <p class="mt-4 text-sm text-brand-moss">
                {{ __('Webserver preference: :engine', ['engine' => $activeWebserverInfo['label']]) }}
                <a href="{{ route('servers.webserver', $server) }}" wire:navigate class="font-semibold text-brand-forest underline decoration-brand-forest/30 underline-offset-2 hover:text-brand-forest/80">
                    {{ __('Open Webserver workspace') }}
                </a>
            </p>
        @endif
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
        <button
            type="button"
            wire:click="setWorkspaceTab('change')"
            class="group {{ $card }} flex items-start gap-3 p-5 text-left transition hover:border-brand-forest/25 hover:shadow-md sm:p-6"
        >
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-arrow-up-tray class="h-5 w-5" aria-hidden="true" />
            </span>
            <span class="min-w-0">
                <span class="block text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Add or remove edge proxy') }}</span>
                <span class="mt-1 block text-[13px] leading-5 text-brand-moss">{{ __('Install Traefik, HAProxy, or preview upcoming engines in front of port :80.', ['port' => 80]) }}</span>
            </span>
        </button>
        @if ($activeEdgeProxy !== null)
            <button
                type="button"
                wire:click="setWorkspaceTab('{{ $activeEdgeProxy }}')"
                class="group {{ $card }} flex items-start gap-3 p-5 text-left transition hover:border-brand-forest/25 hover:shadow-md sm:p-6"
            >
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-dynamic-component :component="$edgeProxyCatalog[$activeEdgeProxy]['icon'] ?? 'heroicon-o-bolt'" class="h-5 w-5" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Manage :name', ['name' => $edgeProxyCatalog[$activeEdgeProxy]['label'] ?? ucfirst($activeEdgeProxy)]) }}</span>
                    <span class="mt-1 block text-[13px] leading-5 text-brand-moss">{{ __('Routers, config, logs, and service lifecycle for the active edge proxy.') }}</span>
                </span>
            </button>
        @endif
    </div>
</div>
