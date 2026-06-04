<x-server-workspace-layout
    :server="$server"
    active="edge-proxy"
    :title="__('Edge proxy')"
    :description="__('Optional L7 reverse proxy in front of your sites — preview what is shipping next.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-workspace-coming-soon
        :server="$server"
        icon="heroicon-o-arrow-path-rounded-square"
        :title="__('Edge proxy')"
        :description="__('Put a managed edge proxy in front of your sites — Traefik, HAProxy, Envoy, or OpenResty — with host routing, TLS, and health checks configured from Dply.')"
        :eyebrow="__('Edge proxy preview')"
        :heroNote="__('The edge proxy will route traffic into :server when it ships.', ['server' => $server->name])"
        :lines="[
            ['tone' => 'cmd', 'text' => '~ $ dply edge routes'],
            ['tone' => 'muted', 'text' => 'ROUTE              UPSTREAM         TLS'],
            ['tone' => 'muted', 'text' => 'api.example.com    127.0.0.1:8080   auto'],
            ['tone' => 'muted', 'text' => 'app.example.com    127.0.0.1:3000   auto'],
            ['tone' => 'ok', 'text' => 'Traefik · 2 routers · automatic HTTPS'],
        ]"
        :features="[
            ['icon' => 'arrow-path-rounded-square', 'title' => __('Multi-engine'), 'body' => __('Choose Traefik, HAProxy, Envoy, or OpenResty as your edge.')],
            ['icon' => 'map', 'title' => __('Declarative routing'), 'body' => __('Map hostnames to upstreams with per-route TLS and headers.')],
            ['icon' => 'lock-closed', 'title' => __('Automatic HTTPS'), 'body' => __('Terminate TLS at the edge with auto-renewing certificates.')],
            ['icon' => 'heart', 'title' => __('Health & failover'), 'body' => __('Route around unhealthy upstreams with active health checks.')],
        ]"
    />
</x-server-workspace-layout>
