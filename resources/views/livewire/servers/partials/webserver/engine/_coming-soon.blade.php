@php
    // Coming-soon teaser shown in place of the actionable engine panel for
    // engines flagged `coming_soon` in the catalog (and not yet active).
    // No switch / lifecycle controls — this is a preview only. Uses the shared
    // <x-workspace-coming-soon> terminal-hero treatment so the look matches the
    // nginx live-state teaser, the database engine teasers, and every other
    // coming-soon surface across the app.
    $isEdgeProxyPreview = ! empty($info['is_edge_proxy']);

    $engineBlurb = match ($key) {
        'nginx' => __('Mature HTTP server + reverse proxy. Excellent static-file performance, predictable config, very low memory footprint. Default for most production deployments.'),
        'caddy' => __('Automatic HTTPS out of the box, simple Caddyfile syntax, HTTP/3 by default. Great for opinionated setups where you want sensible defaults over fine-grained tuning.'),
        'apache' => __('Battle-tested with the broadest module catalog and per-directory `.htaccess` support. Higher per-request footprint than nginx but unbeatable compatibility with legacy stacks.'),
        'openlitespeed' => __('LSAPI for the fastest PHP execution, built-in LSCache module with per-vhost cache rules, and a familiar Apache-style config. The standard pick for WordPress-heavy hosting.'),
        'traefik' => __('Cloud-native L7 reverse proxy with automatic service discovery, middleware chains, and a dashboard API. Sits in front of Caddy site backends on ephemeral high ports.'),
        'haproxy' => __('Battle-tested load balancer and ACL router with fine-grained frontend/backend rules. Ideal when you need sticky sessions, health checks, or classic HAProxy config patterns.'),
        'envoy' => __('Cloud-native L7 proxy with dynamic listeners/clusters, rich metrics, and gRPC-friendly routing. Would sit on :80 in front of Caddy backends when install ships.'),
        'openresty' => __('nginx + LuaJIT at the edge for programmable auth, rate limits, and custom routing — separate from choosing nginx as the primary webserver.'),
        default => '',
    };

    // Feature cards. Icons are heroicon-o-* leaves (the component prepends
    // `heroicon-o-`), mirroring <x-workspace-coming-soon>'s expected shape.
    $engineHighlights = match ($key) {
        'nginx' => [
            ['icon' => 'bolt', 'title' => __('FastCGI cache'), 'body' => __('RunCloud-style page caching with shared FastCGI/proxy zones.')],
            ['icon' => 'server', 'title' => __('Upstreams'), 'body' => __('Load-balanced backend pools in nginx.conf.')],
            ['icon' => 'puzzle-piece', 'title' => __('Dynamic modules'), 'body' => __('Install and enable libnginx-mod-* packages from the dashboard.')],
            ['icon' => 'pencil-square', 'title' => __('Config editor'), 'body' => __('Edit and validate nginx config with backups.')],
        ],
        'caddy' => [
            ['icon' => 'lock-closed', 'title' => __('Automatic HTTPS'), 'body' => __('Certificates provisioned and renewed with zero config.')],
            ['icon' => 'arrow-path-rounded-square', 'title' => __('Route inspector'), 'body' => __('Live routes, upstreams, and certs from the admin API.')],
            ['icon' => 'code-bracket-square', 'title' => __('Caddyfile editor'), 'body' => __('In-app validate, format, and save with backups.')],
            ['icon' => 'bolt', 'title' => __('HTTP/3 default'), 'body' => __('Modern protocol support without extra tuning.')],
        ],
        'apache' => [
            ['icon' => 'puzzle-piece', 'title' => __('Module catalog'), 'body' => __('Browse and toggle loaded modules over SSH.')],
            ['icon' => 'server-stack', 'title' => __('Vhost inspector'), 'body' => __('See every virtual host and its document root.')],
            ['icon' => 'pencil-square', 'title' => __('Config editor'), 'body' => __('Edit and validate apache2 config with backups.')],
            ['icon' => 'lock-closed', 'title' => __('Certs & workers'), 'body' => __('Certificate inventory and MPM worker stats.')],
        ],
        'openlitespeed' => [
            ['icon' => 'server-stack', 'title' => __('Vhosts & listeners'), 'body' => __('Inspect virtual hosts, listeners, and external apps.')],
            ['icon' => 'bolt', 'title' => __('LSCache'), 'body' => __('Per-vhost cache rules for WordPress-heavy hosting.')],
            ['icon' => 'cpu-chip', 'title' => __('LSAPI execution'), 'body' => __('The fastest PHP execution path, managed in-app.')],
            ['icon' => 'pencil-square', 'title' => __('Config editor'), 'body' => __('Edit and validate OLS config with backups.')],
        ],
        'traefik' => [
            ['icon' => 'arrow-path-rounded-square', 'title' => __('Router inspector'), 'body' => __('Live routers, services, and middlewares from the API.')],
            ['icon' => 'server-stack', 'title' => __('Site backends'), 'body' => __('Route hostnames to Caddy backends on high ports.')],
            ['icon' => 'pencil-square', 'title' => __('Static config editor'), 'body' => __('Edit and validate traefik.yml with backups.')],
            ['icon' => 'shield-check', 'title' => __('TLS termination'), 'body' => __('Terminate HTTPS on :80 before site backends.')],
        ],
        'haproxy' => [
            ['icon' => 'scale', 'title' => __('Frontend / backend map'), 'body' => __('Inspect ACLs, stick tables, and backend health.')],
            ['icon' => 'server-stack', 'title' => __('Site routing'), 'body' => __('Host-based routing to Caddy backends on high ports.')],
            ['icon' => 'pencil-square', 'title' => __('Config editor'), 'body' => __('Edit and validate haproxy.cfg with backups.')],
            ['icon' => 'cpu-chip', 'title' => __('Runtime stats'), 'body' => __('Socket stats and runtime info from the server.')],
        ],
        'envoy' => [
            ['icon' => 'arrows-right-left', 'title' => __('Listeners & clusters'), 'body' => __('Dynamic xDS-style routing to Caddy backends.')],
            ['icon' => 'chart-bar', 'title' => __('Observability'), 'body' => __('Rich stats, access logs, and admin interface.')],
            ['icon' => 'server-stack', 'title' => __('Site routing'), 'body' => __('Host-based forwarding to per-site high ports.')],
            ['icon' => 'pencil-square', 'title' => __('Config editor'), 'body' => __('Validate and edit envoy.yaml with backups.')],
        ],
        'openresty' => [
            ['icon' => 'code-bracket-square', 'title' => __('Lua routing'), 'body' => __('Programmable edge logic without per-site nginx hand-edits.')],
            ['icon' => 'server-stack', 'title' => __('Site routing'), 'body' => __('Host maps to Caddy backends on high ports.')],
            ['icon' => 'shield-check', 'title' => __('Edge auth & limits'), 'body' => __('JWT gates, ACLs, and rate limits at :80.')],
            ['icon' => 'pencil-square', 'title' => __('Config editor'), 'body' => __('Edit nginx/OpenResty configs with validate + backup.')],
        ],
        default => [],
    };

    // Terminal-hero output lines per engine.
    $engineLines = match ($key) {
        'nginx' => [
            ['tone' => 'cmd', 'text' => '~ $ nginx -v'],
            ['tone' => 'muted', 'text' => 'nginx/1.27.0'],
            ['tone' => 'muted', 'text' => 'systemctl enable --now nginx'],
            ['tone' => 'ok', 'text' => 'listening on :80 / :443'],
        ],
        'caddy' => [
            ['tone' => 'cmd', 'text' => '~ $ caddy version'],
            ['tone' => 'muted', 'text' => 'v2.8.4'],
            ['tone' => 'muted', 'text' => 'automatic HTTPS · example.com'],
            ['tone' => 'ok', 'text' => 'serving · HTTP/3 enabled'],
        ],
        'apache' => [
            ['tone' => 'cmd', 'text' => '~ $ apachectl -v'],
            ['tone' => 'muted', 'text' => 'Server version: Apache/2.4'],
            ['tone' => 'muted', 'text' => 'a2enmod rewrite ssl headers'],
            ['tone' => 'ok', 'text' => 'mpm_event · :80 ready'],
        ],
        'openlitespeed' => [
            ['tone' => 'cmd', 'text' => '~ $ lswsctrl status'],
            ['tone' => 'muted', 'text' => 'litespeed is running'],
            ['tone' => 'muted', 'text' => 'LSAPI lsphp82 · LSCache enabled'],
            ['tone' => 'ok', 'text' => 'WebAdmin :7080 · HTTP :80'],
        ],
        'traefik' => [
            ['tone' => 'cmd', 'text' => '~ $ traefik version'],
            ['tone' => 'muted', 'text' => 'Version: 3.1'],
            ['tone' => 'muted', 'text' => 'entrypoint web :80 → caddy backends'],
            ['tone' => 'ok', 'text' => 'dashboard ready · 6 routers'],
        ],
        'haproxy' => [
            ['tone' => 'cmd', 'text' => '~ $ haproxy -vv'],
            ['tone' => 'muted', 'text' => 'HAProxy version 3.0'],
            ['tone' => 'muted', 'text' => 'frontend fe_http bind :80'],
            ['tone' => 'ok', 'text' => '2 backends · health checks up'],
        ],
        'envoy' => [
            ['tone' => 'cmd', 'text' => '~ $ envoy --version'],
            ['tone' => 'muted', 'text' => 'envoy 1.31'],
            ['tone' => 'muted', 'text' => 'listener :80 → cluster caddy_backends'],
            ['tone' => 'ok', 'text' => 'admin :9901 · 4 clusters'],
        ],
        'openresty' => [
            ['tone' => 'cmd', 'text' => '~ $ openresty -v'],
            ['tone' => 'muted', 'text' => 'openresty/1.25'],
            ['tone' => 'muted', 'text' => 'access_by_lua · rate-limit'],
            ['tone' => 'ok', 'text' => 'edge :80 · LuaJIT ready'],
        ],
        default => [
            ['tone' => 'cmd', 'text' => '~ $ dply webserver switch '.$key],
            ['tone' => 'muted', 'text' => 'Provisioning over SSH …'],
            ['tone' => 'ok', 'text' => 'webserver ready'],
        ],
    };
@endphp

@if ($engine_subtab !== 'info')
    <div wire:key="webserver-coming-soon-{{ $key }}">
        <x-workspace-coming-soon
            :server="$server"
            :icon="$info['icon']"
            :title="$info['label']"
            :description="$engineBlurb !== '' ? $engineBlurb : null"
            :eyebrow="$isEdgeProxyPreview ? __(':engine edge-proxy preview', ['engine' => $info['label']]) : __(':engine preview', ['engine' => $info['label']])"
            :heroNote="$isEdgeProxyPreview
                ? __('Install and lifecycle controls for :engine land on :server when it ships.', ['engine' => $info['label'], 'server' => $server->name])
                : __('Switching :server to :engine lands here when it ships.', ['engine' => $info['label'], 'server' => $server->name])"
            :lines="$engineLines"
            :features="$engineHighlights"
            :footnote="$isEdgeProxyPreview
                ? __(':engine install and lifecycle controls are on the way — add/remove will land here when it ships.', ['engine' => $info['label']])
                : __(':engine support is on the way — switching will land here when it ships.', ['engine' => $info['label']])"
        />
    </div>
@endif
