@php
    $webserverCatalog = \App\Support\Servers\WebserverWorkspaceViewData::webserverCatalog();
    $activeWebserverInfo = $webserverCatalog[$activeWebserver] ?? null;
    $activeEdgeInfo = $activeEdgeProxy !== null ? ($edgeProxyCatalog[$activeEdgeProxy] ?? null) : null;
@endphp

<div class="min-w-0">
    <div class="{{ $card }}">
        <x-workspace-panel-head
            dense
            :icon="$activeEdgeInfo['icon'] ?? 'heroicon-o-arrow-path-rounded-square'"
            :title="__('Edge proxy on this server')"
            :count="$activeEdgeInfo !== null ? __(':name on :80', ['name' => $activeEdgeInfo['label'], 'port' => 80]) : __('None')"
            :note="__('An edge proxy is optional. When active, it binds :80 and routes hostnames to Caddy backends on ephemeral high ports. Removing an edge proxy restores :webserver on :port.', ['port' => 80, 'webserver' => $edgeProxyPreviousLabel ?? __('your previous webserver')])"
            class="border-b border-brand-ink/10"
        >
            @if ($activeEdgeProxy !== null)
                <x-slot:actions>
                    <button
                        type="button"
                        wire:click="setWorkspaceTab('{{ $activeEdgeProxy }}')"
                        class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-[11px] font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        {{ __('Open :name controls', ['name' => $activeEdgeInfo['label'] ?? ucfirst($activeEdgeProxy)]) }}
                    </button>
                </x-slot:actions>
            @endif
        </x-workspace-panel-head>

        {{-- What's serving :80 right now, and what it falls back to — the two
             facts an operator opens this page for. --}}
        <x-workspace-stat-strip class="border-b border-brand-ink/10" :columns="3" :stats="[
            [
                'label' => __('Serving port :port', ['port' => 80]),
                'value' => $activeEdgeInfo['label'] ?? __('Webserver'),
                'tone' => $activeEdgeProxy !== null ? 'ok' : null,
                'hint' => $activeEdgeProxy !== null
                    ? __('The edge proxy binds :80 and routes to Caddy backends.', ['port' => 80])
                    : __('No edge proxy — the webserver serves :80 directly.', ['port' => 80]),
            ],
            [
                'label' => __('Webserver'),
                'value' => $activeWebserverInfo['label'] ?? '—',
                'hint' => __('Engine serving your sites behind the edge proxy'),
            ],
            [
                'label' => __('Restores to'),
                'value' => $edgeProxyPreviousLabel ?: '—',
                'hint' => __('What returns to :80 if the edge proxy is removed', ['port' => 80]),
            ],
        ]" />

        @if ($activeWebserverInfo !== null)
            <p class="border-b border-brand-ink/10 px-4 py-2 text-[11px] text-brand-moss sm:px-5">
                {{ __('Webserver preference: :engine', ['engine' => $activeWebserverInfo['label']]) }}
                <a href="{{ route('servers.webserver', $server) }}" wire:navigate class="font-semibold text-brand-forest underline decoration-brand-forest/30 underline-offset-2 hover:text-brand-forest/80">
                    {{ __('Open Webserver workspace') }}
                </a>
            </p>
        @endif
    </div>

    <div class="grid gap-2 border-b border-brand-ink/10 px-4 py-3.5 sm:grid-cols-2 sm:px-5">
        <button
            type="button"
            wire:click="setWorkspaceTab('change')"
            class="group flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3 text-left transition hover:border-brand-forest/30 hover:bg-brand-sand/30"
        >
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-brand-forest ring-1 ring-brand-ink/10">
                <x-heroicon-o-arrow-up-tray class="h-4 w-4" aria-hidden="true" />
            </span>
            <span class="min-w-0">
                <span class="block text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Add or remove edge proxy') }}</span>
                <span class="mt-0.5 block text-[12px] leading-5 text-brand-moss">{{ __('Install Traefik, HAProxy, or preview upcoming engines in front of port :80.', ['port' => 80]) }}</span>
            </span>
        </button>
        @if ($activeEdgeProxy !== null)
            <button
                type="button"
                wire:click="setWorkspaceTab('{{ $activeEdgeProxy }}')"
                class="group flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3 text-left transition hover:border-brand-forest/30 hover:bg-brand-sand/30"
            >
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-brand-forest ring-1 ring-brand-ink/10">
                    <x-dynamic-component :component="$edgeProxyCatalog[$activeEdgeProxy]['icon'] ?? 'heroicon-o-bolt'" class="h-4 w-4" aria-hidden="true" />
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-brand-ink group-hover:text-brand-forest">{{ __('Manage :name', ['name' => $edgeProxyCatalog[$activeEdgeProxy]['label'] ?? ucfirst($activeEdgeProxy)]) }}</span>
                    <span class="mt-0.5 block text-[12px] leading-5 text-brand-moss">{{ __('Routers, config, logs, and service lifecycle for the active edge proxy.') }}</span>
                </span>
            </button>
        @endif
    </div>
</div>
