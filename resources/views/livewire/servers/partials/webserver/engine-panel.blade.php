@php
    // Per-engine state shared across every sub-partial below. Lives at the
    // dispatcher level (not inside _header-tabs / _live-state-vars) because
    // @include creates an isolated scope for vars defined via @php inside the
    // included file — hoisting them here lets every partial read them
    // through normal inherit.
    $isEdgeProxyPanel = ! empty($info['is_edge_proxy']);
    $isActive = $isEdgeProxyPanel
        ? $key === $activeEdgeProxy
        : $key === $activeWebserver;
    $unit = $unitFor($info['systemd']);
    $pill = $statePill($unit['active_state'] ?? null);
    $version = $versionFor($key);
    $actionTriad = $actionTriadFor($key);
    $isBlocked = ! $isEdgeProxyPanel && ! $isActive && $preflight->isBlocked($server, $key);
    $blockerReason = $isBlocked ? $preflight->plan($server, $key)['blocker']['label'] ?? null : null;
    $hasControls = $isActive && $engineHasFullControls($key);

    // ---- Local-only panel preview -------------------------------------------
    // Switching the live webserver is destructive and slow, so reviewing another
    // engine's panels used to mean actually switching. In local this flag renders
    // them as if the engine were active, with three hard limits: it only applies
    // to engines that have real panels, every mutating control is disabled by
    // reusing the deployer read-only path, and the sub-tab data boot is skipped
    // so nothing SSHes for an engine that isn't installed.
    $panelPreviewAvailable = $this->enginePanelPreviewAvailable()
        && ! $isActive
        && ! $isEdgeProxyPanel
        && $engineHasFullControls($key);
    $isPanelPreview = $panelPreviewAvailable && $this->previewingEnginePanels();
    if ($isPanelPreview) {
        $isActive = true;
        $hasControls = true;
        $isDeployer = true;
    }

    // Live-state sub-tab dispatch. Each active engine surfaces its own
    // probe-backed sub-tabs (vhosts / routes / upstreams / certs / etc.).
    $liveStateTabsByEngine = [
        'openlitespeed' => ['vhosts', 'listeners', 'extapps', 'modules', 'cache'],
        'caddy' => ['routes', 'upstreams', 'certs', 'admin'],
        'nginx' => ['hosts', 'upstreams', 'certs', 'modules', 'workers'],
        'apache' => ['vhosts', 'modules', 'certs', 'workers'],
        'traefik' => [
            'routers', 'services', 'middlewares', 'entrypoints',
            'tcprouters', 'tcpservices', 'udprouters', 'udpservices', 'tls', 'providers',
        ],
        'haproxy' => ['frontends', 'backends', 'ssl', 'runtime'],
        'envoy' => ['listeners', 'clusters', 'runtime', 'virtualhosts', 'stats'],
        'openresty' => ['servers', 'upstreams', 'runtime'],
    ];
    $tabsForThisEngine = $liveStateTabsByEngine[$key] ?? [];
    $isLiveStateView = ($isActive || $isEdgeProxyPanel) && in_array($engine_subtab, $tabsForThisEngine, true);

    // Coming-soon engines (flagged in the catalog and not yet active) render a
    // preview teaser instead of the actionable switch / lifecycle panels.
    $isComingSoon = ! $isActive && ! empty($info['coming_soon']);

    // nginx live-state sub-tabs still being finished — show the shared
    // coming-soon teaser in place of their real config panels + live-state
    // table. Overview / Logs / Config / Info / Hosts stay fully functional. The
    // tabs remain clickable so the roadmap stays discoverable. Listed
    // explicitly (not via $isLiveStateView) because the live-state dispatch map
    // above omits nginx's `cache` tab.
    //
    // NginxLiveStateProbe parses every `server {}` / `upstream {}` block, every
    // `ssl_certificate` path (with its openssl-derived expiry), the module list,
    // and stub_status out of `nginx -T`; ManagesNginxWebserver backs the
    // `dply-custom-*.conf`, http-level upstream, dynamic-module, global-options,
    // and cache-zone panels. Certs is read-only by design — issuance and renewal
    // live in the Certificates module, linked from the panel above the table.
    // Every nginx sub-tab is backed by a real panel now, so nothing here carries
    // a Soon badge. Kept (empty) because the strip and the teaser dispatch below
    // still read it — an engine that regresses can be re-listed in one line.
    $nginxComingSoonSubtabs = [];
    $nginxLiveStateComingSoon = $key === 'nginx'
        && ($isActive || $isEdgeProxyPanel)
        && in_array($engine_subtab, $nginxComingSoonSubtabs, true);

    // Live-state sub-tab keys that still render the coming-soon teaser instead
    // of a real panel — surfaced to _header-tabs so those tabs carry a "Soon"
    // badge. Today only nginx's strip is unfinished; every other active
    // engine's live-state tabs are backed by real panels.
    $comingSoonSubtabKeys = $key === 'nginx' ? $nginxComingSoonSubtabs : [];

    // Instant sub-tab paint: entangle engine_subtab client-side, defer SSH via wire:init.
    $optimisticEngineSubtabs = ($isActive || $isEdgeProxyPanel) && ! $isComingSoon;
    $liveStateTabKeys = $liveStateTabsByEngine[$key] ?? [];
@endphp
@if ($optimisticEngineSubtabs)
    <div x-data="{ subtab: @entangle('engine_subtab').live }">
@endif

@if ($panelPreviewAvailable)
    {{-- Local-only escape hatch: look at another engine's panels without paying
         for a real switch. Dashed + amber so it never reads as product chrome. --}}
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-dashed border-amber-300/70 bg-amber-50/60 px-3 py-2 sm:px-4">
        <x-heroicon-o-beaker class="h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
        <span class="shrink-0 text-xs font-semibold text-amber-900">{{ __('Local preview') }}</span>
        <span class="h-4 w-px shrink-0 bg-amber-300/60" aria-hidden="true"></span>
        <p class="min-w-0 flex-1 text-xs leading-relaxed text-amber-900/80">
            @if ($isPanelPreview)
                {{ __(':engine is not the active engine. Panels render read-only — every control is disabled and no data is read from the server.', ['engine' => $info['label']]) }}
            @else
                {{ __('Render :engine\'s panels read-only to review their layout, without switching this server\'s webserver.', ['engine' => $info['label']]) }}
            @endif
        </p>
        <button
            type="button"
            wire:click="toggleEnginePanelPreview"
            @class([
                'inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md px-2 text-xs font-semibold shadow-sm transition',
                'border border-amber-300 bg-amber-100 text-amber-900 hover:bg-amber-200' => $isPanelPreview,
                'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => ! $isPanelPreview,
            ])
        >
            <x-heroicon-o-beaker class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ $isPanelPreview ? __('Exit preview') : __('Preview panels') }}
        </button>
    </div>
@endif

@include('livewire.servers.partials.webserver.engine._header-tabs')
@if (($isActive || $isEdgeProxyPanel) && ! $isPanelPreview)
    {{-- Skipped in preview: the engine isn't installed, so booting its sub-tab
         data would SSH for files and sockets that aren't there. --}}
    <div
        wire:key="engine-subtab-boot-{{ $key }}-{{ $engine_subtab }}"
        wire:init="loadActiveEngineSubtabData"
        class="hidden"
        aria-hidden="true"
    ></div>
@endif

@php
    // Skeleton shape per sub-tab. Overview / Logs / Config / Info have their own
    // layouts; every live-state sub-tab is a probe head + result table, so they
    // share one shape. Keys mirror the strip built in _header-tabs.
    $subtabShapes = ['overview' => 'overview', 'info' => 'info'];
    if ($hasControls) {
        $subtabShapes['logs'] = 'logs';
        $subtabShapes['config'] = 'config';
    }
    foreach ($tabsForThisEngine as $liveStateKey) {
        $subtabShapes[$liveStateKey] = 'live-state';
    }
    // Strip sub-tabs that aren't probe-backed still need a shape. nginx and
    // apache both carry a Cache tab in _header-tabs' strip while the live-state
    // map above omits it (it's a config form, not a probe table) — without an
    // entry here the swap painted nothing at all and the panel just went blank
    // until the response landed.
    if ($hasControls && in_array($key, ['nginx', 'apache'], true)) {
        $subtabShapes['cache'] = 'form';
    }
    // Same drift on Caddy: its strip carries Snippets (a Caddyfile block editor)
    // and Modules (an xcaddy plugin inventory), neither of which is in the
    // live-state map above.
    if (($isActive || $isEdgeProxyPanel) && $key === 'caddy') {
        $subtabShapes['snippets'] = 'form';
        $subtabShapes['modules'] = 'live-state';
    }
@endphp

@if ($optimisticEngineSubtabs)
    {{-- Skeleton swap on sub-tab switch. Only rendered on the optimistic path,
         where Alpine's `subtab` already holds the incoming key — without it
         there's nothing to shape the skeleton with. --}}
    @include('livewire.servers.partials.webserver.engine._subtab-skeleton', [
        'key' => $key,
        'subtabShapes' => $subtabShapes,
    ])
@endif

<div @if ($optimisticEngineSubtabs) wire:loading.class="hidden" wire:target="engine_subtab,setEngineSubtab" @endif>
@if ($isComingSoon)
    @include('livewire.servers.partials.webserver.engine._coming-soon')
    @include('livewire.servers.partials.webserver.engine._info')
@else
    @include('livewire.servers.partials.webserver.engine._overview')
    @include('livewire.servers.partials.webserver.engine._logs')
    @include('livewire.servers.partials.webserver.engine._config')
    @if ($nginxLiveStateComingSoon)
        @include('livewire.servers.partials.webserver.engine._nginx-live-state-coming-soon')
    @else
        @switch($key)
            @case('caddy')
                @include('livewire.servers.partials.webserver.engine.caddy')
                @break
            @case('haproxy')
                @include('livewire.servers.partials.webserver.engine.haproxy')
                @break
            @case('envoy')
                @include('livewire.servers.partials.webserver.engine.envoy')
                @break
            @case('openresty')
                @include('livewire.servers.partials.webserver.engine.openresty')
                @break
            @case('traefik')
                @include('livewire.servers.partials.webserver.engine.traefik')
                @break
            @case('apache')
                @include('livewire.servers.partials.webserver.engine.apache')
                @break
            @case('nginx')
                @include('livewire.servers.partials.webserver.engine.nginx')
                @break
            @case('openlitespeed')
                @include('livewire.servers.partials.webserver.engine.openlitespeed')
                @break
        @endswitch
        @include('livewire.servers.partials.webserver.engine._live-state-table')
    @endif
    @include('livewire.servers.partials.webserver.engine._info')
@endif
</div>
@if ($optimisticEngineSubtabs ?? false)
    </div>
@endif
