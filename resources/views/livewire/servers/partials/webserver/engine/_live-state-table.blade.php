
            @if ($isLiveStateView || ($optimisticEngineSubtabs ?? false))
                @php
                    $liveStatePayload = data_get($server->meta ?? [], 'webserver_live_state.'.$key);
                    $liveState = \App\Services\Servers\LiveState\EngineLiveState::fromArray($liveStatePayload);
                    $liveUnits = $liveState?->units ?? [];
                    $liveCapturedAt = $liveState?->capturedAt;
                    $engineLabel = $info['label'] ?? ucfirst($key);
                    $subtabTitle = match ($engine_subtab) {
                        'vhosts' => __('Vhosts'),
                        'listeners' => __('Listeners'),
                        'extapps' => __('External Apps'),
                        'cache' => __('Cache'),
                        'routes' => __('Routes'),
                        'upstreams' => __('Upstreams'),
                        'certs' => __('Certs'),
                        'admin' => __('Admin'),
                        'hosts' => __('Hosts'),
                        'workers' => __('Workers'),
                        'modules' => __('Modules'),
                        'routers' => __('Routers'),
                        'services' => __('Services'),
                        'middlewares' => __('Middlewares'),
                        'entrypoints' => __('Entry points'),
                        'tcprouters' => __('TCP routers'),
                        'tcpservices' => __('TCP services'),
                        'udprouters' => __('UDP routers'),
                        'udpservices' => __('UDP services'),
                        'tls' => __('TLS'),
                        'providers' => __('Providers'),
                        'frontends' => __('Frontends'),
                        'backends' => __('Backends'),
                        'clusters' => __('Clusters'),
                        'ssl' => __('SSL'),
                        'runtime' => __('Runtime'),
                        'virtualhosts' => __('Virtual hosts'),
                        'stats' => __('Stats'),
                        'servers' => __('Servers'),
                        'static' => __('Static'),
                        default => $engine_subtab,
                    };
                    $subtabDescription = match ($key.'/'.$engine_subtab) {
                        'openlitespeed/vhosts' => __('Configured virtual hosts parsed from /usr/local/lsws/conf/vhosts/. Per-vhost PHP processor + SSL state + document root.'),
                        'openlitespeed/listeners' => __('Listener blocks from httpd_config.conf — which port serves which vhosts.'),
                        'openlitespeed/extapps' => __('External LSAPI / proxy app processors referenced by vhosts. Marks missing lsphpXX binaries.'),
                        'openlitespeed/cache' => __('LSCache hit counts and hit-rate per .rtreport file.'),
                        'caddy/routes' => __('Every route across http.servers.* — host matcher, listen addresses, and the handler chain (reverse_proxy/file_server/headers/etc.).'),
                        'caddy/upstreams' => __('Reverse-proxy backends with live health, request count, and consecutive fail count from /reverse_proxy/upstreams. Down PHP-FPM unix sockets can be repaired from this table.'),
                        'caddy/certs' => __('TLS automation policies + Caddy\'s local CA. Per-site issued certs live on disk under /var/lib/caddy/.local/share/caddy/certificates/.'),
                        'caddy/admin' => __('Caddy build version, admin endpoint state, listening sockets, and the size of the active config payload.'),
                        'nginx/hosts' => __('Every server block from `nginx -T` with its server_name, listen, root, and first fastcgi_pass/proxy_pass upstream.'),
                        'nginx/upstreams' => __('Every `upstream` block — name + member servers (host:port).'),
                        'nginx/certs' => __('ssl_certificate paths across all server blocks with the openssl-derived expiry.'),
                        'nginx/modules' => __('Built-in modules from `nginx -V` plus dynamic modules loaded via modules-enabled.'),
                        'nginx/workers' => __('Active connections + accepts/handled/requests counters from the stub_status endpoint (127.0.0.1:9091).'),
                        'apache/vhosts' => __('Vhost map from `apachectl -S` — ServerName, port, config file:line, and any ServerAlias.'),
                        'apache/modules' => __('Loaded modules from `apachectl -M` with static/shared kind.'),
                        'apache/certs' => __('SSLCertificateFile paths across enabled sites + openssl-derived expiry.'),
                        'apache/workers' => __('mod_status counters — busy/idle workers, request rate, total accesses (127.0.0.1:9092).'),
                        'traefik/routers' => __('HTTP routers from /api/http/routers — rule, service, middleware chain, entry-points, status, provider.'),
                        'traefik/services' => __('HTTP services from /api/http/services — load balancer servers + server status.'),
                        'traefik/middlewares' => __('HTTP middlewares from /api/http/middlewares — basicAuth, headers, redirects, rate limits, etc.'),
                        'traefik/entrypoints' => __('Entry points from /api/entrypoints — listen addresses and transport (HTTP/TCP/UDP).'),
                        'traefik/tcprouters' => __('TCP routers from /api/tcp/routers — HostSNI rules and backend services.'),
                        'traefik/tcpservices' => __('TCP services from /api/tcp/services — load balancer targets.'),
                        'traefik/udprouters' => __('UDP routers from /api/udp/routers — packet routing rules.'),
                        'traefik/udpservices' => __('UDP services from /api/udp/services — load balancer targets.'),
                        'traefik/tls' => __('TLS certificate stores from /api/tls/stores.'),
                        'traefik/providers' => __('Config providers contributing routers/services (file, docker, kubernetes, etc.).'),
                        'haproxy/frontends' => __('Per-frontend stats from `show stat` — current sessions, total, rate, 2xx/5xx counts.'),
                        'haproxy/backends' => __('Per-backend rollup with member servers + per-server health check status.'),
                        'haproxy/ssl' => __('Loaded SSL certificate paths from `show ssl cert`.'),
                        'haproxy/runtime' => __('Runtime info from `show info` + memory pool summary from `show pools`.'),
                        'envoy/listeners' => __('HTTP listeners from the admin API — bind addresses and names.'),
                        'envoy/virtualhosts' => __('Host → cluster routing from the live config dump — one virtual host per site plus the catch-all.'),
                        'envoy/clusters' => __('Upstream clusters with host health from /clusters?format=json.'),
                        'envoy/stats' => __('Per-cluster request and error counters from /stats/prometheus.'),
                        'envoy/runtime' => __('Process info from /server_info — version, uptime, connections.'),
                        'openresty/servers' => __('Server blocks from `openresty -T` — server_name, listen, and proxy_pass upstream.'),
                        'openresty/upstreams' => __('Upstream pools from the flattened config — member servers per pool.'),
                        'openresty/runtime' => __('Build version and stub_status connection counters from localhost.'),
                        default => '',
                    };
                @endphp
                <div
                    @if ($optimisticEngineSubtabs ?? false)
                        x-show="@js($liveStateTabKeys).includes(subtab)"
                        x-cloak
                    @endif
                    class="{{ $card }}"
                    wire:key="livestate-{{ $key }}-{{ $engine_subtab }}"
                >
                    <div class="flex flex-wrap items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-table-cells class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Live state') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">
                                {{ $engineLabel }} — {{ $subtabTitle }}
                            </h3>
                            @if ($subtabDescription !== '')
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $subtabDescription }}</p>
                            @endif
                            @if ($liveCapturedAt)
                                <p class="mt-1 text-[11px] tabular-nums text-brand-mist">
                                    {{ __('As of :time', ['time' => $liveCapturedAt->diffForHumans()]) }}
                                    <span
                                        wire:loading
                                        wire:target="refreshEngineLiveState,setEngineSubtab,setWorkspaceTab,repairCaddyPhpFpmUpstream,confirmActionModal"
                                        class="ml-1 inline-flex items-center gap-1 text-brand-forest"
                                    >
                                        <x-spinner variant="forest" class="h-3 w-3" /> {{ __('Refreshing…') }}
                                    </span>
                                    <span wire:loading.remove wire:target="refreshEngineLiveState,setEngineSubtab,setWorkspaceTab,repairCaddyPhpFpmUpstream,confirmActionModal">
                                        @if ($liveState && ! $liveState->isFresh)
                                            <span class="ml-1 inline-flex items-center rounded-full bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold text-sky-800 ring-1 ring-sky-200">{{ __('Cached') }}</span>
                                        @endif
                                    </span>
                                </p>
                            @else
                                <p class="mt-1 inline-flex items-center gap-1 text-[11px] text-brand-forest">
                                    <span
                                        wire:loading
                                        wire:target="refreshEngineLiveState,setEngineSubtab,setWorkspaceTab,repairCaddyPhpFpmUpstream,confirmActionModal"
                                        class="inline-flex items-center gap-1"
                                    >
                                        <x-spinner variant="forest" class="h-3 w-3" /> {{ __('Probing server…') }}
                                    </span>
                                </p>
                            @endif
                        </div>
                        <button
                            type="button"
                            wire:click="refreshEngineLiveState"
                            wire:loading.attr="disabled"
                            wire:target="refreshEngineLiveState"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="refreshEngineLiveState" class="inline-flex">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            </span>
                            <span wire:loading wire:target="refreshEngineLiveState" class="inline-flex">
                                <x-spinner variant="forest" size="sm" />
                            </span>
                            {{ __('Refresh now') }}
                        </button>
                    </div>

                    @php
                        $rows = $liveUnits[$engine_subtab] ?? [];
                        $liveStateProbed = $liveState !== null && $liveCapturedAt !== null;
                        $liveStateStandby = (bool) data_get($liveState?->engineSpecific ?? [], 'standby', false);
                        $liveStateStandbyReason = (string) data_get($liveState?->engineSpecific ?? [], 'standby_reason', '');
                        $liveStateErrors = \App\Services\Servers\LiveState\EngineLiveState::probeErrorLines(
                            data_get($liveState?->engineSpecific ?? [], 'errors', []),
                        );
                        $liveStateEmptyMessage = match ($key.'/'.$engine_subtab) {
                            'nginx/hosts' => __('No server blocks found in `nginx -T`.'),
                            'nginx/upstreams' => __('No `upstream` blocks found in the flattened nginx config.'),
                            'nginx/certs' => __('No SSL certificates found — no server block declares ssl_certificate.'),
                            'nginx/modules' => __('No modules reported — run `nginx -V` or enable dynamic modules.'),
                            'nginx/workers' => __('stub_status is unreachable on 127.0.0.1:9091.'),
                            'apache/vhosts' => __('No custom vhosts yet — use Add vhost above, or create a site from the Sites workspace.'),
                            'apache/modules' => __('No loaded modules reported by `apachectl -M`.'),
                            'apache/certs' => __('No SSLCertificateFile paths found in enabled sites.'),
                            'apache/workers' => __('mod_status is unreachable on 127.0.0.1:9092.'),
                            'caddy/routes' => __('No routes returned from the Caddy admin API — add a custom route above or create a site.'),
                            'caddy/upstreams', 'caddy/certs', 'caddy/admin' => __('Nothing matched for this Caddy view.'),
                            'openlitespeed/vhosts' => __('No virtual hosts found under /usr/local/lsws/conf/vhosts/.'),
                            'openlitespeed/listeners' => __('No listener blocks found in httpd_config.conf.'),
                            'openlitespeed/extapps' => __('No external applications referenced by vhosts.'),
                            'openlitespeed/cache' => __('No LSCache rtreport data found.'),
                            'traefik/routers', 'traefik/services', 'traefik/middlewares', 'traefik/entrypoints',
                            'traefik/entrypoints' => __('No entry points from the API — use Manage entry points above (reads traefik.yml).'),
                            'traefik/providers' => __('No routers or services are registered yet. Enable providers above, add custom routers, or provision sites — then refresh.'),
                            'traefik/tcprouters', 'traefik/tcpservices', 'traefik/udprouters', 'traefik/udpservices', 'traefik/tls' => __('Nothing returned from the Traefik API for this view.'),
                            'haproxy/frontends', 'haproxy/backends', 'haproxy/ssl', 'haproxy/runtime' => __('Nothing returned from HAProxy stats for this view.'),
                            'envoy/listeners', 'envoy/virtualhosts', 'envoy/clusters', 'envoy/stats', 'envoy/runtime' => __('Nothing returned from the Envoy admin API for this view.'),
                            'openresty/servers', 'openresty/upstreams', 'openresty/runtime' => __('Nothing returned from the OpenResty probe for this view.'),
                            default => __('Nothing to show for this view.'),
                        };
                        // For the vhosts table, resolve each row name back to a Site so
                        // we can render a "Manage on site" link (PHP version, SSL, env, …
                        // all live there, not here). Names follow the
                        // `dply-<site_id>-<slug>` pattern emitted by Site::nginxConfigBasename().
                        $caddyDownPhpFpmUpstreams = [];
                        if ($key === 'caddy' && $engine_subtab === 'upstreams' && ! empty($rows)) {
                            foreach ($rows as $upstreamRow) {
                                $addr = (string) ($upstreamRow['address'] ?? '');
                                if (empty($upstreamRow['healthy']) && \App\Support\Servers\CaddyPhpFpmUpstreamAddress::isPhpFpmSocket($addr)) {
                                    $caddyDownPhpFpmUpstreams[] = $upstreamRow;
                                }
                            }
                        }
                        $repairCaddyPhpFpmAction = config('server_manage.service_actions.repair_caddy_php_fpm_upstream');
                        $sitesByVhostName = [];
                        $sitesByEnvoyVhost = [];
                        if ($engine_subtab === 'vhosts' && ! empty($rows) && $key === 'openlitespeed') {
                            $ids = [];
                            foreach ($rows as $row) {
                                if (preg_match('/^dply-([0-9a-z]+)-/i', (string) ($row['name'] ?? ''), $idMatch) === 1) {
                                    $ids[] = $idMatch[1];
                                }
                            }
                            if ($ids !== []) {
                                $sitesByVhostName = \App\Models\Site::query()
                                    ->whereIn('id', array_unique($ids))
                                    ->get(['id', 'slug'])
                                    ->keyBy(fn ($s) => 'dply-'.$s->id.'-'.$s->slug)
                                    ->all();
                            }
                        }
                        if ($engine_subtab === 'virtualhosts' && ! empty($rows) && $key === 'envoy') {
                            $ids = [];
                            foreach ($rows as $row) {
                                if (! empty($row['site_id'])) {
                                    $ids[] = (string) $row['site_id'];
                                }
                            }
                            if ($ids !== []) {
                                $sitesByEnvoyVhost = \App\Models\Site::query()
                                    ->whereIn('id', array_unique($ids))
                                    ->get(['id'])
                                    ->keyBy('id')
                                    ->all();
                            }
                        }
                    @endphp
                    @php
                        $liveStateWireLoadingTargets = 'refreshEngineLiveState,setEngineSubtab,setWorkspaceTab,repairCaddyPhpFpmUpstream,confirmActionModal';
                    @endphp
                    <div class="px-6 py-6 sm:px-7">
                    <div
                        wire:loading.block
                        wire:target="{{ $liveStateWireLoadingTargets }}"
                        class="mt-5 w-full rounded-xl border border-brand-ink/10 bg-white px-6 py-10 text-center text-sm text-brand-moss"
                    >
                        <x-spinner variant="forest" class="mx-auto h-5 w-5" />
                        <p class="mt-2">{{ __('Probing server…') }}</p>
                    </div>

                    <div wire:loading.remove wire:target="{{ $liveStateWireLoadingTargets }}">
                    @if ($caddyDownPhpFpmUpstreams !== [] && $opsReady && ! $isDeployer)
                        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50/80 px-5 py-4 text-sm text-amber-950">
                            <p class="font-semibold">{{ __('PHP-FPM upstream unreachable') }}</p>
                            <p class="mt-1 leading-relaxed">
                                {{ __('Caddy reports these unix PHP-FPM backends as down — usually php-fpm is stopped, the socket path does not match your sites, or the caddy user cannot read the socket. Repair starts the FPM service for the upstream socket (or the server default PHP when that version is missing). If Caddy still points at an old socket, update each site\'s PHP version and re-apply webserver config.') }}
                            </p>
                        </div>
                    @endif
                    @if ($liveStateStandby)
                        <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50/70 px-6 py-4 text-sm text-amber-950">
                            <p class="font-semibold">{{ __('Engine on standby') }}</p>
                            <p class="mt-1 leading-relaxed">{{ $liveStateStandbyReason !== '' ? $liveStateStandbyReason : __('This edge proxy is not running on this server.') }}</p>
                            <p class="mt-2 text-[13px] text-amber-900/80">{{ __('Live routing tables appear here when this engine is the active edge proxy on port :port.', ['port' => 80]) }}</p>
                        </div>
                    @elseif ($liveStateErrors !== [])
                        <div class="mt-5 rounded-xl border border-rose-200 bg-rose-50/70 px-6 py-4 text-sm text-rose-900">
                            <p class="font-semibold">{{ __('Probe reported problems') }}</p>
                            <ul class="mt-2 list-inside list-disc space-y-1 font-mono text-xs">
                                @foreach ($liveStateErrors as $err)
                                    <li>{{ $err }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @elseif ($engine_live_state_loading && empty($rows))
                        <div class="mt-5 w-full rounded-xl border border-brand-ink/10 bg-white px-6 py-10 text-center text-sm text-brand-moss">
                            <x-spinner variant="forest" class="mx-auto h-5 w-5" />
                            <p class="mt-2">{{ __('Probing server…') }}</p>
                        </div>
                    @elseif (! $liveStateProbed && empty($rows))
                        <div class="mt-5 rounded-xl border border-dashed border-brand-ink/15 bg-white px-6 py-10 text-center text-sm text-brand-moss">
                            <x-heroicon-o-signal-slash class="mx-auto h-5 w-5 text-brand-mist" />
                            <p class="mt-2">{{ __('No data yet — open this tab or click "Refresh now" to probe the server.') }}</p>
                        </div>
                    @elseif ($liveStateProbed && empty($rows))
                        <div class="mt-5 rounded-xl border border-brand-ink/10 bg-white px-6 py-10 text-center text-sm text-brand-moss">
                            <x-heroicon-o-check-circle class="mx-auto h-5 w-5 text-emerald-600" />
                            <p class="mt-2 font-medium text-brand-ink">{{ __('In sync — nothing to list') }}</p>
                            <p class="mt-1 max-w-md mx-auto text-[13px] leading-relaxed">{{ $liveStateEmptyMessage }}</p>
                        </div>
                    @else
                        <div class="mt-5 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-brand-sand/30 text-[11px] uppercase tracking-wide text-brand-mist">
                                    <tr>
                                        @switch($engine_subtab)
                                            @case('vhosts')
                                                @if ($key === 'apache')
                                                    <th class="px-4 py-2 font-medium">{{ __('Server name') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Port') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Aliases') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Config') }}</th>
                                                @else
                                                    <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Domains') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Doc root') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('PHP') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('SSL') }}</th>
                                                    <th class="px-4 py-2 font-medium text-right">{{ __('Manage') }}</th>
                                                @endif
                                                @break
                                            @case('listeners')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Address') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Secure') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Mapped vhosts') }}</th>
                                                @break
                                            @case('extapps')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Type') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Path') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('PHP') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                @break
                                            @case('cache')
                                                <th class="px-4 py-2 font-medium">{{ __('Source') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Public hits') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Private hits') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Static hits') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Hit rate') }}</th>
                                                @break
                                            @case('routes')
                                                <th class="px-4 py-2 font-medium">{{ __('Server') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Host') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Listen') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Handlers') }}</th>
                                                @break
                                            @case('upstreams')
                                                @if ($key === 'nginx')
                                                    <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Servers') }}</th>
                                                @else
                                                    <th class="px-4 py-2 font-medium">{{ __('Address') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Healthy') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Requests') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Fails') }}</th>
                                                    @if ($key === 'caddy')
                                                        <th class="px-4 py-2 font-medium text-right">{{ __('Actions') }}</th>
                                                    @endif
                                                @endif
                                                @break
                                            @case('certs')
                                                @if ($key === 'nginx')
                                                    <th class="px-4 py-2 font-medium">{{ __('Path') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Hosts') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Expiry') }}</th>
                                                @elseif ($key === 'apache')
                                                    <th class="px-4 py-2 font-medium">{{ __('Path') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Expiry') }}</th>
                                                @else
                                                    <th class="px-4 py-2 font-medium">{{ __('Kind') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Subjects') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Issuer') }}</th>
                                                    <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                @endif
                                                @break
                                            @case('admin')
                                                <th class="px-4 py-2 font-medium">{{ __('Key') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Value') }}</th>
                                                @break
                                            @case('hosts')
                                                <th class="px-4 py-2 font-medium">{{ __('Server names') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Listen') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Root') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Upstream') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('SSL') }}</th>
                                                @break
                                            @case('workers')
                                                <th class="px-4 py-2 font-medium">{{ __('Key') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Value') }}</th>
                                                @break
                                            @case('modules')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Kind') }}</th>
                                                @break
                                            @case('routers')
                                            @case('tcprouters')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Rule') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Service') }}</th>
                                                @if ($engine_subtab === 'routers')
                                                    <th class="px-4 py-2 font-medium">{{ __('Middlewares') }}</th>
                                                @endif
                                                <th class="px-4 py-2 font-medium">{{ __('Entry points') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                @break
                                            @case('entrypoints')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Address') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Transport') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                @break
                                            @case('tcpservices')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Servers') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Provider') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                @break
                                            @case('udprouters')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Service') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Entry points') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                @break
                                            @case('udpservices')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Servers') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Provider') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                @break
                                            @case('tls')
                                                <th class="px-4 py-2 font-medium">{{ __('Store') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Subject') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('SANs') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                @break
                                            @case('services')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Type') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Servers') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Provider') }}</th>
                                                @break
                                            @case('middlewares')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Type') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Provider') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                @break
                                            @case('providers')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Routers') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Services') }}</th>
                                                @break
                                            @case('frontends')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Sess (cur/max/tot)') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Rate') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('2xx / 5xx') }}</th>
                                                @break
                                            @case('backends')
                                                <th class="px-4 py-2 font-medium">{{ __('Backend') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Servers') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Sess (cur/tot)') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('5xx') }}</th>
                                                @break
                                            @case('clusters')
                                                <th class="px-4 py-2 font-medium">{{ __('Cluster') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Status') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Hosts') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Healthy (cur/tot)') }}</th>
                                                @break
                                            @case('virtualhosts')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Domains') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Cluster') }}</th>
                                                <th class="px-4 py-2 font-medium text-right">{{ __('Manage') }}</th>
                                                @break
                                            @case('servers')
                                                <th class="px-4 py-2 font-medium">{{ __('Name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('server_name') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Listen') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Upstream') }}</th>
                                                @break
                                            @case('stats')
                                                <th class="px-4 py-2 font-medium">{{ __('Cluster') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Requests') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('5xx') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Active conns') }}</th>
                                                @break
                                            @case('ssl')
                                                <th class="px-4 py-2 font-medium">{{ __('Path') }}</th>
                                                @break
                                            @case('runtime')
                                                <th class="px-4 py-2 font-medium">{{ __('Version') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Uptime') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Conns (cur/cum)') }}</th>
                                                <th class="px-4 py-2 font-medium">{{ __('Cum requests') }}</th>
                                                @break
                                        @endswitch
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/5">
                                    @foreach ($rows as $row)
                                        <tr>
                                            @switch($engine_subtab)
                                                @case('vhosts')
                                                    @if ($key === 'apache')
                                                        <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['server_name'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 tabular-nums text-xs">{{ $row['port'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['aliases'] ?? []) ?: '—' }}</td>
                                                        <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['config'] ?? '—' }}</td>
                                                    @else
                                                        @php $vhSite = $sitesByVhostName[(string) ($row['name'] ?? '')] ?? null; @endphp
                                                        <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['domains'] ?? []) ?: '—' }}</td>
                                                        <td class="px-4 py-2 font-mono text-xs text-brand-moss">{{ $row['doc_root'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 text-xs">{{ $row['php_version'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 text-xs">
                                                            @if (! empty($row['ssl']))
                                                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('on') }}</span>
                                                            @else
                                                                <span class="text-brand-mist">—</span>
                                                            @endif
                                                        </td>
                                                        <td class="px-4 py-2 text-right">
                                                            @if ($vhSite)
                                                                <a
                                                                    href="{{ route('sites.show', ['server' => $server, 'site' => $vhSite->id]) }}"
                                                                    class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                                    title="{{ __('PHP version, SSL, env, and other per-site settings are managed in the Site workspace.') }}"
                                                                >
                                                                    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" />
                                                                    {{ __('Open site') }}
                                                                </a>
                                                            @else
                                                                <span class="text-brand-mist text-[11px]">—</span>
                                                            @endif
                                                        </td>
                                                    @endif
                                                    @break
                                                @case('listeners')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-moss">{{ $row['address'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ ! empty($row['secure']) ? __('yes') : __('no') }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['vhosts'] ?? []) ?: '—' }}</td>
                                                    @break
                                                @case('extapps')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['type'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['path'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['php_version'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">
                                                        @if (! empty($row['installed']))
                                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('installed') }}</span>
                                                        @else
                                                            <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700">{{ __('missing') }}</span>
                                                        @endif
                                                    </td>
                                                    @break
                                                @case('cache')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['source'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['public_hits'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['private_hits'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['static_hits'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((float) ($row['hit_rate_pct'] ?? 0), 2) }}%</td>
                                                    @break
                                                @case('routes')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['server'] ?? 'srv0' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['host'] ?? []) ?: __('(catch-all)') }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ implode(', ', $row['listen'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(' → ', $row['handlers'] ?? []) ?: '—' }}</td>
                                                    @break
                                                @case('upstreams')
                                                    @if ($key === 'nginx' || $key === 'openresty')
                                                        <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ is_array($row['servers'] ?? null) ? implode(', ', $row['servers']) : '—' }}</td>
                                                    @else
                                                        @php
                                                            $upstreamAddress = (string) ($row['address'] ?? '');
                                                            $canRepairPhpFpm = $key === 'caddy'
                                                                && empty($row['healthy'])
                                                                && \App\Support\Servers\CaddyPhpFpmUpstreamAddress::isPhpFpmSocket($upstreamAddress);
                                                            $repairPhpVersions = $canRepairPhpFpm
                                                                ? \App\Support\Servers\CaddyPhpFpmUpstreamAddress::repairPhpVersions(
                                                                    $upstreamAddress,
                                                                    app(\App\Services\Servers\ServerPhpManager::class)->probeInstalledVersionIds($server),
                                                                    app(\App\Services\Servers\ServerPhpManager::class)->probeLatestInstalledVersion($server),
                                                                )
                                                                : null;
                                                            $repairModalDetails = [];
                                                            if (is_array($repairPhpVersions)) {
                                                                $repairModalDetails[] = ['label' => __('Upstream'), 'value' => $upstreamAddress, 'mono' => true];
                                                                if ($repairPhpVersions['upstream'] !== null) {
                                                                    $repairModalDetails[] = ['label' => __('Caddy config'), 'value' => 'php'.$repairPhpVersions['upstream'].'-fpm'];
                                                                }
                                                                $repairModalDetails[] = ['label' => __('Will use'), 'value' => 'php'.$repairPhpVersions['primary'].'-fpm'];
                                                                if ($repairPhpVersions['needs_config_update']) {
                                                                    $repairModalDetails[] = ['label' => __('Also rewrites Caddy configs'), 'value' => __('Yes — stale php:version sockets are updated to the latest installed PHP.')];
                                                                }
                                                            }
                                                        @endphp
                                                        <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $upstreamAddress !== '' ? $upstreamAddress : '—' }}</td>
                                                        <td class="px-4 py-2 text-xs">
                                                            @if (! empty($row['healthy']))
                                                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('healthy') }}</span>
                                                            @else
                                                                <span class="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold text-rose-700">{{ __('down') }}</span>
                                                            @endif
                                                        </td>
                                                        <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['num_requests'] ?? 0)) }}</td>
                                                        <td class="px-4 py-2 tabular-nums text-xs">
                                                            @php $fails = (int) ($row['fails'] ?? 0); @endphp
                                                            <span class="{{ $fails > 0 ? 'text-rose-700' : '' }}">{{ number_format($fails) }}</span>
                                                        </td>
                                                        @if ($key === 'caddy')
                                                            <td class="px-4 py-2 text-right text-xs">
                                                                @if ($canRepairPhpFpm && $opsReady && ! $isDeployer && is_array($repairCaddyPhpFpmAction))
                                                                    <button
                                                                        type="button"
                                                                        wire:click="openConfirmActionModal('repairCaddyPhpFpmUpstream', [@js($upstreamAddress)], @js(__('Repair PHP-FPM for Caddy')), @js($repairCaddyPhpFpmAction['confirm'] ?? ''), @js(__('Repair PHP-FPM')), false, @js($repairModalDetails))"
                                                                        wire:loading.attr="disabled"
                                                                        wire:target="openConfirmActionModal,repairCaddyPhpFpmUpstream,confirmActionModal"
                                                                        class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                                                    >
                                                                        <x-heroicon-o-wrench-screwdriver class="h-3.5 w-3.5" aria-hidden="true" />
                                                                        {{ __('Repair PHP-FPM') }}
                                                                    </button>
                                                                @else
                                                                    <span class="text-brand-mist">—</span>
                                                                @endif
                                                            </td>
                                                        @endif
                                                    @endif
                                                    @break
                                                @case('hosts')
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['server_names'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ implode(', ', $row['listen'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['root'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['upstream'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">
                                                        @if (! empty($row['ssl']))
                                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">{{ __('on') }}</span>
                                                        @else
                                                            <span class="text-brand-mist">—</span>
                                                        @endif
                                                    </td>
                                                    @break
                                                @case('workers')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['key'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-moss">{{ $row['value'] ?? '—' }}</td>
                                                    @break
                                                @case('modules')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">
                                                        @php $kind = (string) ($row['kind'] ?? ''); @endphp
                                                        <span @class([
                                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1',
                                                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => in_array($kind, ['static', 'builtin'], true),
                                                            'bg-sky-50 text-sky-700 ring-sky-200' => in_array($kind, ['shared', 'dynamic'], true),
                                                            'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => ! in_array($kind, ['static', 'shared', 'builtin', 'dynamic'], true),
                                                        ])>{{ $kind ?: '—' }}</span>
                                                    </td>
                                                    @break
                                                @case('certs')
                                                    @if ($key === 'nginx')
                                                        <td class="px-4 py-2 font-mono text-[11px] text-brand-moss break-all">{{ $row['path'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['hosts'] ?? []) ?: '—' }}</td>
                                                        <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['expiry'] ?? '—' }}</td>
                                                    @elseif ($key === 'apache')
                                                        <td class="px-4 py-2 font-mono text-[11px] text-brand-moss break-all">{{ $row['path'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['expiry'] ?? '—' }}</td>
                                                    @else
                                                        <td class="px-4 py-2 text-xs">
                                                            @php $kind = (string) ($row['kind'] ?? ''); @endphp
                                                            <span @class([
                                                                'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1',
                                                                'bg-sky-50 text-sky-700 ring-sky-200' => $kind === 'policy',
                                                                'bg-emerald-50 text-emerald-700 ring-emerald-200' => $kind === 'local_ca',
                                                                'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => ! in_array($kind, ['policy', 'local_ca'], true),
                                                            ])>{{ $kind ?: '—' }}</span>
                                                        </td>
                                                        <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['subjects'] ?? []) ?: '—' }}</td>
                                                        <td class="px-4 py-2 text-xs text-brand-moss">{{ $row['issuer'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 text-xs">{{ $row['status'] ?? '—' }}</td>
                                                    @endif
                                                    @break
                                                @case('admin')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['key'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-moss">{{ $row['value'] ?? '—' }}</td>
                                                    @break
                                                @case('routers')
                                                @case('tcprouters')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['rule'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['service'] ?? '—' }}</td>
                                                    @if ($engine_subtab === 'routers')
                                                        <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['middlewares'] ?? []) ?: '—' }}</td>
                                                    @endif
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['entry_points'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">
                                                        @php $st = strtoupper((string) ($row['status'] ?? '')); @endphp
                                                        <span @class([
                                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1',
                                                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $st === 'ENABLED',
                                                            'bg-amber-50 text-amber-700 ring-amber-200' => $st === 'WARNING',
                                                            'bg-rose-50 text-rose-700 ring-rose-200' => $st === 'DISABLED' || $st === 'ERROR',
                                                            'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => ! in_array($st, ['ENABLED', 'WARNING', 'DISABLED', 'ERROR'], true),
                                                        ])>{{ $st ?: '—' }}</span>
                                                    </td>
                                                    @break
                                                @case('entrypoints')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['address'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ $row['transport'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['status'] ?? '—' }}</td>
                                                    @break
                                                @case('tcpservices')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ implode(', ', $row['servers'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ $row['provider'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['status'] ?? '—' }}</td>
                                                    @break
                                                @case('udprouters')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['service'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['entry_points'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['status'] ?? '—' }}</td>
                                                    @break
                                                @case('udpservices')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ implode(', ', $row['servers'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ $row['provider'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['status'] ?? '—' }}</td>
                                                    @break
                                                @case('tls')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['store'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ $row['subject'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['sans'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['status'] ?? '—' }}</td>
                                                    @break
                                                @case('services')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['type'] ?? 'loadBalancer' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ implode(', ', $row['servers'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ $row['provider'] ?? '—' }}</td>
                                                    @break
                                                @case('middlewares')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['type'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ $row['provider'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">{{ $row['status'] ?? '—' }}</td>
                                                    @break
                                                @case('providers')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['router_count'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['service_count'] ?? 0)) }}</td>
                                                    @break
                                                @case('frontends')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">
                                                        @php $st = strtoupper((string) ($row['status'] ?? '')); @endphp
                                                        <span @class([
                                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1',
                                                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $st === 'OPEN' || $st === 'UP',
                                                            'bg-rose-50 text-rose-700 ring-rose-200' => $st === 'DOWN' || $st === 'STOP',
                                                            'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => ! in_array($st, ['OPEN', 'UP', 'DOWN', 'STOP'], true),
                                                        ])>{{ $st ?: '—' }}</span>
                                                    </td>
                                                    <td class="px-4 py-2 font-mono tabular-nums text-xs text-brand-moss">{{ ($row['sessions_current'] ?? 0).' / '.($row['sessions_max'] ?? 0).' / '.number_format((int) ($row['sessions_total'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ $row['rate'] ?? 0 }}/s</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['hrsp_2xx'] ?? 0)) }} / <span class="text-rose-700">{{ number_format((int) ($row['hrsp_5xx'] ?? 0)) }}</span></td>
                                                    @break
                                                @case('backends')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">
                                                        @php $st = strtoupper((string) ($row['status'] ?? '')); @endphp
                                                        <span @class([
                                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1',
                                                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $st === 'UP',
                                                            'bg-rose-50 text-rose-700 ring-rose-200' => str_contains($st, 'DOWN'),
                                                            'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => $st === '' || (! in_array($st, ['UP'], true) && ! str_contains($st, 'DOWN')),
                                                        ])>{{ $st ?: '—' }}</span>
                                                    </td>
                                                    <td class="px-4 py-2 text-[11px] text-brand-moss">
                                                        @foreach (($row['servers'] ?? []) as $srv)
                                                            <div class="inline-flex items-center gap-1 mr-2">
                                                                <span class="font-mono">{{ $srv['name'] }}</span>
                                                                <span @class([
                                                                    'text-[10px]',
                                                                    'text-emerald-700' => strtoupper($srv['status'] ?? '') === 'UP',
                                                                    'text-rose-700' => str_contains(strtoupper($srv['status'] ?? ''), 'DOWN'),
                                                                ])>{{ $srv['status'] ?? '' }}</span>
                                                            </div>
                                                        @endforeach
                                                        @if (empty($row['servers']))—@endif
                                                    </td>
                                                    <td class="px-4 py-2 font-mono tabular-nums text-xs text-brand-moss">{{ ($row['sessions_current'] ?? 0).' / '.number_format((int) ($row['sessions_total'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs text-rose-700">{{ number_format((int) ($row['hrsp_5xx'] ?? 0)) }}</td>
                                                    @break
                                                @case('clusters')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs">
                                                        @php $st = strtoupper((string) ($row['status'] ?? '')); @endphp
                                                        <span @class([
                                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase ring-1',
                                                            'bg-emerald-50 text-emerald-700 ring-emerald-200' => $st === 'UP',
                                                            'bg-amber-50 text-amber-800 ring-amber-200' => $st === 'DEGRADED',
                                                            'bg-rose-50 text-rose-700 ring-rose-200' => str_contains($st, 'DOWN'),
                                                            'bg-brand-sand/40 text-brand-moss ring-brand-ink/10' => ! in_array($st, ['UP', 'DEGRADED'], true) && ! str_contains($st, 'DOWN'),
                                                        ])>{{ $st ?: '—' }}</span>
                                                    </td>
                                                    <td class="px-4 py-2 text-[11px] text-brand-moss">
                                                        @foreach (($row['servers'] ?? []) as $srv)
                                                            <div class="inline-flex items-center gap-1 mr-2">
                                                                <span class="font-mono">{{ $srv['name'] }}</span>
                                                                <span @class([
                                                                    'text-[10px]',
                                                                    'text-emerald-700' => strtoupper($srv['status'] ?? '') === 'UP',
                                                                    'text-rose-700' => str_contains(strtoupper($srv['status'] ?? ''), 'DOWN'),
                                                                ])>{{ $srv['status'] ?? '' }}</span>
                                                            </div>
                                                        @endforeach
                                                        @if (empty($row['servers']))—@endif
                                                    </td>
                                                    <td class="px-4 py-2 font-mono tabular-nums text-xs text-brand-moss">{{ ($row['sessions_current'] ?? 0).' / '.number_format((int) ($row['sessions_total'] ?? 0)) }}</td>
                                                    @break
                                                @case('virtualhosts')
                                                    @php $envoySite = ! empty($row['site_id']) ? ($sitesByEnvoyVhost[(string) $row['site_id']] ?? null) : null; @endphp
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ implode(', ', $row['domains'] ?? []) ?: '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['cluster'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-right">
                                                        @if ($envoySite)
                                                            <a
                                                                href="{{ route('sites.show', ['server' => $server, 'site' => $envoySite->id]) }}"
                                                                class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                            >
                                                                <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" />
                                                                {{ __('Open site') }}
                                                            </a>
                                                        @else
                                                            <span class="text-brand-mist text-[11px]">—</span>
                                                        @endif
                                                    </td>
                                                    @break
                                                @case('servers')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 text-xs text-brand-moss">{{ $row['server_names'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['listen'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 font-mono text-[11px] text-brand-moss">{{ $row['upstream'] ?? '—' }}</td>
                                                    @break
                                                @case('stats')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['name'] ?? '—' }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['requests'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs text-rose-700">{{ number_format((int) ($row['errors_5xx'] ?? 0)) }}</td>
                                                    <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['connections_active'] ?? 0)) }}</td>
                                                    @break
                                                @case('ssl')
                                                    <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['path'] ?? '—' }}</td>
                                                    @break
                                                @case('runtime')
                                                    @if ($key === 'openresty')
                                                        <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['version'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 tabular-nums text-xs">{{ $row['active_connections'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 tabular-nums text-xs">{{ ($row['reading'] ?? '—').' / '.($row['writing'] ?? '—').' / '.($row['waiting'] ?? '—') }}</td>
                                                        <td class="px-4 py-2 text-xs text-brand-mist">—</td>
                                                    @else
                                                        <td class="px-4 py-2 font-mono text-xs text-brand-ink">{{ $row['version'] ?? '—' }}</td>
                                                        <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['uptime_sec'] ?? 0)) }}s</td>
                                                        <td class="px-4 py-2 font-mono tabular-nums text-xs">{{ ($row['current_conns'] ?? 0).' / '.number_format((int) ($row['cum_conns'] ?? 0)) }}</td>
                                                        <td class="px-4 py-2 tabular-nums text-xs">{{ number_format((int) ($row['cum_req'] ?? 0)) }}</td>
                                                    @endif
                                                    @break
                                            @endswitch
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    </div>{{-- wire:loading.remove --}}
                    </div>
                </div>
            @endif
