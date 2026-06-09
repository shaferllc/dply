@php
    // Per-sub-tab "coming soon" teaser for the nginx live-state strip
    // (Hosts / Upstreams / Certs / Modules / Cache / Workers). Rendered in
    // place of the real config panels + live-state table while these surfaces
    // are still being finished — see engine-panel.blade.php ($nginxLiveStateComingSoon).
    // Reuses the shared <x-workspace-coming-soon> treatment used by full
    // workspace pages so the look matches everywhere.
    $cs = match ($engine_subtab) {
        'hosts' => [
            'icon' => 'heroicon-o-server-stack',
            'title' => __('Custom nginx hosts'),
            'description' => __('Add standalone `server {}` blocks as `dply-custom-*.conf` for legacy hostnames and ad-hoc vhosts — edited and validated from the dashboard, separate from managed site vhosts.'),
            'eyebrow' => __('nginx hosts preview'),
            'lines' => [
                ['tone' => 'cmd', 'text' => '~ $ nginx -T | grep server_name'],
                ['tone' => 'muted', 'text' => 'server_name api.example.com;'],
                ['tone' => 'muted', 'text' => 'root /home/dply/api/public;'],
                ['tone' => 'ok', 'text' => '3 custom hosts · nginx -t passed'],
            ],
            'features' => [
                ['icon' => 'plus-circle', 'title' => __('Ad-hoc vhosts'), 'body' => __('Standalone server blocks, separate from managed sites.')],
                ['icon' => 'pencil-square', 'title' => __('Validated edits'), 'body' => __('Every save runs `nginx -t` and auto-reverts on failure.')],
                ['icon' => 'link', 'title' => __('Upstream wiring'), 'body' => __('fastcgi_pass / proxy_pass to PHP sockets or pools.')],
                ['icon' => 'arrow-path', 'title' => __('Live reload'), 'body' => __('Reload on change with zero downtime.')],
            ],
        ],
        'upstreams' => [
            'icon' => 'heroicon-o-server',
            'title' => __('nginx upstreams'),
            'description' => __('Reusable `upstream` pools at the http level of nginx.conf with weights, health checks, and backup servers — referenced by sites via `proxy_pass` / `fastcgi_pass`.'),
            'eyebrow' => __('nginx upstreams preview'),
            'lines' => [
                ['tone' => 'cmd', 'text' => '~ $ cat /etc/nginx/nginx.conf'],
                ['tone' => 'muted', 'text' => 'upstream app_backend {'],
                ['tone' => 'muted', 'text' => '  server 127.0.0.1:8081 weight=2;'],
                ['tone' => 'muted', 'text' => '  server 127.0.0.1:8082 backup;'],
                ['tone' => 'ok', 'text' => '2 pools · least_conn balancing'],
            ],
            'features' => [
                ['icon' => 'server', 'title' => __('Backend pools'), 'body' => __('Group servers behind a name your sites can reference.')],
                ['icon' => 'scale', 'title' => __('Weights & balancing'), 'body' => __('Tune weight, max_fails, and balancing per pool.')],
                ['icon' => 'heart', 'title' => __('Health & backup'), 'body' => __('Mark backup/down servers and fail-timeouts.')],
                ['icon' => 'pencil-square', 'title' => __('Validated edits'), 'body' => __('Save runs `nginx -t` and reloads safely.')],
            ],
        ],
        'certs' => [
            'icon' => 'heroicon-o-lock-closed',
            'title' => __('nginx certificates'),
            'description' => __('Inventory the TLS certificates nginx serves — issuers, SANs, and expiry windows — read straight from the running configuration.'),
            'eyebrow' => __('nginx certs preview'),
            'lines' => [
                ['tone' => 'cmd', 'text' => '~ $ openssl x509 -in cert.pem -noout -dates'],
                ['tone' => 'muted', 'text' => 'subject=CN=example.com'],
                ['tone' => 'muted', 'text' => 'notAfter=Aug 14 2026 GMT'],
                ['tone' => 'ok', 'text' => '4 certs · earliest expiry in 68 days'],
            ],
            'features' => [
                ['icon' => 'lock-closed', 'title' => __('Cert inventory'), 'body' => __('Every certificate nginx is serving, in one list.')],
                ['icon' => 'identification', 'title' => __('Issuers & SANs'), 'body' => __('See the issuer and every name on each cert.')],
                ['icon' => 'clock', 'title' => __('Expiry windows'), 'body' => __('Spot certificates nearing renewal at a glance.')],
                ['icon' => 'shield-check', 'title' => __('Chain checks'), 'body' => __('Surface mismatched or incomplete chains.')],
            ],
        ],
        'modules' => [
            'icon' => 'heroicon-o-puzzle-piece',
            'title' => __('nginx dynamic modules'),
            'description' => __('Install `libnginx-mod-*` packages and toggle loadable modules via modules-enabled — the same workflow as Debian/Ubuntu dynamic modules, driven from the dashboard.'),
            'eyebrow' => __('nginx modules preview'),
            'lines' => [
                ['tone' => 'cmd', 'text' => '~ $ apt-get install libnginx-mod-http-geoip2'],
                ['tone' => 'muted', 'text' => 'ln -s ../modules-available/… modules-enabled/'],
                ['tone' => 'muted', 'text' => 'nginx -t && systemctl reload nginx'],
                ['tone' => 'ok', 'text' => '12 dynamic modules · 5 enabled'],
            ],
            'features' => [
                ['icon' => 'puzzle-piece', 'title' => __('Install & enable'), 'body' => __('Pull libnginx-mod-* packages and load them.')],
                ['icon' => 'adjustments-horizontal', 'title' => __('Filter by type'), 'body' => __('TLS, stream, geo, security, observability, more.')],
                ['icon' => 'shield-exclamation', 'title' => __('Protected modules'), 'body' => __('dply-required modules can\'t be disabled by accident.')],
                ['icon' => 'arrow-path', 'title' => __('Safe reload'), 'body' => __('Failed `nginx -t` auto-reverts the symlink.')],
            ],
        ],
        'cache' => [
            'icon' => 'heroicon-o-bolt',
            'title' => __('nginx FastCGI / proxy cache'),
            'description' => __('Shared FastCGI and proxy cache zones tuned from the dashboard, with one-click purge — pair it with per-site engine HTTP caching in Sites → Caching.'),
            'eyebrow' => __('nginx cache preview'),
            'lines' => [
                ['tone' => 'cmd', 'text' => '~ $ nginx -T | grep fastcgi_cache_path'],
                ['tone' => 'muted', 'text' => 'keys_zone=dply_fcgi:64m  max_size=2g'],
                ['tone' => 'muted', 'text' => 'inactive=60m  use_temp_path=off'],
                ['tone' => 'ok', 'text' => 'HIT ratio 91% · purge ready'],
            ],
            'features' => [
                ['icon' => 'bolt', 'title' => __('Shared cache zones'), 'body' => __('FastCGI + proxy zones with size and TTL tuning.')],
                ['icon' => 'trash', 'title' => __('One-click purge'), 'body' => __('Drop cache files and send PURGE to local vhosts.')],
                ['icon' => 'chart-bar', 'title' => __('Zone summary'), 'body' => __('See zone names, paths, and sizes at a glance.')],
                ['icon' => 'pencil-square', 'title' => __('Validated edits'), 'body' => __('Save runs `nginx -t` and reloads safely.')],
            ],
        ],
        'workers' => [
            'icon' => 'heroicon-o-cpu-chip',
            'title' => __('nginx workers & global options'),
            'description' => __('Tune the top of nginx.conf — worker counts and rlimits, the events block, and http defaults — without hand-editing files. Site blocks pass through untouched.'),
            'eyebrow' => __('nginx workers preview'),
            'lines' => [
                ['tone' => 'cmd', 'text' => '~ $ nginx -T | head'],
                ['tone' => 'muted', 'text' => 'worker_processes auto;'],
                ['tone' => 'muted', 'text' => 'events { worker_connections 4096; }'],
                ['tone' => 'ok', 'text' => 'globals saved · nginx reloaded'],
            ],
            'features' => [
                ['icon' => 'cpu-chip', 'title' => __('Worker tuning'), 'body' => __('worker_processes, rlimit_nofile, and connections.')],
                ['icon' => 'signal', 'title' => __('events block'), 'body' => __('Connection limits and multi-accept toggles.')],
                ['icon' => 'cog-6-tooth', 'title' => __('http defaults'), 'body' => __('Keepalive, timeouts, and buffer defaults.')],
                ['icon' => 'pencil-square', 'title' => __('Validated edits'), 'body' => __('Save runs `nginx -t` and auto-restores on failure.')],
            ],
        ],
        default => null,
    };
@endphp

@if ($cs)
    <div class="mb-6" wire:key="nginx-coming-soon-{{ $engine_subtab }}">
        <x-workspace-coming-soon
            :server="$server"
            :icon="$cs['icon']"
            :title="$cs['title']"
            :description="$cs['description']"
            :eyebrow="$cs['eyebrow']"
            :heroNote="__('This lands for :engine on :server when it ships.', ['engine' => $info['label'] ?? 'nginx', 'server' => $server->name])"
            :lines="$cs['lines']"
            :features="$cs['features']"
        />
    </div>
@endif
