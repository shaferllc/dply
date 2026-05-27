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
        'openlitespeed' => ['vhosts', 'listeners', 'extapps', 'cache'],
        'caddy' => ['routes', 'upstreams', 'certs', 'admin'],
        'nginx' => ['hosts', 'upstreams', 'certs', 'workers'],
        'apache' => ['vhosts', 'modules', 'certs', 'workers'],
        'traefik' => ['routers', 'services', 'middlewares', 'providers'],
        'haproxy' => ['frontends', 'backends', 'ssl', 'runtime'],
    ];
    $tabsForThisEngine = $liveStateTabsByEngine[$key] ?? [];
    $isLiveStateView = $isActive && in_array($engine_subtab, $tabsForThisEngine, true);
@endphp
@include('livewire.servers.partials.webserver.engine._header-tabs')
@include('livewire.servers.partials.webserver.engine._overview')
@include('livewire.servers.partials.webserver.engine._logs')
@include('livewire.servers.partials.webserver.engine._config')
@switch($key)
    @case('caddy')
        @include('livewire.servers.partials.webserver.engine.caddy')
        @break
    @case('haproxy')
        @include('livewire.servers.partials.webserver.engine.haproxy')
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
@include('livewire.servers.partials.webserver.engine._info')
