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

    // nginx live-state sub-tabs (Hosts / Upstreams / Certs / Modules / Cache /
    // Workers) are still being finished — show the shared coming-soon teaser in
    // place of their real config panels + live-state table. Overview / Logs /
    // Config / Info stay fully functional. The tabs remain clickable so the
    // roadmap stays discoverable. Listed explicitly (not via $isLiveStateView)
    // because the live-state dispatch map above omits nginx's `cache` tab.
    $nginxComingSoonSubtabs = ['hosts', 'upstreams', 'certs', 'modules', 'cache', 'workers'];
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
@include('livewire.servers.partials.webserver.engine._header-tabs')
@if ($isActive || $isEdgeProxyPanel)
    <div
        wire:key="engine-subtab-boot-{{ $key }}-{{ $engine_subtab }}"
        wire:init="loadActiveEngineSubtabData"
        class="hidden"
        aria-hidden="true"
    ></div>
@endif
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
@if ($optimisticEngineSubtabs ?? false)
    </div>
@endif
