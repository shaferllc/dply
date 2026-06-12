<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\Server;
use App\Services\Servers\LiveState\ApacheLiveStateProbe;
use App\Services\Servers\LiveState\CaddyLiveStateProbe;
use App\Services\Servers\LiveState\EngineLiveStateProbe;
use App\Services\Servers\LiveState\EnvoyLiveStateProbe;
use App\Services\Servers\LiveState\HaproxyLiveStateProbe;
use App\Services\Servers\LiveState\NginxLiveStateProbe;
use App\Services\Servers\LiveState\OlsLiveStateProbe;
use App\Services\Servers\LiveState\OpenRestyLiveStateProbe;
use App\Services\Servers\LiveState\TraefikLiveStateProbe;

/**
 * Concern extracted from {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component. Every public property/method name
 * is unchanged, so Livewire snapshots and wire:* bindings keep resolving against
 * the composed class.
 */
trait ManagesWebserverLiveState
{
    public bool $engine_live_state_loading = false;

    /**
     * Deferred loader for the active engine sub-tab. Wired from wire:init so
     * {@see setEngineSubtab()} can paint the tab highlight before SSH work.
     */
    public function loadActiveEngineSubtabData(): void
    {
        if (! $this->serverOpsReady()) {
            return;
        }

        $tab = $this->workspace_tab;
        $sub = $this->engine_subtab;

        if ($tab === 'openlitespeed' && $sub === 'cache' && ! $this->ols_cache_loaded) {
            $this->loadOlsCacheConfig();
        }
        if ($tab === 'openlitespeed' && $sub === 'extapps' && ! $this->ols_extapps_loaded) {
            $this->loadOlsExtAppsConfig();
        }
        if ($tab === 'openlitespeed' && $sub === 'listeners' && ! $this->ols_listeners_loaded) {
            $this->loadOlsListenersConfig();
        }
        if ($tab === 'openlitespeed' && $sub === 'vhosts' && ! $this->ols_vhosts_loaded) {
            $this->loadOlsVhostsConfig();
        }
        if ($tab === 'openlitespeed' && $sub === 'modules' && ! $this->ols_modules_loaded) {
            $this->loadOlsModulesConfig();
        }
        if ($tab === 'caddy' && $sub === 'admin' && ! $this->caddy_globals_loaded) {
            $this->loadCaddyGlobalsConfig();
        }
        if ($tab === 'caddy' && $sub === 'snippets' && ! $this->caddy_snippets_loaded) {
            $this->loadCaddySnippetsConfig();
        }
        if ($tab === 'caddy' && $sub === 'modules' && ! $this->caddy_modules_loaded) {
            $this->loadCaddyModulesInventory();
        }
        if ($tab === 'caddy' && $sub === 'routes' && ! $this->caddy_custom_routes_loaded) {
            $this->loadCaddyCustomRoutesConfig();
        }
        if ($tab === 'nginx' && $sub === 'upstreams' && ! $this->nginx_upstreams_loaded) {
            $this->loadNginxUpstreamsConfig();
        }
        if ($tab === 'nginx' && $sub === 'hosts' && ! $this->nginx_custom_hosts_loaded) {
            $this->loadNginxCustomHostsConfig();
        }
        if ($tab === 'nginx' && $sub === 'modules' && ! $this->nginx_modules_loaded) {
            $this->loadNginxModulesConfig();
        }
        if ($tab === 'nginx' && $sub === 'cache' && ! $this->nginx_cache_loaded) {
            $this->loadNginxCacheConfig();
        }
        if ($tab === 'apache' && $sub === 'modules' && ! $this->apache_modules_loaded) {
            $this->loadApacheModulesConfig();
        }
        if ($tab === 'apache' && $sub === 'cache' && ! $this->apache_cache_loaded) {
            $this->loadApacheCacheConfig();
        }
        if ($tab === 'apache' && $sub === 'vhosts' && ! $this->apache_custom_vhosts_loaded) {
            $this->loadApacheCustomVhostsConfig();
        }
        if ($tab === 'haproxy' && $sub === 'runtime' && ! $this->haproxy_globals_loaded) {
            $this->loadHaproxyGlobalsConfig();
        }
        if ($tab === 'haproxy' && $sub === 'frontends' && ! $this->haproxy_frontends_loaded) {
            $this->loadHaproxyFrontendsConfig();
        }
        if ($tab === 'haproxy' && $sub === 'backends' && ! $this->haproxy_backends_loaded) {
            $this->loadHaproxyBackendsConfig();
        }
        if ($tab === 'traefik' && $sub === 'static' && ! $this->traefik_static_loaded) {
            $this->loadTraefikStaticConfig();
        }
        if ($tab === 'traefik' && $sub === 'dynamic' && ! $this->traefik_dynamic_loaded) {
            $this->loadTraefikDynamicConfigs();
        }
        if ($tab === 'traefik' && $sub === 'overview') {
            $this->ensureEngineLiveState();
            if (! $this->traefik_dashboard_loaded) {
                $this->loadTraefikDashboardConfig();
            }
        }
        if ($tab === 'traefik' && $sub === 'providers' && ! $this->traefik_providers_loaded) {
            $this->loadTraefikProvidersConfig();
        }
        if ($tab === 'traefik' && $sub === 'routers' && ! $this->traefik_custom_routes_loaded) {
            $this->loadTraefikCustomRoutesConfig();
        }
        if ($tab === 'traefik' && $sub === 'middlewares' && ! $this->traefik_custom_middlewares_loaded) {
            $this->loadTraefikCustomMiddlewaresConfig();
        }
        if ($tab === 'traefik' && $sub === 'entrypoints' && ! $this->traefik_entrypoints_loaded) {
            $this->loadTraefikEntrypointsConfig();
        }
        if ($tab === 'traefik' && in_array($sub, ['tcprouters', 'tcpservices'], true) && ! $this->traefik_tcp_routes_loaded) {
            $this->loadTraefikTcpRoutesConfig();
        }
        if ($tab === 'traefik' && in_array($sub, ['udprouters', 'udpservices'], true) && ! $this->traefik_udp_routes_loaded) {
            $this->loadTraefikUdpRoutesConfig();
        }
        if ($tab === 'traefik' && $sub === 'services' && ! $this->traefik_http_services_loaded) {
            $this->loadTraefikHttpServicesConfig();
        }
        if ($tab === 'envoy' && $sub === 'overview') {
            $this->ensureEngineLiveState();
        }
        if ($tab === 'envoy' && $sub === 'static' && ! $this->envoy_static_loaded) {
            $this->loadEnvoyStaticConfig();
        }
        if ($tab === 'envoy' && $sub === 'clusters' && ! $this->envoy_clusters_loaded) {
            $this->loadEnvoyClustersConfig();
        }
        if ($tab === 'envoy' && $sub === 'virtualhosts' && ! $this->envoy_virtualhosts_loaded) {
            $this->loadEnvoyVirtualHostsConfig();
        }
        if ($tab === 'envoy' && $sub === 'listeners' && ! $this->envoy_listeners_loaded) {
            $this->loadEnvoyListenersConfig();
        }
        if ($tab === 'openresty' && $sub === 'overview') {
            $this->ensureEngineLiveState();
        }
        if ($tab === 'openresty' && $sub === 'static' && ! $this->openresty_static_loaded) {
            $this->loadOpenRestyStaticConfig();
        }
        if ($tab === 'openresty' && $sub === 'upstreams' && ! $this->openresty_upstreams_loaded) {
            $this->loadOpenRestyUpstreamsConfig();
        }
        if ($tab === 'openresty' && $sub === 'servers' && ! $this->openresty_servers_loaded) {
            $this->loadOpenRestyServersConfig();
        }
        if ($sub === 'workers') {
            if ($tab === 'nginx' && ! $this->nginx_globals_loaded) {
                $this->loadNginxGlobalsConfig();
            } elseif ($tab === 'apache' && ! $this->apache_globals_loaded) {
                $this->loadApacheGlobalsConfig();
            }
        }

        if ($sub === 'config' && $this->engineSupportsConfig($tab) && ! $this->webserverConfigFilesLoaded) {
            $this->loadWebserverConfigFiles();
        }

        if ($this->isEngineLiveStateSubtab($sub, $tab)) {
            $this->ensureEngineLiveState();
        }
    }

    /**
     * Refresh-now action for the per-engine live-state sub-tabs. Runs a
     * fresh probe (synchronous SSH) and updates the cached state on
     * Server.meta. Returns nothing — the blade re-renders against the
     * new cached state on next paint.
     */
    public function refreshEngineLiveState(): void
    {
        $this->authorize('view', $this->server);
        $this->ensureEngineLiveState(forceFresh: true);
        $this->toastSuccess(__('Refreshed.'));
    }

    /**
     * Load cached live-state when fresh (default 60s TTL), otherwise probe
     * over SSH and persist to Server.meta. Called when opening a live-state
     * sub-tab (Hosts, Upstreams, etc.).
     */
    public function ensureEngineLiveState(bool $forceFresh = false): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            return;
        }

        $engine = $this->workspace_tab;
        if (! $this->isEngineLiveStateSubtab($this->engine_subtab, $engine)) {
            return;
        }

        $probe = $this->resolveLiveStateProbe($engine);
        if ($probe === null) {
            return;
        }

        $this->engine_live_state_loading = true;

        try {
            $probe->probe($this->server->fresh(), $forceFresh);
            $this->server->refresh();
            if ($engine === 'traefik') {
                $this->mergeTraefikStaticEntrypointsIntoMeta();
                $this->server->refresh();
            }
        } catch (\Throwable $e) {
            if ($forceFresh) {
                $this->toastError(__('Refresh failed: :msg', ['msg' => $e->getMessage()]));
            }
        } finally {
            $this->engine_live_state_loading = false;
        }
    }

    private function isEngineLiveStateSubtab(string $subtab, string $engine): bool
    {
        $map = [
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

        return in_array($subtab, $map[$engine] ?? [], true);
    }

    /**
     * Engine key → probe implementation. Returns null for engines whose
     * probe isn't built yet (anything other than OLS in v1). Each
     * subsequent engine wires in here as its probe lands.
     */
    private function resolveLiveStateProbe(string $engine): ?EngineLiveStateProbe
    {
        return match ($engine) {
            'openlitespeed' => app(OlsLiveStateProbe::class),
            'caddy' => app(CaddyLiveStateProbe::class),
            'nginx' => app(NginxLiveStateProbe::class),
            'apache' => app(ApacheLiveStateProbe::class),
            'traefik' => app(TraefikLiveStateProbe::class),
            'haproxy' => app(HaproxyLiveStateProbe::class),
            'envoy' => app(EnvoyLiveStateProbe::class),
            'openresty' => app(OpenRestyLiveStateProbe::class),
            default => null,
        };
    }
}
