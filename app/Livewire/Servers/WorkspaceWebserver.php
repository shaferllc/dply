<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Enums\SiteType;
use App\Jobs\RunWebserverConfigOpJob;
use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Servers\Concerns\LoadsLiveServerCerts;
use App\Livewire\Servers\Concerns\ManagesWebserverConfigRevisions;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ApacheCustomVhostsConfig;
use App\Services\Servers\ApacheEngineCacheConfig;
use App\Services\Servers\ApacheEngineCachePurger;
use App\Services\Servers\ApacheGlobalOptionsConfig;
use App\Services\Servers\ApacheModulesConfig;
use App\Services\Servers\CaddyCustomRoutesConfig;
use App\Services\Servers\CaddyGlobalOptionsConfig;
use App\Services\Servers\CaddyModulesManager;
use App\Services\Servers\EnvoyCustomClustersConfig;
use App\Services\Servers\EnvoyCustomListenersConfig;
use App\Services\Servers\EnvoyCustomVirtualHostsConfig;
use App\Services\Servers\EnvoyStaticConfigOptions;
use App\Services\Servers\HaproxyBackendsConfig;
use App\Services\Servers\HaproxyFrontendsConfig;
use App\Services\Servers\HaproxyGlobalOptionsConfig;
use App\Services\Servers\LiveState\ApacheLiveStateProbe;
use App\Services\Servers\LiveState\CaddyLiveStateProbe;
use App\Services\Servers\LiveState\EngineLiveStateProbe;
use App\Services\Servers\LiveState\EnvoyLiveStateProbe;
use App\Services\Servers\LiveState\HaproxyLiveStateProbe;
use App\Services\Servers\LiveState\NginxLiveStateProbe;
use App\Services\Servers\LiveState\OlsLiveStateProbe;
use App\Services\Servers\LiveState\OpenRestyLiveStateProbe;
use App\Services\Servers\LiveState\TraefikLiveStateProbe;
use App\Services\Servers\NginxAccessLogParser;
use App\Services\Servers\NginxCustomHostsConfig;
use App\Services\Servers\NginxEngineCacheConfig;
use App\Services\Servers\NginxEngineCachePurger;
use App\Services\Servers\NginxGlobalOptionsConfig;
use App\Services\Servers\NginxModulesConfig;
use App\Services\Servers\NginxUpstreamsConfig;
use App\Services\Servers\OpenLiteSpeedCacheModuleConfig;
use App\Services\Servers\OpenLiteSpeedExtAppsConfig;
use App\Services\Servers\OpenLiteSpeedListenersConfig;
use App\Services\Servers\OpenLiteSpeedLscachePurger;
use App\Services\Servers\OpenLiteSpeedModulesConfig;
use App\Services\Servers\OpenLiteSpeedVhostsConfig;
use App\Services\Servers\OpenRestyCustomServersConfig;
use App\Services\Servers\OpenRestyCustomUpstreamsConfig;
use App\Services\Servers\OpenRestyStaticConfigOptions;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerManageToolsReport;
use App\Services\Servers\ServerMetricsRangeQuery;
use App\Services\Servers\ServerPhpManager;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\ServerWebserverConfigEditor;
use App\Services\Servers\TraefikCustomMiddlewaresConfig;
use App\Services\Servers\TraefikCustomRoutesConfig;
use App\Services\Servers\TraefikDashboardExposure;
use App\Services\Servers\TraefikDynamicConfigInventory;
use App\Services\Servers\TraefikEntrypointsConfig;
use App\Services\Servers\TraefikHttpServicesConfig;
use App\Services\Servers\TraefikProvidersConfig;
use App\Services\Servers\TraefikStaticConfigOptions;
use App\Services\Servers\TraefikTcpRoutesConfig;
use App\Services\Servers\TraefikUdpRoutesConfig;
use App\Services\Servers\WebserverConfigDriftDetector;
use App\Services\Sites\SiteCaddyProvisioner;
use App\Services\SshConnection;
use App\Support\Servers\CaddyPhpFpmUpstreamAddress;
use App\Support\Servers\ServerConsoleActionLookup;
use App\Support\Servers\WebserverWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

/**
 * Top-level "Webserver" workspace — gives the per-server webserver picker grid +
 * cascade modal + audit history its own sidebar entry, peer to PHP / Caches /
 * Cron, rather than living nested under Manage > Web.
 *
 * Extends {@see WorkspaceManage} so all the switch state, switch methods,
 * service-action plumbing (runAllowlistedAction et al), banner concerns, and
 * console-action dismissal are inherited unchanged. The only differences:
 *
 *   - `mount()` accepts no `?section` query string (this isn't a sub-tab
 *     anymore) — section is fixed at 'web' so the parent's render share +
 *     trait-internal asserts continue working.
 *   - `render()` points at `workspace-webserver.blade.php`, a thin orchestrator
 *     that lazy-renders tab partials under `partials/webserver/` inside
 *     {@see <x-server-workspace-layout>} with `active="webserver"`.
 *   - Adds Tools / Logs / Config sub-tabs and their backing Livewire methods
 *     (load/save/validate/restore for config; tail for logs). Path safety and
 *     atomic-write semantics live in {@see RemoteWebserverConfigService}.
 *
 * Result: clicking "Webserver" in the sidebar lands on the same content,
 * scoped + framed as a peer workspace rather than nested.
 */
#[Layout('layouts.app')]
class WorkspaceWebserver extends WorkspaceManage
{
    use LoadsLiveServerCerts;
    use ManagesWebserverConfigRevisions;

    /**
     * Second-level tab within this workspace — mirrors WorkspaceDatabases /
     * WorkspaceCaches: an "overview" tab, one tab per webserver in the
     * catalog (currently active gets an Active badge; the rest let the
     * operator open the cascade-switch modal), and an "advanced" tab that
     * collects PHP-FPM, TLS, and the switch-history table.
     *
     * Allowed values are validated in {@see setWorkspaceTab()}; an unknown
     * value falls back to 'overview' rather than throwing.
     *
     * Bound to ?tab= so an operator can deep-link / share the URL and the
     * tab they were on restores on load.
     */
    #[Url(as: 'tab', except: 'overview')]
    public string $workspace_tab = 'overview';

    /**
     * Per-engine sub-tab. Originally just `overview` / `info`; now also
     * `logs` (live access + error tail) and `config` (file editor) plus the
     * per-engine live-state sub-tabs (vhosts / listeners / routers / etc.).
     * Validated in {@see setEngineSubtab()}; unknown values fall back to
     * `overview`.
     *
     * Bound to ?sub= so deep-linking to e.g. ?tab=openlitespeed&sub=cache
     * lands the operator directly on that sub-tab.
     */
    #[Url(as: 'sub', except: 'overview')]
    public string $engine_subtab = 'overview';

    // ---- Config editor state -----------------------------------------

    /** The path the editor currently has loaded, scoped to the active engine. */
    public ?string $config_selected_path = null;

    /** Mutable buffer bound to the textarea; persists across re-renders. */
    public string $config_contents = '';

    /** Output of the last validate run (whether triggered by a save or the explicit button). */
    public ?string $config_validate_output = null;

    /** True if the last validate run looked OK (engine-specific heuristic). */
    public ?bool $config_validate_ok = null;

    /** Set when the last save raised the "file > preview cap" notice. */
    public bool $config_truncated_on_load = false;

    /** Path of the most-recent backup the editor created; surfaced as a quick-revert affordance. */
    public ?string $config_last_backup = null;

    /** Cached backup listing for the loaded file (path => row). */
    public array $config_backups = [];

    /** True when the loaded buffer came from the per-path content cache (instant, no SSH). */
    public bool $config_loaded_from_cache = false;

    /** ISO-8601 timestamp the cached buffer was read from the server, when loaded from cache. */
    public ?string $config_content_cached_at = null;

    // ---- Log viewer state --------------------------------------------

    /** Which log to read: 'access', 'error', or 'journal'. */
    public string $log_kind = 'access';

    /** Last fetched log buffer; rendered in a <pre> on the Logs tab. */
    public string $log_output = '';

    /** How many trailing lines the last fetch grabbed. */
    public int $log_lines = 300;

    /** When true, the Logs tab adds a wire:poll so the buffer refreshes every few seconds. */
    public bool $log_live = false;

    /** When true, force the raw text dump even for parseable nginx access logs. */
    public bool $log_raw = false;

    /**
     * Time range for the per-engine Overview health charts. One of the
     * ServerMetricsRangeQuery::RANGES keys: '1h', '6h', '24h', '7d'.
     * Persisted in localStorage on the client (keyed per server) so the
     * operator's preference survives reloads.
     */
    public string $engine_metrics_range = '1h';

    /**
     * Set while a queued file-load is in flight. The render loop watches
     * the matching ConsoleAction row and, once it goes to completed,
     * pulls the cached read result and drops it into the editor buffer.
     */
    public ?string $pending_load_console_id = null;

    public ?string $pending_load_path = null;

    // ---- OLS cache module form (Cache sub-tab on the OpenLiteSpeed engine).
    /** Form values keyed by `OpenLiteSpeedCacheModuleConfig::PARAMS` keys. */
    public array $ols_cache_form = [];

    /** True once we've fetched server values into the form. */
    public bool $ols_cache_loaded = false;

    /** Banner state for the save action ("Saved.", "Validation failed: …"). */
    public ?string $ols_cache_flash = null;

    public ?string $ols_cache_error = null;

    // ---- OLS ExtApps form (ExtApps sub-tab on the OpenLiteSpeed engine).
    /**
     * Read-only identity per app (name → ['type','address','path']).
     * Shown in the card header so the operator knows which worker pool.
     *
     * @var array<string, array<string, string>>
     */
    public array $ols_extapps_identity = [];

    /**
     * Editable values per app (app-name → directive-key → value string).
     *
     * @var array<string, array<string, string>>
     */
    public array $ols_extapps_form = [];

    public bool $ols_extapps_loaded = false;

    public ?string $ols_extapps_flash = null;

    public ?string $ols_extapps_error = null;

    /** Toggles the inline "+ Add ExtApp" form. */
    public bool $ols_extapps_show_add = false;

    /**
     * Backing state for the add-form inputs.
     *
     * @var array<string, string>
     */
    public array $ols_extapps_new_app = [
        'name' => '',
        'type' => 'lsapi',
        'address' => '',
        'path' => '',
    ];

    // ---- OLS Listeners form (Listeners sub-tab on the OpenLiteSpeed engine).
    /** @var array<string, array<string, string>> */
    public array $ols_listeners_identity = [];

    /** @var array<string, array<string, string>> */
    public array $ols_listeners_form = [];

    /** @var array<string, list<string>>  Per-listener map-directive entries (read-only). */
    public array $ols_listeners_maps = [];

    public bool $ols_listeners_loaded = false;

    public ?string $ols_listeners_flash = null;

    public ?string $ols_listeners_error = null;

    public bool $ols_listeners_show_add = false;

    /** @var array<string, string> */
    public array $ols_listeners_new = [
        'name' => '',
        'address' => '',
        'secure' => '0',
        'keyFile' => '',
        'certFile' => '',
    ];

    // ---- OLS Modules toggle (Modules sub-tab on the OpenLiteSpeed engine).
    /**
     * Per-module: ['name', 'enabled', 'protected', 'on_disk', 'type']
     *
     * @var list<array{name: string, enabled: bool, protected: bool, on_disk: bool, type: string}>
     */
    public array $ols_modules_list = [];

    public bool $ols_modules_loaded = false;

    public ?string $ols_modules_flash = null;

    public ?string $ols_modules_error = null;

    /** Active type filter on the modules table: 'all' or one of the classify() outputs. */
    public string $ols_modules_filter = 'all';

    // ---- Caddy Global Options form (Admin sub-tab on the Caddy engine).
    /** @var array<string, string> */
    public array $caddy_globals_form = [];

    public bool $caddy_globals_loaded = false;

    public ?string $caddy_globals_flash = null;

    public ?string $caddy_globals_error = null;

    // ---- Caddy Snippets form (Snippets sub-tab on the Caddy engine).
    /** @var array<string, string> Snippet name → body text */
    public array $caddy_snippets_form = [];

    public bool $caddy_snippets_loaded = false;

    public ?string $caddy_snippets_flash = null;

    public ?string $caddy_snippets_error = null;

    public bool $caddy_snippets_show_add = false;

    /** @var array<string, string> */
    public array $caddy_snippets_new = ['name' => '', 'body' => ''];

    // ---- Caddy Modules (Modules sub-tab on the Caddy engine).
    /**
     * @var list<array{id: string, namespace: string, kind: string}>
     */
    public array $caddy_modules_installed = [];

    /**
     * @var list<array{
     *     path: string,
     *     version: string,
     *     label: string,
     *     description: string,
     *     repo: string,
     *     docs_url: string,
     *     module_ids: list<string>,
     *     compiled: bool,
     * }>
     */
    public array $caddy_modules_plugins = [];

    public bool $caddy_modules_loaded = false;

    public ?string $caddy_modules_flash = null;

    public ?string $caddy_modules_error = null;

    public string $caddy_modules_filter = 'all';

    public string $caddy_modules_search = '';

    public bool $caddy_modules_show_add = false;

    /** @var array{path: string, version: string} */
    public array $caddy_modules_new = ['path' => '', 'version' => ''];

    public ?string $caddy_modules_caddy_version = null;

    public bool $caddy_modules_custom_binary = false;

    public bool $caddy_modules_show_browse = false;

    public string $caddy_modules_browse_search = '';

    public ?string $caddy_modules_browse_error = null;

    /** @var array<string, array{label: string, description: string}> */
    public array $caddy_modules_available_catalog = [];

    /**
     * @var list<array{
     *     path: string,
     *     repo: string,
     *     label: string,
     *     description: string,
     *     module_ids: list<string>,
     * }>
     */
    public array $caddy_modules_browse_packages = [];

    // ---- nginx Global Options form (Workers sub-tab on the nginx engine).
    /** @var array<string, string> */
    public array $nginx_globals_form = [];

    public bool $nginx_globals_loaded = false;

    public ?string $nginx_globals_flash = null;

    public ?string $nginx_globals_error = null;

    // ---- Apache Global Options form (Workers sub-tab on the Apache engine).
    /** @var array<string, string> */
    public array $apache_globals_form = [];

    public bool $apache_globals_loaded = false;

    public ?string $apache_globals_flash = null;

    public ?string $apache_globals_error = null;

    /** Resolved MPM block name (mpm_event_module / mpm_worker_module / mpm_prefork_module). */
    public string $apache_globals_mpm = 'mpm_event_module';

    // ---- Apache Modules toggle (Modules sub-tab on the Apache engine).
    /**
     * Per-module: ['name', 'enabled', 'protected', 'type']
     *
     * @var list<array{name: string, enabled: bool, protected: bool, type: string}>
     */
    public array $apache_modules_list = [];

    public bool $apache_modules_loaded = false;

    public ?string $apache_modules_flash = null;

    public ?string $apache_modules_error = null;

    /** Active type filter on the modules table: 'all' or one of the classify() outputs. */
    public string $apache_modules_filter = 'all';

    /**
     * Custom Apache vhosts keyed by slug → VirtualHost fields.
     *
     * @var array<string, array{server_name: string, server_aliases: string, document_root: string, php_socket: string}>
     */
    public array $apache_custom_vhosts_form = [];

    public bool $apache_custom_vhosts_loaded = false;

    public ?string $apache_custom_vhosts_flash = null;

    public ?string $apache_custom_vhosts_error = null;

    public bool $apache_custom_vhosts_show_add = false;

    /** @var array{slug: string, server_name: string, server_aliases: string, document_root: string, php_socket: string} */
    public array $apache_custom_vhosts_new = [
        'slug' => '',
        'server_name' => '',
        'server_aliases' => '',
        'document_root' => '',
        'php_socket' => '',
    ];

    // ---- HAProxy Global Options form (Runtime sub-tab on the HAProxy edge proxy).
    /** @var array<string, string> */
    public array $haproxy_globals_form = [];

    public bool $haproxy_globals_loaded = false;

    public ?string $haproxy_globals_flash = null;

    public ?string $haproxy_globals_error = null;

    // ---- HAProxy Frontends editor (Frontends sub-tab on the HAProxy edge proxy).
    /**
     * Per-frontend: ['binds' => list<string>, 'values' => array<string,string>]
     *
     * @var array<string, array{binds: list<string>, values: array<string, string>}>
     */
    public array $haproxy_frontends_form = [];

    /** Textarea-friendly mirror of `binds` per frontend (newline-separated). */
    /** @var array<string, string> */
    public array $haproxy_frontends_binds_text = [];

    public bool $haproxy_frontends_loaded = false;

    public ?string $haproxy_frontends_flash = null;

    public ?string $haproxy_frontends_error = null;

    public bool $haproxy_frontends_show_add = false;

    /** @var array<string, string> */
    public array $haproxy_frontends_new = ['name' => '', 'binds' => '', 'default_backend' => ''];

    // ---- HAProxy Backends editor (Backends sub-tab).
    /**
     * Per-backend: ['servers' => list<string>, 'values' => array<string,string>]
     *
     * @var array<string, array{servers: list<string>, values: array<string, string>}>
     */
    public array $haproxy_backends_form = [];

    /** @var array<string, string>  Textarea-friendly mirror of `servers` per backend. */
    public array $haproxy_backends_servers_text = [];

    public bool $haproxy_backends_loaded = false;

    public ?string $haproxy_backends_flash = null;

    public ?string $haproxy_backends_error = null;

    public bool $haproxy_backends_show_add = false;

    /** @var array<string, string> */
    public array $haproxy_backends_new = ['name' => '', 'servers' => '', 'balance' => 'roundrobin'];

    // ---- Cross-engine TLS certificates dashboard (Health tab card).
    // State + loader live in LoadsLiveServerCerts ($liveCerts*), shared with the
    // server cert-inventory page; the SSH sweep runs async in ScanServerLiveCertsJob.

    // ---- Site smoke-test results (Overview tab card).
    /**
     * Per-site smoke test result entries from {@see WebserverSmokeTestRunner}.
     *
     * @var list<array<string, mixed>>
     */
    public array $smoke_results = [];

    public ?string $smoke_scanned_at_iso = null;

    public int $smoke_total_sites = 0;

    public int $smoke_probed = 0;

    public bool $smoke_truncated = false;

    public bool $smoke_loaded = false;

    public ?string $smoke_error = null;

    // ---- Config drift detector (Overview tab card).
    /** @var list<array<string, mixed>> */
    public array $drift_results = [];

    public ?string $drift_engine = null;

    public ?string $drift_scanned_at_iso = null;

    public int $drift_total_sites = 0;

    public int $drift_count = 0;

    public bool $drift_truncated = false;

    public bool $drift_unsupported = false;

    public bool $drift_loaded = false;

    public ?string $drift_error = null;

    // ---- Traefik Static Config form (Providers sub-tab on the Traefik edge proxy).
    /** @var array<string, string> */
    public array $traefik_static_form = [];

    public bool $traefik_static_loaded = false;

    public ?string $traefik_static_flash = null;

    public ?string $traefik_static_error = null;

    /** @var list<array{path: string, basename: string, size: int, modified_at: ?string}> */
    public array $traefik_dynamic_files = [];

    public bool $traefik_dynamic_loaded = false;

    public ?string $traefik_dynamic_error = null;

    /** @var array<string, string> */
    public array $traefik_providers_form = [];

    /** @var list<array{key: string, label: string, summary: string}> */
    public array $traefik_providers_configured = [];

    public bool $traefik_providers_loaded = false;

    public ?string $traefik_providers_flash = null;

    public ?string $traefik_providers_error = null;

    /** @var array{enabled: string, path: string, username: string, password: string} */
    public array $traefik_dashboard_form = [
        'enabled' => '0',
        'path' => '/traefik-dashboard',
        'username' => '',
        'password' => '',
    ];

    public bool $traefik_dashboard_loaded = false;

    public ?string $traefik_dashboard_flash = null;

    public ?string $traefik_dashboard_error = null;

    /** @var array<string, array{hosts: string, upstream: string, rule: string, middlewares: string}> */
    public array $traefik_custom_routes_form = [];

    public bool $traefik_custom_routes_loaded = false;

    public ?string $traefik_custom_routes_flash = null;

    public ?string $traefik_custom_routes_error = null;

    public bool $traefik_custom_routes_show_add = false;

    /** @var array{slug: string, hosts: string, upstream: string, rule: string, middlewares: string} */
    public array $traefik_custom_routes_new = [
        'slug' => '',
        'hosts' => '',
        'upstream' => '',
        'rule' => '',
        'middlewares' => '',
    ];

    /** @var array<string, array{type: string, prefix: string, scheme: string, header_key: string, header_value: string, users: string}> */
    public array $traefik_custom_middlewares_form = [];

    public bool $traefik_custom_middlewares_loaded = false;

    public ?string $traefik_custom_middlewares_flash = null;

    public ?string $traefik_custom_middlewares_error = null;

    public bool $traefik_custom_middlewares_show_add = false;

    /** @var array{slug: string, type: string, prefix: string, scheme: string, header_key: string, header_value: string, users: string} */
    public array $traefik_custom_middlewares_new = [
        'slug' => '',
        'type' => 'stripPrefix',
        'prefix' => '/',
        'scheme' => 'https',
        'header_key' => '',
        'header_value' => '',
        'users' => '',
    ];

    /** @var array<string, array{name: string, address: string}> */
    public array $traefik_entrypoints_form = [];

    public bool $traefik_entrypoints_loaded = false;

    public ?string $traefik_entrypoints_flash = null;

    public ?string $traefik_entrypoints_error = null;

    public bool $traefik_entrypoints_show_add = false;

    /** @var array{name: string, address: string} */
    public array $traefik_entrypoints_new = ['name' => '', 'address' => ':8080'];

    /** @var array<string, array{rule: string, entry_points: string, server_address: string}> */
    public array $traefik_tcp_routes_form = [];

    public bool $traefik_tcp_routes_loaded = false;

    public ?string $traefik_tcp_routes_flash = null;

    public ?string $traefik_tcp_routes_error = null;

    public bool $traefik_tcp_routes_show_add = false;

    /** @var array{slug: string, rule: string, entry_points: string, server_address: string} */
    public array $traefik_tcp_routes_new = ['slug' => '', 'rule' => 'HostSNI(`*`)', 'entry_points' => 'web', 'server_address' => ''];

    /** @var array<string, array{entry_points: string, server_address: string}> */
    public array $traefik_udp_routes_form = [];

    public bool $traefik_udp_routes_loaded = false;

    public ?string $traefik_udp_routes_flash = null;

    public ?string $traefik_udp_routes_error = null;

    public bool $traefik_udp_routes_show_add = false;

    /** @var array{slug: string, entry_points: string, server_address: string} */
    public array $traefik_udp_routes_new = ['slug' => '', 'entry_points' => 'web', 'server_address' => ''];

    /** @var array<string, array{servers: string}> */
    public array $traefik_http_services_form = [];

    public bool $traefik_http_services_loaded = false;

    public ?string $traefik_http_services_flash = null;

    public ?string $traefik_http_services_error = null;

    public bool $traefik_http_services_show_add = false;

    /** @var array{slug: string, servers: string} */
    public array $traefik_http_services_new = ['slug' => '', 'servers' => ''];

    /** @var array<string, string> */
    public array $envoy_static_form = [];

    public bool $envoy_static_loaded = false;

    public ?string $envoy_static_flash = null;

    public ?string $envoy_static_error = null;

    /** @var array<string, array{endpoints: list<string>, values: array<string, string>}> */
    public array $envoy_clusters_form = [];

    /** @var array<string, string> */
    public array $envoy_clusters_endpoints_text = [];

    public bool $envoy_clusters_loaded = false;

    public ?string $envoy_clusters_flash = null;

    public ?string $envoy_clusters_error = null;

    public bool $envoy_clusters_show_add = false;

    /** @var array{name: string, endpoints: string, connect_timeout: string, lb_policy: string} */
    public array $envoy_clusters_new = [
        'name' => '',
        'endpoints' => '',
        'connect_timeout' => '5s',
        'lb_policy' => 'ROUND_ROBIN',
    ];

    /** @var array<string, array{domains: list<string>, cluster: string}> */
    public array $envoy_virtualhosts_form = [];

    /** @var array<string, string> */
    public array $envoy_virtualhosts_domains_text = [];

    public bool $envoy_virtualhosts_loaded = false;

    public ?string $envoy_virtualhosts_flash = null;

    public ?string $envoy_virtualhosts_error = null;

    public bool $envoy_virtualhosts_show_add = false;

    /** @var array{name: string, domains: string, cluster: string} */
    public array $envoy_virtualhosts_new = [
        'name' => '',
        'domains' => '',
        'cluster' => '',
    ];

    /** @var array<string, array{address: string, port: int|string, mode: string, default_cluster: string}> */
    public array $envoy_listeners_form = [];

    public bool $envoy_listeners_loaded = false;

    public ?string $envoy_listeners_flash = null;

    public ?string $envoy_listeners_error = null;

    public bool $envoy_listeners_show_add = false;

    /** @var array{name: string, address: string, port: string, mode: string, default_cluster: string} */
    public array $envoy_listeners_new = [
        'name' => '',
        'address' => '0.0.0.0',
        'port' => '',
        'mode' => 'shared',
        'default_cluster' => '',
    ];

    /** @var array<string, string> */
    public array $openresty_static_form = [];

    public bool $openresty_static_loaded = false;

    public ?string $openresty_static_flash = null;

    public ?string $openresty_static_error = null;

    /** @var array<string, array{servers: list<string>}> */
    public array $openresty_upstreams_form = [];

    /** @var array<string, string> */
    public array $openresty_upstreams_servers_text = [];

    public bool $openresty_upstreams_loaded = false;

    public ?string $openresty_upstreams_flash = null;

    public ?string $openresty_upstreams_error = null;

    public bool $openresty_upstreams_show_add = false;

    /** @var array{name: string, servers: string} */
    public array $openresty_upstreams_new = ['name' => '', 'servers' => ''];

    /** @var array<string, array{server_names: list<string>, upstream: string}> */
    public array $openresty_servers_form = [];

    /** @var array<string, string> */
    public array $openresty_servers_names_text = [];

    public bool $openresty_servers_loaded = false;

    public ?string $openresty_servers_flash = null;

    public ?string $openresty_servers_error = null;

    public bool $openresty_servers_show_add = false;

    /** @var array{name: string, server_names: string, upstream: string} */
    public array $openresty_servers_new = ['name' => '', 'server_names' => '', 'upstream' => ''];

    // ---- nginx Upstreams editor (Upstreams sub-tab on the nginx engine).
    /**
     * Per-upstream: ['servers' => list<string>, 'values' => array<string,string>]
     *
     * @var array<string, array{servers: list<string>, values: array<string, string>}>
     */
    public array $nginx_upstreams_form = [];

    /**
     * Textarea-friendly mirror of `servers` per upstream (newline-separated).
     * Livewire binds the textarea to this; submitNginxUpstreams() splits on
     * newlines and writes the list back to `nginx_upstreams_form`.
     *
     * @var array<string, string>
     */
    public array $nginx_upstreams_servers_text = [];

    public bool $nginx_upstreams_loaded = false;

    public ?string $nginx_upstreams_flash = null;

    public ?string $nginx_upstreams_error = null;

    public bool $nginx_upstreams_show_add = false;

    /** @var array<string, string> */
    public array $nginx_upstreams_new = ['name' => '', 'servers' => ''];

    /**
     * Custom nginx hosts keyed by slug → server block fields.
     *
     * @var array<string, array{server_names: string, listen: string, root: string, upstream: string}>
     */
    public array $nginx_custom_hosts_form = [];

    public bool $nginx_custom_hosts_loaded = false;

    public ?string $nginx_custom_hosts_flash = null;

    public ?string $nginx_custom_hosts_error = null;

    public bool $nginx_custom_hosts_show_add = false;

    /** @var array{slug: string, server_names: string, listen: string, root: string, upstream: string} */
    public array $nginx_custom_hosts_new = [
        'slug' => '',
        'server_names' => '',
        'listen' => "80\n[::]:80",
        'root' => '',
        'upstream' => '',
    ];

    // ---- nginx dynamic modules (Modules sub-tab on the nginx engine).
    /**
     * @var list<array{
     *     name: string,
     *     conf_file: string,
     *     enabled: bool,
     *     protected: bool,
     *     type: string,
     *     source: string,
     *     package: string,
     *     installed: bool,
     *     so_path: string,
     * }>
     */
    public array $nginx_modules_list = [];

    /** @var list<array{name: string, type: string}> */
    public array $nginx_modules_builtins = [];

    public bool $nginx_modules_loaded = false;

    public bool $nginx_modules_supports_dynamic = false;

    public ?string $nginx_modules_flash = null;

    public ?string $nginx_modules_error = null;

    public string $nginx_modules_filter = 'all';

    /** @var array<string, string> */
    public array $nginx_cache_form = [];

    public bool $nginx_cache_loaded = false;

    public ?string $nginx_cache_flash = null;

    public ?string $nginx_cache_error = null;

    /** @var array<string, mixed> */
    public array $nginx_cache_meta = [];

    /** @var array<string, mixed> */
    public array $apache_cache_status = [];

    public bool $apache_cache_loaded = false;

    public bool $apache_mod_cache_enabled = false;

    public ?string $apache_cache_flash = null;

    public ?string $apache_cache_error = null;

    /**
     * Custom Caddy routes keyed by slug → site block fields.
     *
     * @var array<string, array{hosts: string, root: string, upstream: string}>
     */
    public array $caddy_custom_routes_form = [];

    public bool $caddy_custom_routes_loaded = false;

    public ?string $caddy_custom_routes_flash = null;

    public ?string $caddy_custom_routes_error = null;

    public bool $caddy_custom_routes_show_add = false;

    /** @var array{slug: string, hosts: string, root: string, upstream: string} */
    public array $caddy_custom_routes_new = [
        'slug' => '',
        'hosts' => '',
        'root' => '',
        'upstream' => '',
    ];

    public bool $engine_live_state_loading = false;

    // ---- OLS Vhosts form (Vhosts sub-tab on the OpenLiteSpeed engine).
    /**
     * Per-vhost identity (name → ['conf_path','vh_root','domains','unreadable']).
     *
     * @var array<string, array{conf_path: string, vh_root: ?string, domains: list<string>, unreadable: bool}>
     */
    public array $ols_vhosts_identity = [];

    /**
     * Per-vhost form values keyed by vhost-name → directive-key → value.
     *
     * @var array<string, array<string, string>>
     */
    public array $ols_vhosts_form = [];

    public bool $ols_vhosts_loaded = false;

    public ?string $ols_vhosts_flash = null;

    public ?string $ols_vhosts_error = null;

    public function mount(Server $server, ?string $section = null): void
    {
        // Force the inherited 'web' section state — the parent's render share
        // and any internal asserts on $section still resolve correctly without
        // requiring the operator to type `?section=web` on the URL.
        parent::mount($server, 'web');

        // If the URL restored a deep-link straight to the OLS Cache sub-tab,
        // populate the form so it renders with current server values on the
        // first paint instead of waiting for an extra round-trip.
        if ($this->workspace_tab === 'openlitespeed' && $this->engine_subtab === 'cache') {
            $this->loadOlsCacheConfig();
        }
        if ($this->workspace_tab === 'nginx' && $this->engine_subtab === 'cache') {
            $this->loadNginxCacheConfig();
        }
        if ($this->workspace_tab === 'apache' && $this->engine_subtab === 'cache') {
            $this->loadApacheCacheConfig();
        }

        // Eager-load the Health tab's drift detector so it paints on first
        // render. The TLS cert card loads async via the shared partial's
        // wire:init (loadLiveCerts) so its SSH sweep stays off the request.
        if ($this->workspace_tab === 'health' && $this->serverOpsReady()) {
            $this->loadDriftDetector();
        }
        // Other engine sub-tab data loads deferred via wire:init → loadActiveEngineSubtabData().
    }

    public function setWorkspaceTab(string $tab): void
    {
        $allowed = ['overview', 'change', 'health', 'nginx', 'caddy', 'apache', 'openlitespeed', 'advanced'];
        $this->workspace_tab = in_array($tab, $allowed, true) ? $tab : 'overview';
        // Reset the sub-tab on every top-level switch so the operator always
        // lands on the actionable view first. Skipping this would leave
        // Caddy on `info` after they navigated away from Nginx's `info`.
        $this->engine_subtab = 'overview';
        $this->resetConfigEditorState();
        $this->resetLogViewerState();

        if ($this->workspace_tab === 'health' && $this->serverOpsReady()) {
            $this->loadDriftDetector();
        }
    }

    /**
     * Range setter for the per-engine Overview health charts. Validates
     * against ServerMetricsRangeQuery's known ranges; falls back to '1h'.
     */
    public function setEngineMetricsRange(string $range): void
    {
        $allowed = array_keys(ServerMetricsRangeQuery::RANGES);
        $this->engine_metrics_range = in_array($range, $allowed, true) ? $range : '1h';
    }

    public function setEngineSubtab(string $subtab): void
    {
        $this->engine_subtab = $subtab;
    }

    public function updatedEngineSubtab(): void
    {
        $allowed = [
            'overview', 'info', 'logs', 'config',
            // OLS
            'vhosts', 'listeners', 'extapps', 'modules', 'cache',
            // nginx
            'hosts', 'upstreams', 'certs', 'modules', 'workers',
            // caddy (routes/upstreams/certs share with nginx; admin + snippets are unique)
            'routes', 'admin', 'snippets', 'modules',
            // apache (vhosts/workers/certs shared; modules unique)
            'modules',
            // traefik
            'routers', 'services', 'middlewares', 'entrypoints',
            'tcprouters', 'tcpservices', 'udprouters', 'udpservices', 'tls', 'providers',
            'static', 'dynamic',
            // haproxy
            'frontends', 'backends', 'ssl', 'runtime',
            // envoy
            'listeners', 'clusters', 'runtime', 'virtualhosts', 'stats', 'static',
        ];

        if ($this->engine_subtab === 'tools') {
            $this->engine_subtab = 'overview';
        }

        if (! in_array($this->engine_subtab, $allowed, true)) {
            $this->engine_subtab = 'overview';
        }

        if ($this->engine_subtab !== 'config') {
            $this->resetConfigEditorState();
        }
        if ($this->engine_subtab !== 'logs') {
            $this->resetLogViewerState();
        }

        // Close transient sub-tab UI; keep loaded SSH snapshots until explicit refresh.
        if ($this->engine_subtab !== 'listeners') {
            $this->ols_listeners_show_add = false;
        }
        if ($this->engine_subtab !== 'snippets') {
            $this->caddy_snippets_show_add = false;
        }
        if (! ($this->workspace_tab === 'caddy' && $this->engine_subtab === 'modules')) {
            $this->caddy_modules_show_add = false;
            $this->caddy_modules_show_browse = false;
        }
        if ($this->engine_subtab !== 'routes') {
            $this->caddy_custom_routes_show_add = false;
        }
        if ($this->engine_subtab !== 'upstreams') {
            $this->nginx_upstreams_show_add = false;
        }
        if ($this->engine_subtab !== 'hosts') {
            $this->nginx_custom_hosts_show_add = false;
        }
        if ($this->engine_subtab !== 'vhosts') {
            $this->apache_custom_vhosts_show_add = false;
        }
        if ($this->engine_subtab !== 'frontends') {
            $this->haproxy_frontends_show_add = false;
        }
        if ($this->engine_subtab !== 'backends') {
            $this->haproxy_backends_show_add = false;
        }
    }

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

        if ($this->isEngineLiveStateSubtab($sub, $tab)) {
            $this->ensureEngineLiveState();
        }
    }

    /**
     * Lazy-load the LSCache module values from the server so the form on the
     * OLS Cache sub-tab can render populated. Called on first navigation to
     * the sub-tab (via {@see setEngineSubtab}) and on the explicit refresh
     * button.
     */
    public function loadOlsCacheConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->ols_cache_error = __('Provisioning and SSH must be ready before reading the cache config.');

            return;
        }

        try {
            $result = app(OpenLiteSpeedCacheModuleConfig::class)->read($this->server);
            $this->ols_cache_form = $result['values'];
            $this->ols_cache_loaded = true;
            $this->ols_cache_error = null;
            $this->ols_cache_flash = null;
            if (! empty($result['unreadable'])) {
                $this->ols_cache_error = __('Could not read /usr/local/lsws/conf/httpd_config.conf — check sudo permissions for the deploy user. Defaults shown.');
            } elseif (! $result['exists']) {
                $this->ols_cache_flash = __('No cache module block found — defaults shown. Save to inject one into httpd_config.conf.');
            }
        } catch (\Throwable $e) {
            $this->ols_cache_error = __('Failed to read cache config: :msg', ['msg' => $e->getMessage()]);
            $this->ols_cache_loaded = false;
        }
    }

    public function loadOlsModulesConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->ols_modules_error = __('Provisioning and SSH must be ready before listing modules.');

            return;
        }

        try {
            $result = app(OpenLiteSpeedModulesConfig::class)->read($this->server);
            $this->ols_modules_list = $result['modules'];
            $this->ols_modules_loaded = true;
            $this->ols_modules_flash = null;
            $this->ols_modules_error = null;
            if (! empty($result['unreadable'])) {
                $this->ols_modules_error = __('Could not read /usr/local/lsws/modules or httpd_config.conf — check sudo permissions for the deploy user.');
            }
        } catch (\Throwable $e) {
            $this->ols_modules_error = __('Failed to read modules: :msg', ['msg' => $e->getMessage()]);
            $this->ols_modules_loaded = false;
        }
    }

    public function toggleOlsModule(string $name, bool $enable): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->ols_modules_error = __('Deployers cannot toggle OpenLiteSpeed modules.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->ols_modules_error = __('Provisioning and SSH must be ready before toggling modules.');

            return;
        }

        $this->ols_modules_flash = null;
        $this->ols_modules_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __(':verb OpenLiteSpeed module: :name', ['verb' => $enable ? 'Enable' : 'Disable', 'name' => $name]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(OpenLiteSpeedModulesConfig::class)
                ->toggle($this->server, $name, $enable, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_modules_flash = __('Module :name :state and OpenLiteSpeed reloaded.', ['name' => $name, 'state' => $enable ? 'enabled' : 'disabled']);
            $this->loadOlsModulesConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->ols_modules_error = $e->getMessage();
        }
    }

    public function setOlsModulesFilter(string $filter): void
    {
        $this->ols_modules_filter = in_array($filter, ['all', 'perf', 'security', 'other'], true) ? $filter : 'all';
    }

    /**
     * Persist the form values back to httpd_config.conf, validate, and
     * reload OLS. The service handles snapshot/restore on validation
     * failure; we surface the outcome via flash + error strings.
     */
    public function saveOlsCacheConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->ols_cache_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->ols_cache_error = __('Provisioning and SSH must be ready before saving the cache config.');

            return;
        }

        $this->ols_cache_flash = null;
        $this->ols_cache_error = null;

        // Seed a manage_action ConsoleAction row so the banner streams the
        // save's progress (snapshot → install → validate → reload) the same
        // way it does for other manage actions. We're running the save
        // synchronously inside the Livewire request — the row tracks status
        // so a refresh / second tab still sees the outcome.
        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save OpenLiteSpeed cache config'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(OpenLiteSpeedCacheModuleConfig::class)
                ->save($this->server, $this->ols_cache_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_cache_flash = __('Cache config saved and OpenLiteSpeed reloaded.');
            // Re-read to catch any directive the parser normalized (e.g. 1/0
            // round-tripped from on/off) so the form reflects what's on disk.
            $this->loadOlsCacheConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->ols_cache_error = $e->getMessage();
        }
    }

    public function purgeOlsLscacheConfirmed(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->ols_cache_error = __('Deployers cannot purge server cache.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->ols_cache_error = __('Provisioning and SSH must be ready before purging LSCache.');

            return;
        }

        $this->ols_cache_flash = null;
        $this->ols_cache_error = null;

        try {
            app(OpenLiteSpeedLscachePurger::class)->purgeAll($this->server);
            $this->ols_cache_flash = __('LSCache storage purged on the server.');
        } catch (\Throwable $e) {
            $this->ols_cache_error = $e->getMessage();
        }
    }

    public function loadNginxCacheConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->nginx_cache_error = __('Provisioning and SSH must be ready before reading cache settings.');

            return;
        }

        try {
            $result = app(NginxEngineCacheConfig::class)->read($this->server);
            $this->nginx_cache_form = $result['values'];
            $this->nginx_cache_meta = [
                'fcgi_path' => $result['fcgi_path'],
                'proxy_path' => $result['proxy_path'],
                'fcgi_zone' => $result['fcgi_zone'],
                'proxy_zone' => $result['proxy_zone'],
                'conf_path' => $result['conf_path'],
            ];
            $this->nginx_cache_loaded = true;
            $this->nginx_cache_error = null;
            $this->nginx_cache_flash = null;
        } catch (\Throwable $e) {
            $this->nginx_cache_error = __('Failed to read cache settings: :msg', ['msg' => $e->getMessage()]);
            $this->nginx_cache_loaded = false;
        }
    }

    public function saveNginxCacheConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->nginx_cache_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->nginx_cache_error = __('Provisioning and SSH must be ready before saving cache settings.');

            return;
        }

        $this->nginx_cache_flash = null;
        $this->nginx_cache_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save nginx engine cache config'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(NginxEngineCacheConfig::class)->save($this->server, $this->nginx_cache_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->nginx_cache_flash = __('Cache zones saved and nginx reloaded.');
            $this->loadNginxCacheConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->nginx_cache_error = $e->getMessage();
        }
    }

    public function purgeNginxEngineCacheConfirmed(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->nginx_cache_error = __('Deployers cannot purge server cache.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->nginx_cache_error = __('Provisioning and SSH must be ready before purging cache.');

            return;
        }

        $this->nginx_cache_flash = null;
        $this->nginx_cache_error = null;

        try {
            app(NginxEngineCachePurger::class)->purgeAll($this->server);
            $this->nginx_cache_flash = __('FastCGI and proxy cache storage purged on the server.');
        } catch (\Throwable $e) {
            $this->nginx_cache_error = $e->getMessage();
        }
    }

    public function loadApacheCacheConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->apache_cache_error = __('Provisioning and SSH must be ready before reading cache settings.');

            return;
        }

        try {
            $status = app(ApacheEngineCacheConfig::class)->read($this->server);
            $this->apache_cache_status = $status;
            $this->apache_mod_cache_enabled = (bool) ($status['apache_mod_cache_enabled'] ?? false);
            $this->apache_cache_loaded = true;
            $this->apache_cache_error = null;
            $this->apache_cache_flash = null;
        } catch (\Throwable $e) {
            $this->apache_cache_error = __('Failed to read cache settings: :msg', ['msg' => $e->getMessage()]);
            $this->apache_cache_loaded = false;
        }
    }

    public function saveApacheCacheConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->apache_cache_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->apache_cache_error = __('Provisioning and SSH must be ready before saving cache settings.');

            return;
        }

        $this->apache_cache_flash = null;
        $this->apache_cache_error = null;

        try {
            app(ApacheEngineCacheConfig::class)->saveModCacheFlag($this->server, $this->apache_mod_cache_enabled);
            $this->apache_cache_flash = __('Apache cache preferences saved.');
            $this->loadApacheCacheConfig();
        } catch (\Throwable $e) {
            $this->apache_cache_error = $e->getMessage();
        }
    }

    public function purgeApacheEngineCacheConfirmed(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->apache_cache_error = __('Deployers cannot purge server cache.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->apache_cache_error = __('Provisioning and SSH must be ready before purging cache.');

            return;
        }

        $this->apache_cache_flash = null;
        $this->apache_cache_error = null;

        try {
            app(ApacheEngineCachePurger::class)->purgeAll($this->server);
            $this->apache_cache_flash = __('Apache disk cache storage purged on the server.');
        } catch (\Throwable $e) {
            $this->apache_cache_error = $e->getMessage();
        }
    }

    /**
     * Load ExtApp blocks from httpd_config.conf into the form.
     */
    public function loadOlsExtAppsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->ols_extapps_error = __('Provisioning and SSH must be ready before reading ExtApp config.');

            return;
        }

        try {
            $result = app(OpenLiteSpeedExtAppsConfig::class)->read($this->server);
            $form = [];
            $identity = [];
            foreach ($result['apps'] as $app) {
                $form[$app['name']] = $app['values'];
                $identity[$app['name']] = $app['identity'];
            }
            $this->ols_extapps_form = $form;
            $this->ols_extapps_identity = $identity;
            $this->ols_extapps_loaded = true;
            $this->ols_extapps_flash = null;
            $this->ols_extapps_error = null;
            if (! empty($result['unreadable'])) {
                $this->ols_extapps_error = __('Could not read /usr/local/lsws/conf/httpd_config.conf — check sudo permissions for the deploy user.');
            } elseif (empty($result['apps'])) {
                $this->ols_extapps_flash = __('No extprocessor blocks found in httpd_config.conf yet.');
            }
        } catch (\Throwable $e) {
            $this->ols_extapps_error = __('Failed to read ExtApp config: :msg', ['msg' => $e->getMessage()]);
            $this->ols_extapps_loaded = false;
        }
    }

    /**
     * Persist the ExtApp form back to httpd_config.conf, validate, reload.
     * Streams each step into a manage_action ConsoleAction so the banner
     * shows the same per-step progress as the cache-module save.
     */
    public function saveOlsExtAppsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->ols_extapps_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->ols_extapps_error = __('Provisioning and SSH must be ready before saving ExtApp config.');

            return;
        }

        $this->ols_extapps_flash = null;
        $this->ols_extapps_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save OpenLiteSpeed ExtApp config'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(OpenLiteSpeedExtAppsConfig::class)
                ->save($this->server, $this->ols_extapps_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_extapps_flash = __('ExtApp config saved and OpenLiteSpeed reloaded.');
            $this->loadOlsExtAppsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->ols_extapps_error = $e->getMessage();
        }
    }

    public function openAddOlsExtAppForm(): void
    {
        $this->ols_extapps_show_add = true;
        $this->ols_extapps_new_app = ['name' => '', 'type' => 'lsapi', 'address' => '', 'path' => ''];
        $this->ols_extapps_error = null;
        $this->ols_extapps_flash = null;
    }

    public function cancelAddOlsExtAppForm(): void
    {
        $this->ols_extapps_show_add = false;
        $this->ols_extapps_new_app = ['name' => '', 'type' => 'lsapi', 'address' => '', 'path' => ''];
    }

    public function submitAddOlsExtApp(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->ols_extapps_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->ols_extapps_error = __('Provisioning and SSH must be ready before adding an ExtApp.');

            return;
        }

        $this->ols_extapps_flash = null;
        $this->ols_extapps_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Add OpenLiteSpeed ExtApp: :name', ['name' => trim($this->ols_extapps_new_app['name'] ?? '')]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(OpenLiteSpeedExtAppsConfig::class)
                ->addApp($this->server, $this->ols_extapps_new_app, [], $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_extapps_flash = __('ExtApp :name added and OpenLiteSpeed reloaded.', ['name' => $this->ols_extapps_new_app['name']]);
            $this->ols_extapps_show_add = false;
            $this->ols_extapps_new_app = ['name' => '', 'type' => 'lsapi', 'address' => '', 'path' => ''];
            $this->loadOlsExtAppsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->ols_extapps_error = $e->getMessage();
        }
    }

    public function loadCaddyGlobalsConfig(bool $forceFresh = false): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->caddy_globals_error = __('Provisioning and SSH must be ready before reading the Caddyfile.');

            return;
        }

        if ($this->caddy_globals_loaded && ! $forceFresh) {
            return;
        }

        try {
            $result = app(CaddyGlobalOptionsConfig::class)->read($this->server);
            $this->caddy_globals_form = $result['values'];
            $this->caddy_globals_loaded = true;
            $this->caddy_globals_flash = null;
            $this->caddy_globals_error = null;
            if (! empty($result['unreadable'])) {
                $this->caddy_globals_error = __('Could not read /etc/caddy/Caddyfile — check sudo permissions for the deploy user.');
            } elseif (! $result['exists']) {
                $this->caddy_globals_flash = __('No global options block found — defaults shown. Save to inject one at the top of the Caddyfile.');
            }
        } catch (\Throwable $e) {
            $this->caddy_globals_error = __('Failed to read Caddy globals: :msg', ['msg' => $e->getMessage()]);
            $this->caddy_globals_loaded = false;
        }
    }

    public function saveCaddyGlobalsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->caddy_globals_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->caddy_globals_error = __('Provisioning and SSH must be ready before saving the Caddyfile.');

            return;
        }

        $this->caddy_globals_flash = null;
        $this->caddy_globals_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save Caddy global options'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(CaddyGlobalOptionsConfig::class)
                ->save($this->server, $this->caddy_globals_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->caddy_globals_flash = __('Caddy global options saved and Caddy reloaded.');
            $this->loadCaddyGlobalsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->caddy_globals_error = $e->getMessage();
        }
    }

    /**
     * Run the smoke test for every Site on this server through the active
     * webserver via localhost. Banner-streamed because this can take a few
     * seconds on servers with lots of sites — queueing via a job would be
     * overkill here since each curl is capped at 4s and the total is
     * bounded.
     */
    public function runSmokeTest(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->smoke_error = __('Provisioning and SSH must be ready before running the smoke test.');

            return;
        }

        $this->smoke_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Site smoke test'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            $result = app(\App\Services\Servers\WebserverSmokeTestRunner::class)->run($this->server, $emitter);
            // Serialize Carbon to ISO string for Livewire state.
            $this->smoke_results = array_map(function (array $row): array {
                return $row;
            }, $result['results']);
            $this->smoke_scanned_at_iso = $result['scanned_at']->toIso8601String();
            $this->smoke_total_sites = (int) $result['total_sites'];
            $this->smoke_probed = (int) $result['probed'];
            $this->smoke_truncated = (bool) $result['truncated'];
            $this->smoke_loaded = true;

            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->smoke_error = $e->getMessage();
        }
    }

    public function loadDriftDetector(bool $forceFresh = false): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->drift_error = __('Provisioning and SSH must be ready before checking config drift.');

            return;
        }

        try {
            $result = app(WebserverConfigDriftDetector::class)->detect($this->server, $forceFresh);
            $this->drift_results = $result['results'];
            $this->drift_engine = $result['engine'];
            $this->drift_scanned_at_iso = $result['scanned_at']->toIso8601String();
            $this->drift_total_sites = (int) $result['total_sites'];
            $this->drift_count = (int) $result['drifted_count'];
            $this->drift_truncated = (bool) $result['truncated'];
            $this->drift_unsupported = (bool) $result['unsupported'];
            $this->drift_loaded = true;
            $this->drift_error = null;
        } catch (\Throwable $e) {
            $this->drift_error = __('Failed to detect drift: :msg', ['msg' => $e->getMessage()]);
            $this->drift_loaded = false;
        }
    }

    public function refreshDriftDetector(): void
    {
        $this->loadDriftDetector(forceFresh: true);
    }


    public function loadHaproxyBackendsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->haproxy_backends_error = __('Provisioning and SSH must be ready before reading haproxy.cfg.');

            return;
        }

        try {
            $result = app(HaproxyBackendsConfig::class)->read($this->server);
            $form = [];
            $serversText = [];
            foreach ($result['backends'] as $b) {
                $form[$b['name']] = ['servers' => $b['servers'], 'values' => $b['values']];
                $serversText[$b['name']] = implode("\n", $b['servers']);
            }
            $this->haproxy_backends_form = $form;
            $this->haproxy_backends_servers_text = $serversText;
            $this->haproxy_backends_loaded = true;
            $this->haproxy_backends_flash = null;
            $this->haproxy_backends_error = null;
            if (! empty($result['unreadable'])) {
                $this->haproxy_backends_error = __('Could not read /etc/haproxy/haproxy.cfg — check sudo permissions for the deploy user.');
            } elseif (empty($result['backends'])) {
                $this->haproxy_backends_flash = __('No `backend <name>` blocks found in haproxy.cfg yet.');
            }
        } catch (\Throwable $e) {
            $this->haproxy_backends_error = __('Failed to read backends: :msg', ['msg' => $e->getMessage()]);
            $this->haproxy_backends_loaded = false;
        }
    }

    public function saveHaproxyBackendsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_backends_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_backends_error = __('Provisioning and SSH must be ready before saving haproxy.cfg.');

            return;
        }

        $this->haproxy_backends_flash = null;
        $this->haproxy_backends_error = null;

        foreach ($this->haproxy_backends_servers_text as $name => $text) {
            if (! isset($this->haproxy_backends_form[$name])) {
                continue;
            }
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text) ?: []), fn (string $l) => $l !== ''));
            $this->haproxy_backends_form[$name]['servers'] = $lines;
        }

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save HAProxy backends'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(HaproxyBackendsConfig::class)
                ->save($this->server, $this->haproxy_backends_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_backends_flash = __('Backends saved and HAProxy reloaded.');
            $this->loadHaproxyBackendsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_backends_error = $e->getMessage();
        }
    }

    public function openAddHaproxyBackendForm(): void
    {
        $this->haproxy_backends_show_add = true;
        $this->haproxy_backends_new = ['name' => '', 'servers' => '', 'balance' => 'roundrobin'];
        $this->haproxy_backends_error = null;
        $this->haproxy_backends_flash = null;
    }

    public function cancelAddHaproxyBackendForm(): void
    {
        $this->haproxy_backends_show_add = false;
        $this->haproxy_backends_new = ['name' => '', 'servers' => '', 'balance' => 'roundrobin'];
    }

    public function submitAddHaproxyBackend(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_backends_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_backends_error = __('Provisioning and SSH must be ready before adding a backend.');

            return;
        }

        $this->haproxy_backends_flash = null;
        $this->haproxy_backends_error = null;

        $name = (string) ($this->haproxy_backends_new['name'] ?? '');
        $servers = array_values(array_filter(
            array_map('trim', preg_split('/\R/', (string) ($this->haproxy_backends_new['servers'] ?? '')) ?: []),
            fn (string $l) => $l !== '',
        ));
        $balance = trim((string) ($this->haproxy_backends_new['balance'] ?? 'roundrobin'));

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Add HAProxy backend: :name', ['name' => trim($name)]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            $values = ['balance' => $balance];
            app(HaproxyBackendsConfig::class)
                ->addBackend($this->server, $name, $servers, $values, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_backends_flash = __('Backend :name added and HAProxy reloaded.', ['name' => $name]);
            $this->haproxy_backends_show_add = false;
            $this->haproxy_backends_new = ['name' => '', 'servers' => '', 'balance' => 'roundrobin'];
            $this->loadHaproxyBackendsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_backends_error = $e->getMessage();
        }
    }

    public function removeHaproxyBackend(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_backends_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_backends_error = __('Provisioning and SSH must be ready before removing a backend.');

            return;
        }

        $this->haproxy_backends_flash = null;
        $this->haproxy_backends_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Remove HAProxy backend: :name', ['name' => $name]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(HaproxyBackendsConfig::class)
                ->removeBackend($this->server, $name, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_backends_flash = __('Backend :name removed and HAProxy reloaded.', ['name' => $name]);
            $this->loadHaproxyBackendsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_backends_error = $e->getMessage();
        }
    }

    public function loadHaproxyFrontendsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->haproxy_frontends_error = __('Provisioning and SSH must be ready before reading haproxy.cfg.');

            return;
        }

        try {
            $result = app(HaproxyFrontendsConfig::class)->read($this->server);
            $form = [];
            $bindsText = [];
            foreach ($result['frontends'] as $f) {
                $form[$f['name']] = ['binds' => $f['binds'], 'values' => $f['values']];
                $bindsText[$f['name']] = implode("\n", $f['binds']);
            }
            $this->haproxy_frontends_form = $form;
            $this->haproxy_frontends_binds_text = $bindsText;
            $this->haproxy_frontends_loaded = true;
            $this->haproxy_frontends_flash = null;
            $this->haproxy_frontends_error = null;
            if (! empty($result['unreadable'])) {
                $this->haproxy_frontends_error = __('Could not read /etc/haproxy/haproxy.cfg — check sudo permissions for the deploy user.');
            } elseif (empty($result['frontends'])) {
                $this->haproxy_frontends_flash = __('No `frontend <name>` blocks found in haproxy.cfg yet.');
            }
        } catch (\Throwable $e) {
            $this->haproxy_frontends_error = __('Failed to read frontends: :msg', ['msg' => $e->getMessage()]);
            $this->haproxy_frontends_loaded = false;
        }
    }

    public function saveHaproxyFrontendsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_frontends_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_frontends_error = __('Provisioning and SSH must be ready before saving haproxy.cfg.');

            return;
        }

        $this->haproxy_frontends_flash = null;
        $this->haproxy_frontends_error = null;

        // Sync binds from textarea mirror back into the form payload.
        foreach ($this->haproxy_frontends_binds_text as $name => $text) {
            if (! isset($this->haproxy_frontends_form[$name])) {
                continue;
            }
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text) ?: []), fn (string $l) => $l !== ''));
            $this->haproxy_frontends_form[$name]['binds'] = $lines;
        }

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save HAProxy frontends'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(HaproxyFrontendsConfig::class)
                ->save($this->server, $this->haproxy_frontends_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_frontends_flash = __('Frontends saved and HAProxy reloaded.');
            $this->loadHaproxyFrontendsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_frontends_error = $e->getMessage();
        }
    }

    public function openAddHaproxyFrontendForm(): void
    {
        $this->haproxy_frontends_show_add = true;
        $this->haproxy_frontends_new = ['name' => '', 'binds' => '', 'default_backend' => ''];
        $this->haproxy_frontends_error = null;
        $this->haproxy_frontends_flash = null;
    }

    public function cancelAddHaproxyFrontendForm(): void
    {
        $this->haproxy_frontends_show_add = false;
        $this->haproxy_frontends_new = ['name' => '', 'binds' => '', 'default_backend' => ''];
    }

    public function submitAddHaproxyFrontend(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_frontends_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_frontends_error = __('Provisioning and SSH must be ready before adding a frontend.');

            return;
        }

        $this->haproxy_frontends_flash = null;
        $this->haproxy_frontends_error = null;

        $name = (string) ($this->haproxy_frontends_new['name'] ?? '');
        $binds = array_values(array_filter(
            array_map('trim', preg_split('/\R/', (string) ($this->haproxy_frontends_new['binds'] ?? '')) ?: []),
            fn (string $l) => $l !== '',
        ));
        $defaultBackend = trim((string) ($this->haproxy_frontends_new['default_backend'] ?? ''));

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Add HAProxy frontend: :name', ['name' => trim($name)]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            $values = [];
            if ($defaultBackend !== '') {
                $values['default_backend'] = $defaultBackend;
            }
            app(HaproxyFrontendsConfig::class)
                ->addFrontend($this->server, $name, $binds, $values, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_frontends_flash = __('Frontend :name added and HAProxy reloaded.', ['name' => $name]);
            $this->haproxy_frontends_show_add = false;
            $this->haproxy_frontends_new = ['name' => '', 'binds' => '', 'default_backend' => ''];
            $this->loadHaproxyFrontendsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_frontends_error = $e->getMessage();
        }
    }

    public function removeHaproxyFrontend(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_frontends_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_frontends_error = __('Provisioning and SSH must be ready before removing a frontend.');

            return;
        }

        $this->haproxy_frontends_flash = null;
        $this->haproxy_frontends_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Remove HAProxy frontend: :name', ['name' => $name]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(HaproxyFrontendsConfig::class)
                ->removeFrontend($this->server, $name, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_frontends_flash = __('Frontend :name removed and HAProxy reloaded.', ['name' => $name]);
            $this->loadHaproxyFrontendsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_frontends_error = $e->getMessage();
        }
    }

    public function loadTraefikStaticConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->traefik_static_error = __('Provisioning and SSH must be ready before reading traefik.yml.');

            return;
        }

        try {
            $result = app(TraefikStaticConfigOptions::class)->read($this->server);
            $this->traefik_static_form = $result['values'];
            $this->traefik_static_loaded = true;
            $this->traefik_static_flash = null;
            $this->traefik_static_error = null;
            if (! empty($result['unreadable'])) {
                $this->traefik_static_error = __('Could not read /etc/traefik/traefik.yml — check sudo permissions or that the YAML is valid.');
            }
        } catch (\Throwable $e) {
            $this->traefik_static_error = __('Failed to read Traefik static config: :msg', ['msg' => $e->getMessage()]);
            $this->traefik_static_loaded = false;
        }
    }

    public function repairTraefikAdminApi(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot repair Traefik config.'));

            return;
        }

        if ($this->server->edgeProxy() !== 'traefik') {
            $this->toastError(__('This server does not have Traefik as its edge proxy.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready.'));

            return;
        }

        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Repair Traefik API entry point'));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(TraefikStaticConfigOptions::class)
                ->repairAdminApiDefaults($this->server, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->toastSuccess(__('Traefik static config reset to dply defaults. Try the dashboard link again.'));
            $this->loadTraefikStaticConfig();
            $this->refreshTraefikLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->toastError($e->getMessage());
        }
    }

    public function startTraefikService(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot start Traefik.'));

            return;
        }

        if ($this->server->edgeProxy() !== 'traefik') {
            $this->toastError(__('This server does not have Traefik as its edge proxy.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready.'));

            return;
        }

        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Start Traefik'));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(TraefikStaticConfigOptions::class)
                ->startTraefikService($this->server, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->toastSuccess(__('Traefik is running. Try the dashboard link again.'));
            $this->refreshTraefikLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->toastError($e->getMessage());
        }
    }

    private function refreshTraefikLiveStateAfterServiceAction(): void
    {
        if ($this->server->edgeProxy() !== 'traefik') {
            return;
        }

        $this->ensureEngineLiveState(forceFresh: true);
    }

    public function saveTraefikStaticConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->traefik_static_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->traefik_static_error = __('Provisioning and SSH must be ready before saving traefik.yml.');

            return;
        }

        $this->traefik_static_flash = null;
        $this->traefik_static_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save Traefik static config (restart required)'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(TraefikStaticConfigOptions::class)
                ->save($this->server, $this->traefik_static_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->traefik_static_flash = __('Traefik static config saved and Traefik restarted.');
            $this->loadTraefikStaticConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->traefik_static_error = $e->getMessage();
        }
    }

    public function loadTraefikDynamicConfigs(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->traefik_dynamic_error = __('Provisioning and SSH must be ready before listing dynamic config files.');

            return;
        }

        try {
            $this->traefik_dynamic_files = app(TraefikDynamicConfigInventory::class)->list($this->server);
            $this->traefik_dynamic_loaded = true;
            $this->traefik_dynamic_error = null;
        } catch (\Throwable $e) {
            $this->traefik_dynamic_error = __('Failed to list dynamic configs: :msg', ['msg' => $e->getMessage()]);
            $this->traefik_dynamic_loaded = false;
        }
    }

    public function loadTraefikProvidersConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            $this->traefik_providers_error = __('Provisioning and SSH must be ready before reading providers.');

            return;
        }
        try {
            $result = app(TraefikProvidersConfig::class)->read($this->server);
            $this->traefik_providers_form = $result['values'];
            $this->traefik_providers_configured = $result['configured'];
            $this->traefik_providers_loaded = true;
            $this->traefik_providers_flash = null;
            $this->traefik_providers_error = $result['unreadable']
                ? __('Could not read traefik.yml.')
                : null;
        } catch (\Throwable $e) {
            $this->traefik_providers_error = $e->getMessage();
            $this->traefik_providers_loaded = false;
        }
    }

    public function saveTraefikProvidersConfig(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->traefik_providers_error = __('Deployers cannot edit server config.');

            return;
        }
        if (! $this->serverOpsReady()) {
            $this->traefik_providers_error = __('Provisioning and SSH must be ready.');

            return;
        }
        $this->traefik_providers_flash = null;
        $this->traefik_providers_error = null;
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Traefik providers'));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikProvidersConfig::class)->save($this->server, $this->traefik_providers_form, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_providers_flash = __('Providers saved and Traefik restarted.');
            $this->loadTraefikProvidersConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_providers_error = $e->getMessage();
        }
    }

    public function installTraefikDockerProvider(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->traefik_providers_error = __('Deployers cannot install packages.');

            return;
        }
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Install Docker for Traefik provider'));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikProvidersConfig::class)->installDocker($this->server, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_providers_flash = __('Docker installed. Enable the Docker provider below and save.');
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_providers_error = $e->getMessage();
        }
    }

    public function loadTraefikDashboardConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            return;
        }
        try {
            $state = app(TraefikDashboardExposure::class)->read($this->server);
            $this->traefik_dashboard_form = [
                'enabled' => $state['enabled'] ? '1' : '0',
                'path' => $state['path'],
                'username' => $state['username'],
                'password' => '',
            ];
            $this->traefik_dashboard_loaded = true;
            $this->traefik_dashboard_error = null;
        } catch (\Throwable $e) {
            $this->traefik_dashboard_error = $e->getMessage();
        }
    }

    public function saveTraefikDashboardConfig(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->traefik_dashboard_error = __('Deployers cannot edit server config.');

            return;
        }
        if (! $this->serverOpsReady()) {
            $this->traefik_dashboard_error = __('Provisioning and SSH must be ready.');

            return;
        }
        $this->traefik_dashboard_flash = null;
        $this->traefik_dashboard_error = null;
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Update Traefik dashboard exposure'));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikDashboardExposure::class)->sync($this->server, [
                'enabled' => ($this->traefik_dashboard_form['enabled'] ?? '0') === '1',
                'path' => (string) ($this->traefik_dashboard_form['path'] ?? '/traefik-dashboard'),
                'username' => (string) ($this->traefik_dashboard_form['username'] ?? ''),
                'password' => (string) ($this->traefik_dashboard_form['password'] ?? ''),
            ], new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_dashboard_flash = __('Dashboard exposure updated.');
            $this->traefik_dashboard_form['password'] = '';
            $this->loadTraefikDashboardConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_dashboard_error = $e->getMessage();
        }
    }

    public function loadTraefikCustomRoutesConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            $this->traefik_custom_routes_error = __('Provisioning and SSH must be ready.');

            return;
        }
        try {
            $result = app(TraefikCustomRoutesConfig::class)->read($this->server);
            $form = [];
            foreach ($result['routes'] as $route) {
                $slug = (string) ($route['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $form[$slug] = [
                    'hosts' => implode(' ', $route['hosts'] ?? []),
                    'upstream' => (string) ($route['upstream'] ?? ''),
                    'rule' => (string) ($route['rule'] ?? ''),
                    'middlewares' => implode(' ', $route['middlewares'] ?? []),
                ];
            }
            $this->traefik_custom_routes_form = $form;
            $this->traefik_custom_routes_loaded = true;
            $this->traefik_custom_routes_error = $result['unreadable'] ? __('Could not read custom route files.') : null;
        } catch (\Throwable $e) {
            $this->traefik_custom_routes_error = $e->getMessage();
            $this->traefik_custom_routes_loaded = false;
        }
    }

    public function openAddTraefikCustomRouteForm(): void
    {
        $this->traefik_custom_routes_show_add = true;
        $this->traefik_custom_routes_new = ['slug' => '', 'hosts' => '', 'upstream' => '', 'rule' => '', 'middlewares' => ''];
    }

    public function cancelAddTraefikCustomRouteForm(): void
    {
        $this->traefik_custom_routes_show_add = false;
    }

    public function submitAddTraefikCustomRoute(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            $this->traefik_custom_routes_error = __('Cannot add route right now.');

            return;
        }
        $slug = (string) ($this->traefik_custom_routes_new['slug'] ?? '');
        $fields = $this->traefikCustomRouteFieldsFromRow($this->traefik_custom_routes_new);
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add Traefik custom route: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikCustomRoutesConfig::class)->add($this->server, $slug, $fields, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_custom_routes_flash = __('Custom route added.');
            $this->traefik_custom_routes_show_add = false;
            $this->loadTraefikCustomRoutesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_custom_routes_error = $e->getMessage();
        }
    }

    public function saveTraefikCustomRoute(string $slug): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! isset($this->traefik_custom_routes_form[$slug])) {
            return;
        }
        $fields = $this->traefikCustomRouteFieldsFromRow($this->traefik_custom_routes_form[$slug]);
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Traefik custom route: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikCustomRoutesConfig::class)->save($this->server, $slug, $fields, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_custom_routes_flash = __('Route saved.');
            $this->loadTraefikCustomRoutesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_custom_routes_error = $e->getMessage();
        }
    }

    public function removeTraefikCustomRoute(string $slug): void
    {
        $this->authorize('update', $this->server);
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove Traefik custom route: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikCustomRoutesConfig::class)->remove($this->server, $slug, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_custom_routes_flash = __('Route removed.');
            $this->loadTraefikCustomRoutesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_custom_routes_error = $e->getMessage();
        }
    }

    public function loadTraefikCustomMiddlewaresConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            $this->traefik_custom_middlewares_error = __('Provisioning and SSH must be ready.');

            return;
        }
        try {
            $result = app(TraefikCustomMiddlewaresConfig::class)->read($this->server);
            $form = [];
            foreach ($result['middlewares'] as $row) {
                $slug = (string) ($row['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $form[$slug] = [
                    'type' => (string) ($row['type'] ?? 'stripPrefix'),
                    'prefix' => '/',
                    'scheme' => 'https',
                    'header_key' => '',
                    'header_value' => '',
                    'users' => '',
                ];
            }
            $this->traefik_custom_middlewares_form = $form;
            $this->traefik_custom_middlewares_loaded = true;
            $this->traefik_custom_middlewares_error = null;
        } catch (\Throwable $e) {
            $this->traefik_custom_middlewares_error = $e->getMessage();
            $this->traefik_custom_middlewares_loaded = false;
        }
    }

    public function openAddTraefikCustomMiddlewareForm(): void
    {
        $this->traefik_custom_middlewares_show_add = true;
        $this->traefik_custom_middlewares_new = [
            'slug' => '', 'type' => 'stripPrefix', 'prefix' => '/', 'scheme' => 'https',
            'header_key' => '', 'header_value' => '', 'users' => '',
        ];
    }

    public function cancelAddTraefikCustomMiddlewareForm(): void
    {
        $this->traefik_custom_middlewares_show_add = false;
    }

    public function submitAddTraefikCustomMiddleware(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $slug = (string) ($this->traefik_custom_middlewares_new['slug'] ?? '');
        $fields = $this->traefikCustomMiddlewareFieldsFromRow($this->traefik_custom_middlewares_new);
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add Traefik middleware: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikCustomMiddlewaresConfig::class)->add($this->server, $slug, $fields, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_custom_middlewares_flash = __('Middleware added.');
            $this->traefik_custom_middlewares_show_add = false;
            $this->loadTraefikCustomMiddlewaresConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_custom_middlewares_error = $e->getMessage();
        }
    }

    public function saveTraefikCustomMiddleware(string $slug): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! isset($this->traefik_custom_middlewares_form[$slug])) {
            return;
        }
        $fields = $this->traefikCustomMiddlewareFieldsFromRow($this->traefik_custom_middlewares_form[$slug]);
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Traefik middleware: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikCustomMiddlewaresConfig::class)->save($this->server, $slug, $fields, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_custom_middlewares_flash = __('Middleware saved.');
            $this->loadTraefikCustomMiddlewaresConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_custom_middlewares_error = $e->getMessage();
        }
    }

    public function removeTraefikCustomMiddleware(string $slug): void
    {
        $this->authorize('update', $this->server);
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove Traefik middleware: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikCustomMiddlewaresConfig::class)->remove($this->server, $slug, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_custom_middlewares_flash = __('Middleware removed.');
            $this->loadTraefikCustomMiddlewaresConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_custom_middlewares_error = $e->getMessage();
        }
    }

    /**
     * @param  array{hosts?: string, upstream?: string, rule?: string, middlewares?: string}  $row
     * @return array{hosts: string, upstream: string, rule?: string, middlewares?: string}
     */
    private function traefikCustomRouteFieldsFromRow(array $row): array
    {
        return [
            'hosts' => (string) ($row['hosts'] ?? ''),
            'upstream' => (string) ($row['upstream'] ?? ''),
            'rule' => trim((string) ($row['rule'] ?? '')),
            'middlewares' => trim((string) ($row['middlewares'] ?? '')),
        ];
    }

    /**
     * @param  array{type?: string, prefix?: string, scheme?: string, header_key?: string, header_value?: string, users?: string}  $row
     * @return array{type: string, prefix?: string, scheme?: string, header_key?: string, header_value?: string, users?: string}
     */
    private function traefikCustomMiddlewareFieldsFromRow(array $row): array
    {
        return [
            'type' => (string) ($row['type'] ?? 'stripPrefix'),
            'prefix' => (string) ($row['prefix'] ?? '/'),
            'scheme' => (string) ($row['scheme'] ?? 'https'),
            'header_key' => (string) ($row['header_key'] ?? ''),
            'header_value' => (string) ($row['header_value'] ?? ''),
            'users' => (string) ($row['users'] ?? ''),
        ];
    }

    public function loadTraefikEntrypointsConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            $this->traefik_entrypoints_error = __('Provisioning and SSH must be ready.');

            return;
        }
        try {
            $result = app(TraefikEntrypointsConfig::class)->read($this->server);
            $form = [];
            foreach ($result['entrypoints'] as $ep) {
                $form[(string) $ep['name']] = ['name' => (string) $ep['name'], 'address' => (string) $ep['address']];
            }
            $this->traefik_entrypoints_form = $form;
            $this->traefik_entrypoints_loaded = true;
            $this->traefik_entrypoints_error = $result['unreadable'] ? __('Could not read traefik.yml.') : null;
        } catch (\Throwable $e) {
            $this->traefik_entrypoints_error = $e->getMessage();
            $this->traefik_entrypoints_loaded = false;
        }
    }

    public function openAddTraefikEntrypointForm(): void
    {
        $this->traefik_entrypoints_show_add = true;
        $this->traefik_entrypoints_new = ['name' => '', 'address' => ':8080'];
    }

    public function cancelAddTraefikEntrypointForm(): void
    {
        $this->traefik_entrypoints_show_add = false;
    }

    public function submitAddTraefikEntrypoint(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $name = (string) ($this->traefik_entrypoints_new['name'] ?? '');
        $address = (string) ($this->traefik_entrypoints_new['address'] ?? '');
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add Traefik entry point: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikEntrypointsConfig::class)->add($this->server, $name, $address, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_entrypoints_flash = __('Entry point added.');
            $this->traefik_entrypoints_show_add = false;
            $this->loadTraefikEntrypointsConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_entrypoints_error = $e->getMessage();
        }
    }

    public function saveTraefikEntrypoint(string $name): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! isset($this->traefik_entrypoints_form[$name])) {
            return;
        }
        $address = (string) ($this->traefik_entrypoints_form[$name]['address'] ?? '');
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Traefik entry point: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikEntrypointsConfig::class)->save($this->server, $name, $address, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_entrypoints_flash = __('Entry point saved.');
            $this->loadTraefikEntrypointsConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_entrypoints_error = $e->getMessage();
        }
    }

    public function removeTraefikEntrypoint(string $name): void
    {
        $this->authorize('update', $this->server);
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove Traefik entry point: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikEntrypointsConfig::class)->remove($this->server, $name, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_entrypoints_flash = __('Entry point removed.');
            $this->loadTraefikEntrypointsConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_entrypoints_error = $e->getMessage();
        }
    }

    public function loadTraefikTcpRoutesConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            return;
        }
        try {
            $result = app(TraefikTcpRoutesConfig::class)->read($this->server);
            $form = [];
            foreach ($result['routes'] as $route) {
                $slug = (string) ($route['slug'] ?? '');
                $form[$slug] = [
                    'rule' => (string) ($route['rule'] ?? ''),
                    'entry_points' => implode(' ', $route['entry_points'] ?? []),
                    'server_address' => (string) ($route['server_address'] ?? ''),
                ];
            }
            $this->traefik_tcp_routes_form = $form;
            $this->traefik_tcp_routes_loaded = true;
            $this->traefik_tcp_routes_error = null;
        } catch (\Throwable $e) {
            $this->traefik_tcp_routes_error = $e->getMessage();
            $this->traefik_tcp_routes_loaded = false;
        }
    }

    public function openAddTraefikTcpRouteForm(): void
    {
        $this->traefik_tcp_routes_show_add = true;
    }

    public function cancelAddTraefikTcpRouteForm(): void
    {
        $this->traefik_tcp_routes_show_add = false;
    }

    public function submitAddTraefikTcpRoute(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $slug = (string) ($this->traefik_tcp_routes_new['slug'] ?? '');
        $fields = [
            'rule' => (string) ($this->traefik_tcp_routes_new['rule'] ?? ''),
            'entry_points' => (string) ($this->traefik_tcp_routes_new['entry_points'] ?? ''),
            'server_address' => (string) ($this->traefik_tcp_routes_new['server_address'] ?? ''),
        ];
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add Traefik TCP route: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikTcpRoutesConfig::class)->add($this->server, $slug, $fields, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_tcp_routes_flash = __('TCP route added.');
            $this->traefik_tcp_routes_show_add = false;
            $this->loadTraefikTcpRoutesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_tcp_routes_error = $e->getMessage();
        }
    }

    public function saveTraefikTcpRoute(string $slug): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! isset($this->traefik_tcp_routes_form[$slug])) {
            return;
        }
        $row = $this->traefik_tcp_routes_form[$slug];
        $fields = ['rule' => (string) ($row['rule'] ?? ''), 'entry_points' => (string) ($row['entry_points'] ?? ''), 'server_address' => (string) ($row['server_address'] ?? '')];
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Traefik TCP route: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikTcpRoutesConfig::class)->save($this->server, $slug, $fields, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_tcp_routes_flash = __('TCP route saved.');
            $this->loadTraefikTcpRoutesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_tcp_routes_error = $e->getMessage();
        }
    }

    public function removeTraefikTcpRoute(string $slug): void
    {
        $this->authorize('update', $this->server);
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove Traefik TCP route: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikTcpRoutesConfig::class)->remove($this->server, $slug, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_tcp_routes_flash = __('TCP route removed.');
            $this->loadTraefikTcpRoutesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_tcp_routes_error = $e->getMessage();
        }
    }

    public function loadTraefikUdpRoutesConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            return;
        }
        try {
            $result = app(TraefikUdpRoutesConfig::class)->read($this->server);
            $form = [];
            foreach ($result['routes'] as $route) {
                $slug = (string) ($route['slug'] ?? '');
                $form[$slug] = [
                    'entry_points' => implode(' ', $route['entry_points'] ?? []),
                    'server_address' => (string) ($route['server_address'] ?? ''),
                ];
            }
            $this->traefik_udp_routes_form = $form;
            $this->traefik_udp_routes_loaded = true;
            $this->traefik_udp_routes_error = null;
        } catch (\Throwable $e) {
            $this->traefik_udp_routes_error = $e->getMessage();
            $this->traefik_udp_routes_loaded = false;
        }
    }

    public function openAddTraefikUdpRouteForm(): void
    {
        $this->traefik_udp_routes_show_add = true;
    }

    public function cancelAddTraefikUdpRouteForm(): void
    {
        $this->traefik_udp_routes_show_add = false;
    }

    public function submitAddTraefikUdpRoute(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $slug = (string) ($this->traefik_udp_routes_new['slug'] ?? '');
        $fields = [
            'entry_points' => (string) ($this->traefik_udp_routes_new['entry_points'] ?? ''),
            'server_address' => (string) ($this->traefik_udp_routes_new['server_address'] ?? ''),
        ];
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add Traefik UDP route: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikUdpRoutesConfig::class)->add($this->server, $slug, $fields, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_udp_routes_flash = __('UDP route added.');
            $this->traefik_udp_routes_show_add = false;
            $this->loadTraefikUdpRoutesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_udp_routes_error = $e->getMessage();
        }
    }

    public function saveTraefikUdpRoute(string $slug): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! isset($this->traefik_udp_routes_form[$slug])) {
            return;
        }
        $row = $this->traefik_udp_routes_form[$slug];
        $fields = ['entry_points' => (string) ($row['entry_points'] ?? ''), 'server_address' => (string) ($row['server_address'] ?? '')];
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Traefik UDP route: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikUdpRoutesConfig::class)->save($this->server, $slug, $fields, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_udp_routes_flash = __('UDP route saved.');
            $this->loadTraefikUdpRoutesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_udp_routes_error = $e->getMessage();
        }
    }

    public function removeTraefikUdpRoute(string $slug): void
    {
        $this->authorize('update', $this->server);
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove Traefik UDP route: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikUdpRoutesConfig::class)->remove($this->server, $slug, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_udp_routes_flash = __('UDP route removed.');
            $this->loadTraefikUdpRoutesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_udp_routes_error = $e->getMessage();
        }
    }

    public function loadTraefikHttpServicesConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            return;
        }
        try {
            $result = app(TraefikHttpServicesConfig::class)->read($this->server);
            $form = [];
            foreach ($result['services'] as $svc) {
                $slug = (string) ($svc['slug'] ?? '');
                $form[$slug] = ['servers' => implode("\n", $svc['servers'] ?? [])];
            }
            $this->traefik_http_services_form = $form;
            $this->traefik_http_services_loaded = true;
            $this->traefik_http_services_error = null;
        } catch (\Throwable $e) {
            $this->traefik_http_services_error = $e->getMessage();
            $this->traefik_http_services_loaded = false;
        }
    }

    public function openAddTraefikHttpServiceForm(): void
    {
        $this->traefik_http_services_show_add = true;
        $this->traefik_http_services_new = ['slug' => '', 'servers' => ''];
    }

    public function cancelAddTraefikHttpServiceForm(): void
    {
        $this->traefik_http_services_show_add = false;
    }

    public function submitAddTraefikHttpService(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $slug = (string) ($this->traefik_http_services_new['slug'] ?? '');
        $fields = ['servers' => (string) ($this->traefik_http_services_new['servers'] ?? '')];
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add Traefik HTTP service: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikHttpServicesConfig::class)->add($this->server, $slug, $fields, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_http_services_flash = __('HTTP service added.');
            $this->traefik_http_services_show_add = false;
            $this->loadTraefikHttpServicesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_http_services_error = $e->getMessage();
        }
    }

    public function saveTraefikHttpService(string $slug): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! isset($this->traefik_http_services_form[$slug])) {
            return;
        }
        $fields = ['servers' => (string) ($this->traefik_http_services_form[$slug]['servers'] ?? '')];
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Traefik HTTP service: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikHttpServicesConfig::class)->save($this->server, $slug, $fields, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_http_services_flash = __('HTTP service saved.');
            $this->loadTraefikHttpServicesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_http_services_error = $e->getMessage();
        }
    }

    public function removeTraefikHttpService(string $slug): void
    {
        $this->authorize('update', $this->server);
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove Traefik HTTP service: :slug', ['slug' => $slug]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(TraefikHttpServicesConfig::class)->remove($this->server, $slug, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->traefik_http_services_flash = __('HTTP service removed.');
            $this->loadTraefikHttpServicesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->traefik_http_services_error = $e->getMessage();
        }
    }

    public function loadEnvoyStaticConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->envoy_static_error = __('Provisioning and SSH must be ready before reading envoy.yaml.');

            return;
        }

        try {
            $result = app(EnvoyStaticConfigOptions::class)->read($this->server);
            $this->envoy_static_form = $result['values'];
            $this->envoy_static_loaded = true;
            $this->envoy_static_flash = null;
            $this->envoy_static_error = $result['unreadable']
                ? __('Could not read /etc/envoy/envoy.yaml — check sudo permissions for the deploy user.')
                : null;
        } catch (\Throwable $e) {
            $this->envoy_static_error = __('Failed to read Envoy static config: :msg', ['msg' => $e->getMessage()]);
            $this->envoy_static_loaded = false;
        }
    }

    public function saveEnvoyStaticConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->envoy_static_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->envoy_static_error = __('Provisioning and SSH must be ready before saving envoy.yaml.');

            return;
        }

        $this->envoy_static_flash = null;
        $this->envoy_static_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save Envoy static settings'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(EnvoyStaticConfigOptions::class)
                ->save($this->server, $this->envoy_static_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->envoy_static_flash = __('Envoy static settings saved and Envoy restarted.');
            $this->loadEnvoyStaticConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_static_error = $e->getMessage();
        }
    }

    public function repairEnvoyAdminApi(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot repair Envoy config.'));

            return;
        }

        if ($this->server->edgeProxy() !== 'envoy') {
            $this->toastError(__('This server does not have Envoy as its edge proxy.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready.'));

            return;
        }

        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Repair Envoy admin interface'));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyStaticConfigOptions::class)
                ->repairAdminDefaults($this->server, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->toastSuccess(__('Envoy admin defaults restored on 127.0.0.1:9901.'));
            $this->loadEnvoyStaticConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->toastError($e->getMessage());
        }
    }

    public function startEnvoyService(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot start Envoy.'));

            return;
        }

        if ($this->server->edgeProxy() !== 'envoy') {
            $this->toastError(__('This server does not have Envoy as its edge proxy.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready.'));

            return;
        }

        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Start Envoy'));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyStaticConfigOptions::class)
                ->startEnvoyService($this->server, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->toastSuccess(__('Envoy is running. Try the admin link again.'));
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->toastError($e->getMessage());
        }
    }

    public function drainEnvoyListeners(): void
    {
        $this->runEnvoyAdminPostAction(
            __('Drain Envoy listeners'),
            '/drain_listeners?graceful',
            __('Envoy listeners are draining — existing connections finish before new ones are accepted.'),
        );
    }

    public function healthcheckFailEnvoy(): void
    {
        $this->runEnvoyAdminPostAction(
            __('Fail Envoy health checks'),
            '/healthcheck/fail',
            __('Envoy health checks marked failed — upstreams should stop receiving new traffic if wired to health status.'),
        );
    }

    private function runEnvoyAdminPostAction(string $title, string $path, string $successMessage): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot run Envoy maintenance actions.'));

            return;
        }

        if ($this->server->edgeProxy() !== 'envoy' || ! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready.'));

            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), $title);
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            $ssh = new SshConnection($this->server);
            $script = sprintf(
                'curl -4sf --max-time 10 -X POST %s 2>&1 || curl -4sf --max-time 10 %s 2>&1',
                escapeshellarg('http://127.0.0.1:9901'.$path),
                escapeshellarg('http://127.0.0.1:9901'.$path),
            );
            $output = $ssh->exec($script, 20);
            if (($ssh->lastExecExitCode() ?? 1) !== 0) {
                throw new \RuntimeException(trim((string) $output) !== '' ? trim((string) $output) : 'Envoy admin request failed.');
            }
            $emitter->info(trim((string) $output));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->toastSuccess($successMessage);
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->toastError($e->getMessage());
        }
    }

    private function refreshEnvoyLiveStateAfterServiceAction(): void
    {
        if ($this->server->edgeProxy() !== 'envoy') {
            return;
        }

        $this->ensureEngineLiveState(forceFresh: true);
    }

    public function loadEnvoyClustersConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->envoy_clusters_error = __('Provisioning and SSH must be ready before reading envoy.yaml.');

            return;
        }

        try {
            $clusters = app(EnvoyCustomClustersConfig::class)->read($this->server);
            $form = [];
            $text = [];
            foreach ($clusters as $cluster) {
                $name = (string) ($cluster['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $form[$name] = [
                    'endpoints' => $cluster['endpoints'] ?? [],
                    'values' => [
                        'connect_timeout' => (string) ($cluster['connect_timeout'] ?? '5s'),
                        'lb_policy' => (string) ($cluster['lb_policy'] ?? 'ROUND_ROBIN'),
                    ],
                ];
                $text[$name] = implode("\n", $cluster['endpoints'] ?? []);
            }
            $this->envoy_clusters_form = $form;
            $this->envoy_clusters_endpoints_text = $text;
            $this->envoy_clusters_loaded = true;
            $this->envoy_clusters_flash = null;
            $this->envoy_clusters_error = null;
        } catch (\Throwable $e) {
            $this->envoy_clusters_error = __('Failed to read custom clusters: :msg', ['msg' => $e->getMessage()]);
            $this->envoy_clusters_loaded = false;
        }
    }

    public function saveEnvoyClustersConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->envoy_clusters_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->envoy_clusters_error = __('Provisioning and SSH must be ready.');

            return;
        }

        $clusters = [];
        foreach ($this->envoy_clusters_endpoints_text as $name => $raw) {
            if (! isset($this->envoy_clusters_form[$name])) {
                continue;
            }
            $endpoints = array_values(array_filter(
                array_map('trim', preg_split('/\R/', (string) $raw) ?: []),
                fn (string $line): bool => $line !== '',
            ));
            $values = $this->envoy_clusters_form[$name]['values'] ?? [];
            $clusters[] = [
                'name' => $name,
                'endpoints' => $endpoints,
                'connect_timeout' => (string) ($values['connect_timeout'] ?? '5s'),
                'lb_policy' => (string) ($values['lb_policy'] ?? 'ROUND_ROBIN'),
            ];
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Envoy custom clusters'));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomClustersConfig::class)
                ->save($this->server, $clusters, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_clusters_flash = __('Custom clusters saved and edge routing regenerated.');
            $this->loadEnvoyClustersConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_clusters_error = $e->getMessage();
        }
    }

    public function openAddEnvoyClusterForm(): void
    {
        $this->envoy_clusters_show_add = true;
        $this->envoy_clusters_new = [
            'name' => '',
            'endpoints' => '',
            'connect_timeout' => '5s',
            'lb_policy' => 'ROUND_ROBIN',
        ];
        $this->envoy_clusters_error = null;
        $this->envoy_clusters_flash = null;
    }

    public function cancelAddEnvoyClusterForm(): void
    {
        $this->envoy_clusters_show_add = false;
    }

    public function submitAddEnvoyCluster(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        $name = trim((string) ($this->envoy_clusters_new['name'] ?? ''));
        $endpoints = array_values(array_filter(
            array_map('trim', preg_split('/\R/', (string) ($this->envoy_clusters_new['endpoints'] ?? '')) ?: []),
            fn (string $line): bool => $line !== '',
        ));
        $values = [
            'connect_timeout' => (string) ($this->envoy_clusters_new['connect_timeout'] ?? '5s'),
            'lb_policy' => (string) ($this->envoy_clusters_new['lb_policy'] ?? 'ROUND_ROBIN'),
        ];

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add Envoy cluster: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomClustersConfig::class)
                ->addCluster($this->server, $name, $endpoints, $values, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_clusters_flash = __('Cluster added.');
            $this->envoy_clusters_show_add = false;
            $this->loadEnvoyClustersConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_clusters_error = $e->getMessage();
        }
    }

    public function removeEnvoyCluster(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove Envoy cluster: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomClustersConfig::class)
                ->removeCluster($this->server, $name, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_clusters_flash = __('Cluster removed.');
            $this->loadEnvoyClustersConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_clusters_error = $e->getMessage();
        }
    }

    public function loadEnvoyVirtualHostsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->envoy_virtualhosts_error = __('Provisioning and SSH must be ready before reading virtual hosts.');

            return;
        }

        try {
            $virtualHosts = app(EnvoyCustomVirtualHostsConfig::class)->read($this->server);
            $form = [];
            $text = [];
            foreach ($virtualHosts as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $form[$name] = [
                    'domains' => $row['domains'] ?? [],
                    'cluster' => (string) ($row['cluster'] ?? ''),
                ];
                $text[$name] = implode("\n", $row['domains'] ?? []);
            }
            $this->envoy_virtualhosts_form = $form;
            $this->envoy_virtualhosts_domains_text = $text;
            $this->envoy_virtualhosts_loaded = true;
            $this->envoy_virtualhosts_flash = null;
            $this->envoy_virtualhosts_error = null;
        } catch (\Throwable $e) {
            $this->envoy_virtualhosts_error = __('Failed to read custom virtual hosts: :msg', ['msg' => $e->getMessage()]);
            $this->envoy_virtualhosts_loaded = false;
        }
    }

    public function saveEnvoyVirtualHostsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->envoy_virtualhosts_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->envoy_virtualhosts_error = __('Provisioning and SSH must be ready.');

            return;
        }

        $virtualHosts = [];
        foreach ($this->envoy_virtualhosts_domains_text as $name => $raw) {
            if (! isset($this->envoy_virtualhosts_form[$name])) {
                continue;
            }
            $domains = array_values(array_filter(
                array_map('trim', preg_split('/[\s,]+/', (string) $raw) ?: []),
                fn (string $d): bool => $d !== '',
            ));
            $virtualHosts[] = [
                'name' => $name,
                'domains' => $domains,
                'cluster' => trim((string) ($this->envoy_virtualhosts_form[$name]['cluster'] ?? '')),
            ];
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Envoy custom virtual hosts'));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomVirtualHostsConfig::class)
                ->save($this->server, $virtualHosts, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_virtualhosts_flash = __('Custom virtual hosts saved and edge routing regenerated.');
            $this->loadEnvoyVirtualHostsConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_virtualhosts_error = $e->getMessage();
        }
    }

    public function openAddEnvoyVirtualHostForm(): void
    {
        $this->envoy_virtualhosts_show_add = true;
        $this->envoy_virtualhosts_new = [
            'name' => '',
            'domains' => '',
            'cluster' => '',
        ];
        $this->envoy_virtualhosts_error = null;
        $this->envoy_virtualhosts_flash = null;
    }

    public function cancelAddEnvoyVirtualHostForm(): void
    {
        $this->envoy_virtualhosts_show_add = false;
    }

    public function submitAddEnvoyVirtualHost(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        $name = trim((string) ($this->envoy_virtualhosts_new['name'] ?? ''));
        $domains = array_values(array_filter(
            array_map('trim', preg_split('/[\s,]+/', (string) ($this->envoy_virtualhosts_new['domains'] ?? '')) ?: []),
            fn (string $d): bool => $d !== '',
        ));
        $cluster = trim((string) ($this->envoy_virtualhosts_new['cluster'] ?? ''));

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add Envoy virtual host: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomVirtualHostsConfig::class)
                ->add($this->server, $name, $domains, $cluster, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_virtualhosts_flash = __('Virtual host added.');
            $this->envoy_virtualhosts_show_add = false;
            $this->loadEnvoyVirtualHostsConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_virtualhosts_error = $e->getMessage();
        }
    }

    public function removeEnvoyVirtualHost(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove Envoy virtual host: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomVirtualHostsConfig::class)
                ->remove($this->server, $name, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_virtualhosts_flash = __('Virtual host removed.');
            $this->loadEnvoyVirtualHostsConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_virtualhosts_error = $e->getMessage();
        }
    }

    public function loadEnvoyListenersConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->envoy_listeners_error = __('Provisioning and SSH must be ready before reading listeners.');

            return;
        }

        try {
            $listeners = app(EnvoyCustomListenersConfig::class)->read($this->server);
            $form = [];
            foreach ($listeners as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $form[$name] = [
                    'address' => (string) ($row['address'] ?? '0.0.0.0'),
                    'port' => (int) ($row['port'] ?? 0),
                    'mode' => (string) ($row['mode'] ?? EnvoyCustomListenersConfig::MODE_SHARED),
                    'default_cluster' => (string) ($row['default_cluster'] ?? ''),
                ];
            }
            $this->envoy_listeners_form = $form;
            $this->envoy_listeners_loaded = true;
            $this->envoy_listeners_flash = null;
            $this->envoy_listeners_error = null;
        } catch (\Throwable $e) {
            $this->envoy_listeners_error = __('Failed to read custom listeners: :msg', ['msg' => $e->getMessage()]);
            $this->envoy_listeners_loaded = false;
        }
    }

    public function saveEnvoyListenersConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->envoy_listeners_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->envoy_listeners_error = __('Provisioning and SSH must be ready.');

            return;
        }

        $listeners = [];
        foreach ($this->envoy_listeners_form as $name => $values) {
            $listeners[] = [
                'name' => $name,
                'address' => trim((string) ($values['address'] ?? '0.0.0.0')) ?: '0.0.0.0',
                'port' => (int) ($values['port'] ?? 0),
                'mode' => trim((string) ($values['mode'] ?? EnvoyCustomListenersConfig::MODE_SHARED)),
                'default_cluster' => trim((string) ($values['default_cluster'] ?? '')),
            ];
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Envoy custom listeners'));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomListenersConfig::class)
                ->save($this->server, $listeners, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_listeners_flash = __('Custom listeners saved and edge routing regenerated.');
            $this->loadEnvoyListenersConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_listeners_error = $e->getMessage();
        }
    }

    public function openAddEnvoyListenerForm(): void
    {
        $this->envoy_listeners_show_add = true;
        $this->envoy_listeners_new = [
            'name' => '',
            'address' => '0.0.0.0',
            'port' => '',
            'mode' => EnvoyCustomListenersConfig::MODE_SHARED,
            'default_cluster' => '',
        ];
        $this->envoy_listeners_error = null;
        $this->envoy_listeners_flash = null;
    }

    public function cancelAddEnvoyListenerForm(): void
    {
        $this->envoy_listeners_show_add = false;
    }

    public function submitAddEnvoyListener(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        $fields = [
            'name' => trim((string) ($this->envoy_listeners_new['name'] ?? '')),
            'address' => trim((string) ($this->envoy_listeners_new['address'] ?? '0.0.0.0')) ?: '0.0.0.0',
            'port' => (int) ($this->envoy_listeners_new['port'] ?? 0),
            'mode' => trim((string) ($this->envoy_listeners_new['mode'] ?? EnvoyCustomListenersConfig::MODE_SHARED)),
            'default_cluster' => trim((string) ($this->envoy_listeners_new['default_cluster'] ?? '')),
        ];

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add Envoy listener: :name', ['name' => $fields['name']]));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomListenersConfig::class)
                ->add($this->server, $fields, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_listeners_flash = __('Listener added.');
            $this->envoy_listeners_show_add = false;
            $this->loadEnvoyListenersConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_listeners_error = $e->getMessage();
        }
    }

    public function removeEnvoyListener(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove Envoy listener: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomListenersConfig::class)
                ->remove($this->server, $name, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_listeners_flash = __('Listener removed.');
            $this->loadEnvoyListenersConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_listeners_error = $e->getMessage();
        }
    }

    public function loadOpenRestyStaticConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            $this->openresty_static_error = __('Provisioning and SSH must be ready before reading OpenResty settings.');

            return;
        }
        try {
            $result = app(OpenRestyStaticConfigOptions::class)->read($this->server);
            $this->openresty_static_form = $result['values'];
            $this->openresty_static_loaded = true;
            $this->openresty_static_flash = null;
            $this->openresty_static_error = null;
        } catch (\Throwable $e) {
            $this->openresty_static_error = __('Failed to read OpenResty settings: :msg', ['msg' => $e->getMessage()]);
            $this->openresty_static_loaded = false;
        }
    }

    public function saveOpenRestyStaticConfig(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save OpenResty static settings'));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyStaticConfigOptions::class)->save($this->server, $this->openresty_static_form, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_static_flash = __('OpenResty static settings saved and edge routing regenerated.');
            $this->loadOpenRestyStaticConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_static_error = $e->getMessage();
        }
    }

    public function loadOpenRestyUpstreamsConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            $this->openresty_upstreams_error = __('Provisioning and SSH must be ready.');

            return;
        }
        try {
            $rows = app(OpenRestyCustomUpstreamsConfig::class)->read($this->server);
            $form = [];
            $text = [];
            foreach ($rows as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $form[$name] = ['servers' => $row['servers'] ?? []];
                $text[$name] = implode("\n", $row['servers'] ?? []);
            }
            $this->openresty_upstreams_form = $form;
            $this->openresty_upstreams_servers_text = $text;
            $this->openresty_upstreams_loaded = true;
            $this->openresty_upstreams_flash = null;
            $this->openresty_upstreams_error = null;
        } catch (\Throwable $e) {
            $this->openresty_upstreams_error = $e->getMessage();
            $this->openresty_upstreams_loaded = false;
        }
    }

    public function saveOpenRestyUpstreamsConfig(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $upstreams = [];
        foreach ($this->openresty_upstreams_servers_text as $name => $raw) {
            if (! isset($this->openresty_upstreams_form[$name])) {
                continue;
            }
            $servers = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $raw) ?: []), fn (string $s): bool => $s !== ''));
            $upstreams[] = ['name' => $name, 'servers' => $servers];
        }
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save OpenResty custom upstreams'));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyCustomUpstreamsConfig::class)->save($this->server, $upstreams, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_upstreams_flash = __('Custom upstreams saved and edge routing regenerated.');
            $this->loadOpenRestyUpstreamsConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_upstreams_error = $e->getMessage();
        }
    }

    public function openAddOpenRestyUpstreamForm(): void
    {
        $this->openresty_upstreams_show_add = true;
        $this->openresty_upstreams_new = ['name' => '', 'servers' => ''];
        $this->openresty_upstreams_error = null;
        $this->openresty_upstreams_flash = null;
    }

    public function cancelAddOpenRestyUpstreamForm(): void
    {
        $this->openresty_upstreams_show_add = false;
    }

    public function submitAddOpenRestyUpstream(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $name = trim((string) ($this->openresty_upstreams_new['name'] ?? ''));
        $servers = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($this->openresty_upstreams_new['servers'] ?? '')) ?: []), fn (string $s): bool => $s !== ''));
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add OpenResty upstream: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyCustomUpstreamsConfig::class)->add($this->server, $name, $servers, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_upstreams_flash = __('Upstream added.');
            $this->openresty_upstreams_show_add = false;
            $this->loadOpenRestyUpstreamsConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_upstreams_error = $e->getMessage();
        }
    }

    public function removeOpenRestyUpstream(string $name): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove OpenResty upstream: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyCustomUpstreamsConfig::class)->remove($this->server, $name, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_upstreams_flash = __('Upstream removed.');
            $this->loadOpenRestyUpstreamsConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_upstreams_error = $e->getMessage();
        }
    }

    public function loadOpenRestyServersConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            $this->openresty_servers_error = __('Provisioning and SSH must be ready.');

            return;
        }
        try {
            $rows = app(OpenRestyCustomServersConfig::class)->read($this->server);
            $form = [];
            $text = [];
            foreach ($rows as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $form[$name] = ['server_names' => $row['server_names'] ?? [], 'upstream' => (string) ($row['upstream'] ?? '')];
                $text[$name] = implode("\n", $row['server_names'] ?? []);
            }
            $this->openresty_servers_form = $form;
            $this->openresty_servers_names_text = $text;
            $this->openresty_servers_loaded = true;
            $this->openresty_servers_flash = null;
            $this->openresty_servers_error = null;
        } catch (\Throwable $e) {
            $this->openresty_servers_error = $e->getMessage();
            $this->openresty_servers_loaded = false;
        }
    }

    public function saveOpenRestyServersConfig(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $servers = [];
        foreach ($this->openresty_servers_names_text as $name => $raw) {
            if (! isset($this->openresty_servers_form[$name])) {
                continue;
            }
            $serverNames = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $raw) ?: []), fn (string $d): bool => $d !== ''));
            $servers[] = [
                'name' => $name,
                'server_names' => $serverNames,
                'upstream' => trim((string) ($this->openresty_servers_form[$name]['upstream'] ?? '')),
            ];
        }
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save OpenResty custom servers'));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyCustomServersConfig::class)->save($this->server, $servers, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_servers_flash = __('Custom server blocks saved and edge routing regenerated.');
            $this->loadOpenRestyServersConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_servers_error = $e->getMessage();
        }
    }

    public function openAddOpenRestyServerForm(): void
    {
        $this->openresty_servers_show_add = true;
        $this->openresty_servers_new = ['name' => '', 'server_names' => '', 'upstream' => ''];
        $this->openresty_servers_error = null;
        $this->openresty_servers_flash = null;
    }

    public function cancelAddOpenRestyServerForm(): void
    {
        $this->openresty_servers_show_add = false;
    }

    public function submitAddOpenRestyServer(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $name = trim((string) ($this->openresty_servers_new['name'] ?? ''));
        $serverNames = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($this->openresty_servers_new['server_names'] ?? '')) ?: []), fn (string $d): bool => $d !== ''));
        $upstream = trim((string) ($this->openresty_servers_new['upstream'] ?? ''));
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add OpenResty server: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyCustomServersConfig::class)->add($this->server, $name, $serverNames, $upstream, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_servers_flash = __('Server block added.');
            $this->openresty_servers_show_add = false;
            $this->loadOpenRestyServersConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_servers_error = $e->getMessage();
        }
    }

    public function removeOpenRestyServer(string $name): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove OpenResty server: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyCustomServersConfig::class)->remove($this->server, $name, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_servers_flash = __('Server block removed.');
            $this->loadOpenRestyServersConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_servers_error = $e->getMessage();
        }
    }

    private function refreshOpenRestyLiveStateAfterServiceAction(): void
    {
        if ($this->server->edgeProxy() !== 'openresty') {
            return;
        }
        $this->ensureEngineLiveState(forceFresh: true);
    }

    private function mergeTraefikStaticEntrypointsIntoMeta(): void
    {
        $server = $this->server->fresh();
        $meta = is_array($server->meta) ? $server->meta : [];
        $state = data_get($meta, 'webserver_live_state.traefik');
        if (! is_array($state)) {
            return;
        }
        $units = is_array($state['units'] ?? null) ? $state['units'] : [];
        if (($units['entrypoints'] ?? []) !== []) {
            return;
        }
        $read = app(TraefikEntrypointsConfig::class)->read($server);
        if ($read['entrypoints'] === []) {
            return;
        }
        $units['entrypoints'] = array_map(static fn (array $ep): array => [
            'name' => $ep['name'],
            'address' => $ep['address'],
            'transport' => 'static',
            'status' => 'configured',
        ], $read['entrypoints']);
        $state['units'] = $units;
        $meta['webserver_live_state']['traefik'] = $state;
        $server->update(['meta' => $meta]);
    }

    public function loadHaproxyGlobalsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->haproxy_globals_error = __('Provisioning and SSH must be ready before reading haproxy.cfg.');

            return;
        }

        try {
            $result = app(HaproxyGlobalOptionsConfig::class)->read($this->server);
            $this->haproxy_globals_form = $result['values'];
            $this->haproxy_globals_loaded = true;
            $this->haproxy_globals_flash = null;
            $this->haproxy_globals_error = null;
            if (! empty($result['unreadable'])) {
                $this->haproxy_globals_error = __('Could not read /etc/haproxy/haproxy.cfg — check sudo permissions for the deploy user.');
            }
        } catch (\Throwable $e) {
            $this->haproxy_globals_error = __('Failed to read HAProxy globals: :msg', ['msg' => $e->getMessage()]);
            $this->haproxy_globals_loaded = false;
        }
    }

    public function saveHaproxyGlobalsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_globals_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_globals_error = __('Provisioning and SSH must be ready before saving haproxy.cfg.');

            return;
        }

        $this->haproxy_globals_flash = null;
        $this->haproxy_globals_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save HAProxy global options'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(HaproxyGlobalOptionsConfig::class)
                ->save($this->server, $this->haproxy_globals_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_globals_flash = __('HAProxy global options saved and HAProxy reloaded.');
            $this->loadHaproxyGlobalsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_globals_error = $e->getMessage();
        }
    }

    public function loadApacheModulesConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->apache_modules_error = __('Provisioning and SSH must be ready before listing modules.');

            return;
        }

        try {
            $result = app(ApacheModulesConfig::class)->read($this->server);
            $this->apache_modules_list = $result['modules'];
            $this->apache_modules_loaded = true;
            $this->apache_modules_flash = null;
            $this->apache_modules_error = null;
            if (! empty($result['unreadable'])) {
                $this->apache_modules_error = __('Could not list /etc/apache2/mods-available/ — check sudo permissions for the deploy user.');
            }
        } catch (\Throwable $e) {
            $this->apache_modules_error = __('Failed to read modules: :msg', ['msg' => $e->getMessage()]);
            $this->apache_modules_loaded = false;
        }
    }

    public function toggleApacheModule(string $name, bool $enable): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->apache_modules_error = __('Deployers cannot toggle Apache modules.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->apache_modules_error = __('Provisioning and SSH must be ready before toggling modules.');

            return;
        }

        $this->apache_modules_flash = null;
        $this->apache_modules_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __(':verb Apache module: :name', ['verb' => $enable ? 'Enable' : 'Disable', 'name' => $name]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(ApacheModulesConfig::class)
                ->toggle($this->server, $name, $enable, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->apache_modules_flash = __('Module :name :state and Apache reloaded.', ['name' => $name, 'state' => $enable ? 'enabled' : 'disabled']);
            $this->loadApacheModulesConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->apache_modules_error = $e->getMessage();
        }
    }

    public function setApacheModulesFilter(string $filter): void
    {
        $this->apache_modules_filter = in_array($filter, ['all', 'mpm', 'tls', 'auth', 'proxy', 'perf', 'security', 'observability', 'core', 'other'], true) ? $filter : 'all';
    }

    public function loadNginxModulesConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->nginx_modules_error = __('Provisioning and SSH must be ready before listing modules.');

            return;
        }

        try {
            $result = app(NginxModulesConfig::class)->read($this->server);
            $this->nginx_modules_list = $result['modules'];
            $this->nginx_modules_builtins = $result['builtins'];
            $this->nginx_modules_supports_dynamic = (bool) $result['supports_dynamic'];
            $this->nginx_modules_loaded = true;
            $this->nginx_modules_flash = null;
            $this->nginx_modules_error = null;
            if (! empty($result['unreadable'])) {
                $this->nginx_modules_error = __('Could not read nginx modules from the server — check SSH/sudo access.');
            } elseif (! $result['supports_dynamic']) {
                $this->nginx_modules_error = __('This nginx install does not use Debian-style dynamic modules. Use a distro nginx package (Ubuntu/Debian) to manage `libnginx-mod-*` modules here.');
            }
        } catch (\Throwable $e) {
            $this->nginx_modules_error = __('Failed to read modules: :msg', ['msg' => $e->getMessage()]);
            $this->nginx_modules_loaded = false;
        }
    }

    public function toggleNginxModule(string $name, bool $enable): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->nginx_modules_error = __('Deployers cannot manage nginx modules.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->nginx_modules_error = __('Provisioning and SSH must be ready before changing modules.');

            return;
        }

        $this->nginx_modules_flash = null;
        $this->nginx_modules_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __(':verb nginx module: :name', ['verb' => $enable ? 'Enable' : 'Disable', 'name' => $name]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(NginxModulesConfig::class)
                ->toggle($this->server, $name, $enable, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->nginx_modules_flash = __('Module :name :state and nginx reloaded.', [
                'name' => $name,
                'state' => $enable ? __('enabled') : __('disabled'),
            ]);
            $this->loadNginxModulesConfig();
            if ($this->isEngineLiveStateSubtab('modules', 'nginx')) {
                $this->ensureEngineLiveState(forceFresh: true);
            }
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->nginx_modules_error = $e->getMessage();
        }
    }

    public function setNginxModulesFilter(string $filter): void
    {
        $allowed = ['all', 'stream', 'mail', 'tls', 'geo', 'content', 'auth', 'perf', 'security', 'observability', 'other'];
        $this->nginx_modules_filter = in_array($filter, $allowed, true) ? $filter : 'all';
    }

    public function loadNginxUpstreamsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->nginx_upstreams_error = __('Provisioning and SSH must be ready before reading nginx.conf.');

            return;
        }

        try {
            $result = app(NginxUpstreamsConfig::class)->read($this->server);
            $form = [];
            $serversText = [];
            foreach ($result['upstreams'] as $u) {
                $form[$u['name']] = ['servers' => $u['servers'], 'values' => $u['values']];
                $serversText[$u['name']] = implode("\n", $u['servers']);
            }
            $this->nginx_upstreams_form = $form;
            $this->nginx_upstreams_servers_text = $serversText;
            $this->nginx_upstreams_loaded = true;
            $this->nginx_upstreams_flash = null;
            $this->nginx_upstreams_error = null;
            if (! empty($result['unreadable'])) {
                $this->nginx_upstreams_error = __('Could not read /etc/nginx/nginx.conf — check sudo permissions for the deploy user.');
            } elseif (empty($result['upstreams'])) {
                $this->nginx_upstreams_flash = __('No `upstream { ... }` blocks at the http level. Per-site upstreams (in sites-enabled/*) are managed by the per-site provisioner.');
            }
        } catch (\Throwable $e) {
            $this->nginx_upstreams_error = __('Failed to read upstreams: :msg', ['msg' => $e->getMessage()]);
            $this->nginx_upstreams_loaded = false;
        }
    }

    public function saveNginxUpstreamsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->nginx_upstreams_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->nginx_upstreams_error = __('Provisioning and SSH must be ready before saving nginx.conf.');

            return;
        }

        $this->nginx_upstreams_flash = null;
        $this->nginx_upstreams_error = null;

        // Pull servers from the textarea mirror back into the form payload.
        foreach ($this->nginx_upstreams_servers_text as $name => $text) {
            if (! isset($this->nginx_upstreams_form[$name])) {
                continue;
            }
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text) ?: []), fn (string $l) => $l !== ''));
            $this->nginx_upstreams_form[$name]['servers'] = $lines;
        }

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save nginx upstreams'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(NginxUpstreamsConfig::class)
                ->save($this->server, $this->nginx_upstreams_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->nginx_upstreams_flash = __('Upstreams saved and nginx reloaded.');
            $this->loadNginxUpstreamsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->nginx_upstreams_error = $e->getMessage();
        }
    }

    public function openAddNginxUpstreamForm(): void
    {
        $this->nginx_upstreams_show_add = true;
        $this->nginx_upstreams_new = ['name' => '', 'servers' => ''];
        $this->nginx_upstreams_error = null;
        $this->nginx_upstreams_flash = null;
    }

    public function cancelAddNginxUpstreamForm(): void
    {
        $this->nginx_upstreams_show_add = false;
        $this->nginx_upstreams_new = ['name' => '', 'servers' => ''];
    }

    public function submitAddNginxUpstream(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->nginx_upstreams_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->nginx_upstreams_error = __('Provisioning and SSH must be ready before adding an upstream.');

            return;
        }

        $this->nginx_upstreams_flash = null;
        $this->nginx_upstreams_error = null;

        $name = (string) ($this->nginx_upstreams_new['name'] ?? '');
        $servers = array_values(array_filter(
            array_map('trim', preg_split('/\R/', (string) ($this->nginx_upstreams_new['servers'] ?? '')) ?: []),
            fn (string $l) => $l !== '',
        ));

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Add nginx upstream: :name', ['name' => trim($name)]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(NginxUpstreamsConfig::class)
                ->addUpstream($this->server, $name, $servers, [], $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->nginx_upstreams_flash = __('Upstream :name added and nginx reloaded.', ['name' => $name]);
            $this->nginx_upstreams_show_add = false;
            $this->nginx_upstreams_new = ['name' => '', 'servers' => ''];
            $this->loadNginxUpstreamsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->nginx_upstreams_error = $e->getMessage();
        }
    }

    public function removeNginxUpstream(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->nginx_upstreams_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->nginx_upstreams_error = __('Provisioning and SSH must be ready before removing an upstream.');

            return;
        }

        $this->nginx_upstreams_flash = null;
        $this->nginx_upstreams_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Remove nginx upstream: :name', ['name' => $name]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(NginxUpstreamsConfig::class)
                ->removeUpstream($this->server, $name, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->nginx_upstreams_flash = __('Upstream :name removed and nginx reloaded.', ['name' => $name]);
            $this->loadNginxUpstreamsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->nginx_upstreams_error = $e->getMessage();
        }
    }

    public function loadApacheGlobalsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->apache_globals_error = __('Provisioning and SSH must be ready before reading apache2.conf.');

            return;
        }

        try {
            $result = app(ApacheGlobalOptionsConfig::class)->read($this->server);
            $this->apache_globals_form = $result['values'];
            $this->apache_globals_mpm = $result['mpm'];
            $this->apache_globals_loaded = true;
            $this->apache_globals_flash = null;
            $this->apache_globals_error = null;
            if (! empty($result['unreadable'])) {
                $this->apache_globals_error = __('Could not read /etc/apache2/apache2.conf — check sudo permissions for the deploy user.');
            }
        } catch (\Throwable $e) {
            $this->apache_globals_error = __('Failed to read Apache globals: :msg', ['msg' => $e->getMessage()]);
            $this->apache_globals_loaded = false;
        }
    }

    public function saveApacheGlobalsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->apache_globals_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->apache_globals_error = __('Provisioning and SSH must be ready before saving apache2.conf.');

            return;
        }

        $this->apache_globals_flash = null;
        $this->apache_globals_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save Apache global options'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(ApacheGlobalOptionsConfig::class)
                ->save($this->server, $this->apache_globals_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->apache_globals_flash = __('Apache global options saved and apache2 reloaded.');
            $this->loadApacheGlobalsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->apache_globals_error = $e->getMessage();
        }
    }

    public function loadNginxGlobalsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->nginx_globals_error = __('Provisioning and SSH must be ready before reading nginx.conf.');

            return;
        }

        try {
            $result = app(NginxGlobalOptionsConfig::class)->read($this->server);
            $this->nginx_globals_form = $result['values'];
            $this->nginx_globals_loaded = true;
            $this->nginx_globals_flash = null;
            $this->nginx_globals_error = null;
            if (! empty($result['unreadable'])) {
                $this->nginx_globals_error = __('Could not read /etc/nginx/nginx.conf — check sudo permissions for the deploy user.');
            }
        } catch (\Throwable $e) {
            $this->nginx_globals_error = __('Failed to read nginx globals: :msg', ['msg' => $e->getMessage()]);
            $this->nginx_globals_loaded = false;
        }
    }

    public function saveNginxGlobalsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->nginx_globals_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->nginx_globals_error = __('Provisioning and SSH must be ready before saving nginx.conf.');

            return;
        }

        $this->nginx_globals_flash = null;
        $this->nginx_globals_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save nginx global options'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(NginxGlobalOptionsConfig::class)
                ->save($this->server, $this->nginx_globals_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->nginx_globals_flash = __('nginx global options saved and nginx reloaded.');
            $this->loadNginxGlobalsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->nginx_globals_error = $e->getMessage();
        }
    }

    public function loadCaddySnippetsConfig(bool $forceFresh = false): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->caddy_snippets_error = __('Provisioning and SSH must be ready before reading the Caddyfile.');

            return;
        }

        if ($this->caddy_snippets_loaded && ! $forceFresh) {
            return;
        }

        $this->caddy_snippets_flash = null;
        $this->caddy_snippets_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Read Caddy snippets'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            $result = app(CaddySnippetsConfig::class)->read($this->server, $emitter);
            $form = [];
            foreach ($result['snippets'] as $snippet) {
                $form[$snippet['name']] = $snippet['body'];
            }
            $this->caddy_snippets_form = $form;
            $this->caddy_snippets_loaded = true;
            if (! empty($result['unreadable'])) {
                $this->caddy_snippets_error = __('Could not read /etc/caddy/Caddyfile — check sudo permissions for the deploy user.');
                DB::table('console_actions')->where('id', $consoleId)->update([
                    'status' => ConsoleAction::STATUS_FAILED,
                    'finished_at' => now(),
                    'error' => mb_substr((string) $this->caddy_snippets_error, 0, 2000),
                    'updated_at' => now(),
                ]);
            } elseif (empty($result['snippets'])) {
                $this->caddy_snippets_flash = __('No snippet blocks found in the Caddyfile yet.');
                DB::table('console_actions')->where('id', $consoleId)->update([
                    'status' => ConsoleAction::STATUS_COMPLETED,
                    'finished_at' => now(),
                    'error' => null,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('console_actions')->where('id', $consoleId)->update([
                    'status' => ConsoleAction::STATUS_COMPLETED,
                    'finished_at' => now(),
                    'error' => null,
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->caddy_snippets_error = __('Failed to read snippets: :msg', ['msg' => $e->getMessage()]);
            $this->caddy_snippets_loaded = false;
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
        }
    }

    public function saveCaddySnippetsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->caddy_snippets_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->caddy_snippets_error = __('Provisioning and SSH must be ready before saving the Caddyfile.');

            return;
        }

        $this->caddy_snippets_flash = null;
        $this->caddy_snippets_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save Caddy snippets'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(CaddySnippetsConfig::class)
                ->save($this->server, $this->caddy_snippets_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->caddy_snippets_flash = __('Snippets saved and Caddy reloaded.');
            $this->loadCaddySnippetsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->caddy_snippets_error = $e->getMessage();
        }
    }

    public function openAddCaddySnippetForm(): void
    {
        $this->caddy_snippets_show_add = true;
        $this->caddy_snippets_new = ['name' => '', 'body' => ''];
        $this->caddy_snippets_error = null;
        $this->caddy_snippets_flash = null;
    }

    public function cancelAddCaddySnippetForm(): void
    {
        $this->caddy_snippets_show_add = false;
        $this->caddy_snippets_new = ['name' => '', 'body' => ''];
    }

    public function submitAddCaddySnippet(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->caddy_snippets_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->caddy_snippets_error = __('Provisioning and SSH must be ready before adding a snippet.');

            return;
        }

        $this->caddy_snippets_flash = null;
        $this->caddy_snippets_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Add Caddy snippet: :name', ['name' => trim($this->caddy_snippets_new['name'] ?? '')]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(CaddySnippetsConfig::class)
                ->addSnippet(
                    $this->server,
                    (string) ($this->caddy_snippets_new['name'] ?? ''),
                    (string) ($this->caddy_snippets_new['body'] ?? ''),
                    $emitter,
                );
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->caddy_snippets_flash = __('Snippet (:name) added and Caddy reloaded.', ['name' => $this->caddy_snippets_new['name']]);
            $this->caddy_snippets_show_add = false;
            $this->caddy_snippets_new = ['name' => '', 'body' => ''];
            $this->loadCaddySnippetsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->caddy_snippets_error = $e->getMessage();
        }
    }

    public function removeCaddySnippet(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->caddy_snippets_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->caddy_snippets_error = __('Provisioning and SSH must be ready before removing a snippet.');

            return;
        }

        $this->caddy_snippets_flash = null;
        $this->caddy_snippets_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Remove Caddy snippet: :name', ['name' => $name]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(CaddySnippetsConfig::class)
                ->removeSnippet($this->server, $name, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->caddy_snippets_flash = __('Snippet (:name) removed and Caddy reloaded.', ['name' => $name]);
            $this->loadCaddySnippetsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->caddy_snippets_error = $e->getMessage();
        }
    }

    public function loadCaddyModulesInventory(bool $forceFresh = false): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->caddy_modules_error = __('Provisioning and SSH must be ready before listing Caddy modules.');

            return;
        }

        if ($this->caddy_modules_loaded && ! $forceFresh) {
            return;
        }

        $this->caddy_modules_flash = null;
        $this->caddy_modules_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Read Caddy modules'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            $result = app(CaddyModulesManager::class)->read($this->server, $emitter);
            $this->caddy_modules_installed = $result['modules'];
            $this->caddy_modules_plugins = $result['plugins'];
            $this->caddy_modules_caddy_version = $result['caddy_version'];
            $this->caddy_modules_custom_binary = (bool) $result['custom_binary'];
            $this->caddy_modules_loaded = true;

            if (! empty($result['unreadable'])) {
                $this->caddy_modules_error = __('Could not run `caddy list-modules` on the server.');
                DB::table('console_actions')->where('id', $consoleId)->update([
                    'status' => ConsoleAction::STATUS_FAILED,
                    'finished_at' => now(),
                    'error' => mb_substr((string) $this->caddy_modules_error, 0, 2000),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('console_actions')->where('id', $consoleId)->update([
                    'status' => ConsoleAction::STATUS_COMPLETED,
                    'finished_at' => now(),
                    'error' => null,
                    'updated_at' => now(),
                ]);
            }

            try {
                $this->refreshCaddyModulesCatalogState();
            } catch (\Throwable) {
                // Registry refresh is best-effort — inventory still loaded.
            }
        } catch (\Throwable $e) {
            $this->caddy_modules_error = __('Failed to read modules: :msg', ['msg' => $e->getMessage()]);
            $this->caddy_modules_loaded = false;
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
        }
    }

    public function refreshCaddyModulesInventory(): void
    {
        $this->loadCaddyModulesInventory(forceFresh: true);
    }

    public function setCaddyModulesFilter(string $filter): void
    {
        $this->caddy_modules_filter = in_array($filter, ['all', 'handlers', 'matchers', 'tls', 'storage', 'dns', 'core', 'other'], true)
            ? $filter
            : 'all';
    }

    public function resetCaddyModulesCompiledFilters(): void
    {
        $this->caddy_modules_filter = 'all';
        $this->caddy_modules_search = '';
    }

    public function openCaddyModuleBrowse(): void
    {
        $this->caddy_modules_show_browse = true;
        $this->caddy_modules_browse_search = '';
        $this->caddy_modules_browse_error = null;
        $this->refreshCaddyModulesCatalogState();
    }

    public function closeCaddyModuleBrowse(): void
    {
        $this->caddy_modules_show_browse = false;
        $this->caddy_modules_browse_search = '';
        $this->caddy_modules_browse_packages = [];
        $this->caddy_modules_browse_error = null;
    }

    public function updatedCaddyModulesBrowseSearch(): void
    {
        $this->refreshCaddyModulesBrowseList();
    }

    public function refreshCaddyModulesCatalogState(): void
    {
        $this->caddy_modules_available_catalog = app(CaddyModulesManager::class)->availableCatalog(
            $this->caddy_modules_plugins,
            $this->caddy_modules_installed,
        );

        if ($this->caddy_modules_show_browse) {
            $this->refreshCaddyModulesBrowseList();
        }
    }

    public function refreshCaddyModulesBrowseList(): void
    {
        if (! $this->caddy_modules_show_browse) {
            return;
        }

        try {
            $this->caddy_modules_browse_packages = app(CaddyModulesManager::class)->browsePackages(
                $this->caddy_modules_plugins,
                $this->caddy_modules_installed,
                $this->caddy_modules_browse_search,
            );
            $this->caddy_modules_browse_error = null;
        } catch (\Throwable $e) {
            $this->caddy_modules_browse_packages = [];
            $this->caddy_modules_browse_error = __('Could not load community modules: :msg', ['msg' => $e->getMessage()]);
        }
    }

    public function openAddCaddyModuleForm(): void
    {
        $this->caddy_modules_show_add = true;
        $this->caddy_modules_new = ['path' => '', 'version' => ''];
        $this->caddy_modules_error = null;
        $this->caddy_modules_flash = null;
    }

    public function cancelAddCaddyModuleForm(): void
    {
        $this->caddy_modules_show_add = false;
        $this->caddy_modules_new = ['path' => '', 'version' => ''];
    }

    public function queueCatalogCaddyModule(string $path): void
    {
        $this->openConfirmInstallCaddyModule($path);
    }

    public function requestAddCaddyModule(): void
    {
        $this->openConfirmInstallCaddyModule(
            (string) ($this->caddy_modules_new['path'] ?? ''),
            (string) ($this->caddy_modules_new['version'] ?? ''),
        );
    }

    public function openConfirmInstallCaddyModule(string $path, string $version = '', bool $rebuild = true): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot modify Caddy modules.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before adding a Caddy plugin.'));

            return;
        }

        $path = trim($path);
        $version = trim($version);

        try {
            $info = app(CaddyModulesManager::class)->packageInfoForInstall($path);
        } catch (\Throwable $e) {
            $this->caddy_modules_error = $e->getMessage();

            return;
        }

        foreach ($this->caddy_modules_plugins as $plugin) {
            if (($plugin['path'] ?? '') === $path) {
                $this->toastError(__('That plugin is already in the build manifest.'));

                return;
            }
        }

        $this->caddy_modules_error = null;

        $this->openConfirmActionModal(
            'installCaddyModuleConfirmed',
            [$path, $version, $rebuild],
            __('Install Caddy plugin?'),
            __('Review the plugin details below. Confirming adds it to your custom build manifest and compiles it into the Caddy binary with xcaddy on the server.'),
            $rebuild ? __('Add & rebuild') : __('Add to manifest'),
            false,
            $this->caddyModuleInstallModalDetails($info, $version, $rebuild),
        );
    }

    public function installCaddyModuleConfirmed(string $path, string $version = '', bool $rebuild = true): void
    {
        $this->caddy_modules_new = ['path' => $path, 'version' => $version];
        $this->submitAddCaddyModule(rebuild: $rebuild);
    }

    /**
     * @param  array{
     *     path: string,
     *     repo: string,
     *     label: string,
     *     description: string,
     *     module_ids: list<string>,
     *     docs_url: string,
     * }  $info
     * @return list<array{label: string, value: string, mono?: bool, multiline?: bool, link?: bool}>
     */
    protected function caddyModuleInstallModalDetails(array $info, string $version = '', bool $rebuild = true): array
    {
        $details = [
            ['label' => (string) __('Plugin'), 'value' => (string) $info['label']],
            ['label' => (string) __('Package'), 'value' => (string) $info['path'], 'mono' => true],
        ];

        if ($version !== '') {
            $details[] = ['label' => (string) __('Version pin'), 'value' => $version, 'mono' => true];
        }

        if (($info['description'] ?? '') !== '') {
            $details[] = ['label' => (string) __('About'), 'value' => (string) $info['description'], 'multiline' => true];
        }

        if (($info['module_ids'] ?? []) !== []) {
            $details[] = [
                'label' => (string) __('Module IDs'),
                'value' => implode("\n", $info['module_ids']),
                'mono' => true,
                'multiline' => true,
            ];
        }

        if (($info['repo'] ?? '') !== '') {
            $details[] = ['label' => (string) __('Repository'), 'value' => (string) $info['repo'], 'mono' => true, 'link' => true];
        }

        if (($info['docs_url'] ?? '') !== '') {
            $details[] = ['label' => (string) __('Documentation'), 'value' => (string) $info['docs_url'], 'link' => true];
        }

        $details[] = [
            'label' => (string) __('Build impact'),
            'value' => $rebuild
                ? (string) __('Queues an xcaddy rebuild on the server, validates the new binary against your Caddyfile, installs it, and restarts Caddy. This usually takes several minutes.')
                : (string) __('Adds the plugin to the manifest only — rebuild Caddy manually when you are ready.'),
            'multiline' => true,
        ];

        return $details;
    }

    public function submitAddCaddyModule(bool $rebuild = true): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->caddy_modules_error = __('Deployers cannot modify Caddy modules.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->caddy_modules_error = __('Provisioning and SSH must be ready before adding a Caddy plugin.');

            return;
        }

        $this->caddy_modules_flash = null;
        $this->caddy_modules_error = null;

        try {
            $this->server = app(CaddyModulesManager::class)->addPlugin(
                $this->server,
                (string) ($this->caddy_modules_new['path'] ?? ''),
                (string) ($this->caddy_modules_new['version'] ?? ''),
            );
            $this->caddy_modules_show_add = false;
            $this->caddy_modules_new = ['path' => '', 'version' => ''];
            $this->loadCaddyModulesInventory();

            if ($rebuild) {
                $this->queueCaddyModulesRebuild();
            } else {
                $this->caddy_modules_flash = __('Plugin added to the build manifest. Rebuild Caddy to compile it in.');
            }
        } catch (\Throwable $e) {
            $this->caddy_modules_error = $e->getMessage();
        }
    }

    public function removeCaddyModulePlugin(string $path): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->caddy_modules_error = __('Deployers cannot modify Caddy modules.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->caddy_modules_error = __('Provisioning and SSH must be ready before removing a Caddy plugin.');

            return;
        }

        $this->caddy_modules_flash = null;
        $this->caddy_modules_error = null;

        try {
            $this->server = app(CaddyModulesManager::class)->removePlugin($this->server, $path);
            $remaining = app(CaddyModulesManager::class)->manifestPlugins($this->server);
            $this->loadCaddyModulesInventory();

            if ($remaining === []) {
                $this->queueRestoreCaddyPackageBinary();
                $this->caddy_modules_flash = __('Last plugin removed — restoring the apt Caddy package.');
            } else {
                $this->queueCaddyModulesRebuild();
            }
        } catch (\Throwable $e) {
            $this->caddy_modules_error = $e->getMessage();
        }
    }

    public function queueCaddyModulesRebuild(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot rebuild Caddy.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before rebuilding Caddy.'));

            return;
        }

        $plugins = app(CaddyModulesManager::class)->manifestPlugins($this->server);
        if ($plugins === []) {
            $this->toastError(__('Add at least one plugin before rebuilding.'));

            return;
        }

        $this->caddy_modules_flash = null;
        $this->caddy_modules_error = null;

        $label = __('Rebuild Caddy with plugins');
        $this->dispatchQueuedManageScript(
            $this->server->fresh() ?? $this->server,
            'manage-config:caddy-modules-rebuild',
            app(CaddyModulesManager::class)->rebuildScript($this->server),
            (int) config('caddy_modules.rebuild_timeout_seconds', 900),
            __('Custom Caddy build finished.'),
            $label,
            $label,
        );
    }

    public function queueRestoreCaddyPackageBinary(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot restore the Caddy package.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before restoring Caddy.'));

            return;
        }

        $this->server = app(CaddyModulesManager::class)->clearManifest($this->server);

        $label = __('Restore apt Caddy package');
        $this->dispatchQueuedManageScript(
            $this->server->fresh() ?? $this->server,
            'manage-config:caddy-modules-restore',
            app(CaddyModulesManager::class)->restorePackageScript(),
            600,
            __('Caddy package restored.'),
            $label,
            $label,
        );
    }

    /**
     * @return array{active: bool, message: string, mode: ?string}
     */
    public function caddyModulesBuildState(): array
    {
        $tasks = [
            'manage-config:caddy-modules-rebuild' => [
                'running' => (string) __('Building custom Caddy binary…'),
                'queued' => (string) __('Queued Caddy rebuild…'),
                'mode' => 'rebuild',
            ],
            'manage-config:caddy-modules-restore' => [
                'running' => (string) __('Restoring apt Caddy package…'),
                'queued' => (string) __('Queued package restore…'),
                'mode' => 'restore',
            ],
        ];

        if ($this->manageRemoteTaskId !== null && $this->manageRemoteTaskId !== '') {
            $taskName = (string) ($this->manageRemoteTaskName ?? '');
            if (isset($tasks[$taskName])) {
                $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
                if (is_array($payload)) {
                    $status = (string) ($payload['status'] ?? '');
                    if (in_array($status, ['queued', 'running'], true)) {
                        return [
                            'active' => true,
                            'message' => $status === 'queued'
                                ? $tasks[$taskName]['queued']
                                : $tasks[$taskName]['running'],
                            'mode' => $tasks[$taskName]['mode'],
                        ];
                    }
                }
            }
        }

        $running = ServerManageAction::query()
            ->where('server_id', $this->server->id)
            ->whereIn('task_name', array_keys($tasks))
            ->whereIn('status', [ServerManageAction::STATUS_QUEUED, ServerManageAction::STATUS_RUNNING])
            ->orderByDesc('created_at')
            ->first();

        if ($running !== null && isset($tasks[$running->task_name])) {
            $meta = $tasks[$running->task_name];

            return [
                'active' => true,
                'message' => $running->status === ServerManageAction::STATUS_QUEUED
                    ? $meta['queued']
                    : $meta['running'],
                'mode' => $meta['mode'],
            ];
        }

        return ['active' => false, 'message' => '', 'mode' => null];
    }

    /** Poll hook — empty body; Livewire re-render refreshes {@see caddyModulesBuildState()}. */
    public function refreshCaddyModulesBuildUi(): void {}

    public function loadOlsVhostsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->ols_vhosts_error = __('Provisioning and SSH must be ready before reading vhost config.');

            return;
        }

        try {
            $result = app(OpenLiteSpeedVhostsConfig::class)->read($this->server);
            $form = [];
            $identity = [];
            foreach ($result['vhosts'] as $vh) {
                $form[$vh['name']] = $vh['values'];
                $identity[$vh['name']] = [
                    'conf_path' => $vh['conf_path'],
                    'vh_root' => $vh['vh_root'],
                    'domains' => $vh['domains'],
                    'unreadable' => $vh['unreadable'],
                ];
            }
            $this->ols_vhosts_form = $form;
            $this->ols_vhosts_identity = $identity;
            $this->ols_vhosts_loaded = true;
            $this->ols_vhosts_flash = null;
            $this->ols_vhosts_error = null;
            if (! empty($result['unreadable_httpd'])) {
                $this->ols_vhosts_error = __('Could not read /usr/local/lsws/conf/httpd_config.conf — check sudo permissions for the deploy user.');
            } elseif (empty($result['vhosts'])) {
                $this->ols_vhosts_flash = __('No virtual hosts found in httpd_config.conf — add a site to populate this list.');
            }
        } catch (\Throwable $e) {
            $this->ols_vhosts_error = __('Failed to read vhost config: :msg', ['msg' => $e->getMessage()]);
            $this->ols_vhosts_loaded = false;
        }
    }

    public function saveOlsVhostsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->ols_vhosts_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->ols_vhosts_error = __('Provisioning and SSH must be ready before saving vhost config.');

            return;
        }

        $this->ols_vhosts_flash = null;
        $this->ols_vhosts_error = null;

        // Build the per-vhost updates payload from the form, attaching the
        // per-vhost conf_path so the service can write to the right file.
        $updates = [];
        foreach ($this->ols_vhosts_form as $vhostName => $values) {
            $confPath = $this->ols_vhosts_identity[$vhostName]['conf_path'] ?? null;
            if ($confPath === null) {
                continue;
            }
            $updates[$vhostName] = ['conf_path' => $confPath, 'values' => $values];
        }

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save OpenLiteSpeed vhost config'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(OpenLiteSpeedVhostsConfig::class)
                ->save($this->server, $updates, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_vhosts_flash = __('Vhost config saved and OpenLiteSpeed reloaded.');
            $this->loadOlsVhostsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->ols_vhosts_error = $e->getMessage();
        }
    }

    public function loadOlsListenersConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->ols_listeners_error = __('Provisioning and SSH must be ready before reading listener config.');

            return;
        }

        try {
            $result = app(OpenLiteSpeedListenersConfig::class)->read($this->server);
            $form = [];
            $identity = [];
            $maps = [];
            foreach ($result['listeners'] as $listener) {
                $form[$listener['name']] = $listener['values'];
                $identity[$listener['name']] = $listener['identity'];
                $maps[$listener['name']] = $listener['maps'];
            }
            $this->ols_listeners_form = $form;
            $this->ols_listeners_identity = $identity;
            $this->ols_listeners_maps = $maps;
            $this->ols_listeners_loaded = true;
            $this->ols_listeners_flash = null;
            $this->ols_listeners_error = null;
            if (! empty($result['unreadable'])) {
                $this->ols_listeners_error = __('Could not read /usr/local/lsws/conf/httpd_config.conf — check sudo permissions for the deploy user.');
            } elseif (empty($result['listeners'])) {
                $this->ols_listeners_flash = __('No listener blocks found in httpd_config.conf yet.');
            }
        } catch (\Throwable $e) {
            $this->ols_listeners_error = __('Failed to read listener config: :msg', ['msg' => $e->getMessage()]);
            $this->ols_listeners_loaded = false;
        }
    }

    public function saveOlsListenersConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->ols_listeners_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->ols_listeners_error = __('Provisioning and SSH must be ready before saving listener config.');

            return;
        }

        $this->ols_listeners_flash = null;
        $this->ols_listeners_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save OpenLiteSpeed listener config'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(OpenLiteSpeedListenersConfig::class)
                ->save($this->server, $this->ols_listeners_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_listeners_flash = __('Listener config saved and OpenLiteSpeed reloaded.');
            $this->loadOlsListenersConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->ols_listeners_error = $e->getMessage();
        }
    }

    public function openAddOlsListenerForm(): void
    {
        $this->ols_listeners_show_add = true;
        $this->ols_listeners_new = ['name' => '', 'address' => '', 'secure' => '0', 'keyFile' => '', 'certFile' => ''];
        $this->ols_listeners_error = null;
        $this->ols_listeners_flash = null;
    }

    public function cancelAddOlsListenerForm(): void
    {
        $this->ols_listeners_show_add = false;
        $this->ols_listeners_new = ['name' => '', 'address' => '', 'secure' => '0', 'keyFile' => '', 'certFile' => ''];
    }

    public function submitAddOlsListener(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->ols_listeners_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->ols_listeners_error = __('Provisioning and SSH must be ready before adding a listener.');

            return;
        }

        $this->ols_listeners_flash = null;
        $this->ols_listeners_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Add OpenLiteSpeed listener: :name', ['name' => trim($this->ols_listeners_new['name'] ?? '')]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(OpenLiteSpeedListenersConfig::class)
                ->addListener($this->server, $this->ols_listeners_new, [], $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_listeners_flash = __('Listener :name added and OpenLiteSpeed reloaded.', ['name' => $this->ols_listeners_new['name']]);
            $this->ols_listeners_show_add = false;
            $this->ols_listeners_new = ['name' => '', 'address' => '', 'secure' => '0', 'keyFile' => '', 'certFile' => ''];
            $this->loadOlsListenersConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->ols_listeners_error = $e->getMessage();
        }
    }

    public function removeOlsListener(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->ols_listeners_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->ols_listeners_error = __('Provisioning and SSH must be ready before removing a listener.');

            return;
        }

        $this->ols_listeners_flash = null;
        $this->ols_listeners_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Remove OpenLiteSpeed listener: :name', ['name' => $name]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(OpenLiteSpeedListenersConfig::class)
                ->removeListener($this->server, $name, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_listeners_flash = __('Listener :name removed and OpenLiteSpeed reloaded.', ['name' => $name]);
            $this->loadOlsListenersConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->ols_listeners_error = $e->getMessage();
        }
    }

    /**
     * Strip an ExtApp from httpd_config.conf. dply-managed lsphp* names are
     * blocked at the service layer so this can't accidentally delete a
     * PHP backend the provisioner owns.
     */
    public function removeOlsExtApp(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->ols_extapps_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->ols_extapps_error = __('Provisioning and SSH must be ready before removing an ExtApp.');

            return;
        }

        $this->ols_extapps_flash = null;
        $this->ols_extapps_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Remove OpenLiteSpeed ExtApp: :name', ['name' => $name]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(OpenLiteSpeedExtAppsConfig::class)
                ->removeApp($this->server, $name, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_extapps_flash = __('ExtApp :name removed and OpenLiteSpeed reloaded.', ['name' => $name]);
            $this->loadOlsExtAppsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->ols_extapps_error = $e->getMessage();
        }
    }

    /**
     * Repair a down Caddy unix upstream to PHP-FPM (start FPM, socket access, reload Caddy).
     * Invoked from the Upstreams live-state table after modal confirmation.
     */
    public function repairCaddyPhpFpmUpstream(string $upstreamAddress): void
    {
        $phpManager = app(ServerPhpManager::class);
        $installed = $phpManager->probeInstalledVersionIds($this->server);
        $versions = CaddyPhpFpmUpstreamAddress::repairPhpVersions(
            $upstreamAddress,
            $installed,
            $phpManager->probeLatestInstalledVersion($this->server),
        );

        if ($versions['upstream'] === null && ! CaddyPhpFpmUpstreamAddress::isPhpFpmSocket($upstreamAddress)) {
            $this->remote_error = __('Could not determine PHP version from this upstream.');

            return;
        }

        $this->allowlistedActionPhpVersion = $versions['primary'];
        $this->allowlistedActionUpstreamPhpVersion = $versions['needs_config_update'] ? $versions['upstream'] : null;

        try {
            $this->runAllowlistedAction('repair_caddy_php_fpm_upstream');
            if ($this->remote_error === null && ($this->manageRemoteTaskId === null || $this->manageRemoteTaskId === '')) {
                $this->reapplyCaddySiteConfigsAfterPhpRepair();
            }
        } finally {
            $this->allowlistedActionPhpVersion = null;
            $this->allowlistedActionUpstreamPhpVersion = null;
            $this->allowlistedActionPhpVersionFallback = null;
        }
    }

    protected function reapplyCaddySiteConfigsAfterPhpRepair(): void
    {
        if (strtolower((string) data_get($this->server->meta, 'webserver', 'nginx')) !== 'caddy') {
            return;
        }

        $provisioner = app(SiteCaddyProvisioner::class);

        foreach ($this->server->sites()->get() as $site) {
            if ($site->type === SiteType::Custom) {
                continue;
            }

            try {
                $provisioner->provision($site->fresh());
            } catch (\Throwable $e) {
                $this->toastError(__('Could not re-apply Caddy config for :site: :error', [
                    'site' => $site->name,
                    'error' => mb_substr($e->getMessage(), 0, 120),
                ]));
            }
        }
    }

    public function syncManageRemoteTaskFromCache(): void
    {
        if ($this->manageRemoteTaskId === null || $this->manageRemoteTaskId === '') {
            return;
        }

        $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
        if (! is_array($payload)) {
            return;
        }

        $status = (string) ($payload['status'] ?? '');
        $taskName = $this->manageRemoteTaskName;
        $refreshLiveState = $status === 'finished'
            && $this->shouldRefreshWebserverLiveStateAfterRemoteTask($taskName);

        parent::syncManageRemoteTaskFromCache();

        if ($status === 'finished' && $taskName === 'manage-action:repair_caddy_php_fpm_upstream') {
            $this->reapplyCaddySiteConfigsAfterPhpRepair();
        }

        if ($status === 'finished' && in_array($taskName, ['manage-config:caddy-modules-rebuild', 'manage-config:caddy-modules-restore'], true)) {
            $this->server->refresh();
            if ($this->workspace_tab === 'caddy' && $this->engine_subtab === 'modules') {
                $this->loadCaddyModulesInventory();
            }
        }

        if ($refreshLiveState && $this->isEngineLiveStateSubtab($this->engine_subtab, $this->workspace_tab)) {
            $this->ensureEngineLiveState(forceFresh: true);
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

    public function loadCaddyCustomRoutesConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->caddy_custom_routes_error = __('Provisioning and SSH must be ready before reading custom routes.');

            return;
        }

        try {
            $result = app(CaddyCustomRoutesConfig::class)->read($this->server);
            $form = [];
            foreach ($result['routes'] as $route) {
                $slug = (string) ($route['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $form[$slug] = [
                    'hosts' => implode("\n", $route['hosts'] ?? []),
                    'root' => (string) ($route['root'] ?? ''),
                    'upstream' => (string) ($route['upstream'] ?? ''),
                ];
            }
            $this->caddy_custom_routes_form = $form;
            $this->caddy_custom_routes_loaded = true;
            $this->caddy_custom_routes_flash = null;
            $this->caddy_custom_routes_error = null;
            if (! empty($result['unreadable'])) {
                $this->caddy_custom_routes_error = __('Could not read custom route files — check sudo permissions for the deploy user.');
            }
        } catch (\Throwable $e) {
            $this->caddy_custom_routes_error = __('Failed to read custom routes: :msg', ['msg' => $e->getMessage()]);
            $this->caddy_custom_routes_loaded = false;
        }
    }

    public function openAddCaddyCustomRouteForm(): void
    {
        $this->caddy_custom_routes_show_add = true;
        $this->caddy_custom_routes_new = [
            'slug' => '',
            'hosts' => '',
            'root' => '',
            'upstream' => '',
        ];
        $this->caddy_custom_routes_error = null;
        $this->caddy_custom_routes_flash = null;
    }

    public function cancelAddCaddyCustomRouteForm(): void
    {
        $this->caddy_custom_routes_show_add = false;
        $this->caddy_custom_routes_new = [
            'slug' => '',
            'hosts' => '',
            'root' => '',
            'upstream' => '',
        ];
    }

    public function submitAddCaddyCustomRoute(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->caddy_custom_routes_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->caddy_custom_routes_error = __('Provisioning and SSH must be ready before adding a custom route.');

            return;
        }

        $this->caddy_custom_routes_flash = null;
        $this->caddy_custom_routes_error = null;

        $fields = $this->caddyCustomRouteFieldsFromForm($this->caddy_custom_routes_new);
        $slug = (string) ($this->caddy_custom_routes_new['slug'] ?? '');

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Add Caddy custom route: :slug', ['slug' => $slug]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(CaddyCustomRoutesConfig::class)->add($this->server, $slug, $fields, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->caddy_custom_routes_flash = __('Custom route :slug added and Caddy reloaded.', ['slug' => $slug]);
            $this->caddy_custom_routes_show_add = false;
            $this->caddy_custom_routes_new = [
                'slug' => '',
                'hosts' => '',
                'root' => '',
                'upstream' => '',
            ];
            $this->loadCaddyCustomRoutesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->caddy_custom_routes_error = $e->getMessage();
        }
    }

    public function saveCaddyCustomRoute(string $slug): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->caddy_custom_routes_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->caddy_custom_routes_error = __('Provisioning and SSH must be ready before saving custom routes.');

            return;
        }

        if (! isset($this->caddy_custom_routes_form[$slug])) {
            return;
        }

        $this->caddy_custom_routes_flash = null;
        $this->caddy_custom_routes_error = null;

        $fields = $this->caddyCustomRouteFieldsFromForm($this->caddy_custom_routes_form[$slug]);

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save Caddy custom route: :slug', ['slug' => $slug]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(CaddyCustomRoutesConfig::class)->save($this->server, $slug, $fields, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->caddy_custom_routes_flash = __('Custom route :slug saved and Caddy reloaded.', ['slug' => $slug]);
            $this->loadCaddyCustomRoutesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->caddy_custom_routes_error = $e->getMessage();
        }
    }

    public function removeCaddyCustomRoute(string $slug): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->caddy_custom_routes_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->caddy_custom_routes_error = __('Provisioning and SSH must be ready before removing custom routes.');

            return;
        }

        $this->caddy_custom_routes_flash = null;
        $this->caddy_custom_routes_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Remove Caddy custom route: :slug', ['slug' => $slug]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(CaddyCustomRoutesConfig::class)->remove($this->server, $slug, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->caddy_custom_routes_flash = __('Custom route :slug removed.', ['slug' => $slug]);
            $this->loadCaddyCustomRoutesConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->caddy_custom_routes_error = $e->getMessage();
        }
    }

    /**
     * @param  array{hosts?: string, root?: string, upstream?: string}  $form
     * @return array{hosts: list<string>, root: string, upstream: string}
     */
    private function caddyCustomRouteFieldsFromForm(array $form): array
    {
        $hosts = preg_split('/[\s,]+/', trim((string) ($form['hosts'] ?? ''))) ?: [];

        return [
            'hosts' => array_values(array_filter(array_map('trim', $hosts), fn (string $s): bool => $s !== '')),
            'root' => trim((string) ($form['root'] ?? '')),
            'upstream' => trim((string) ($form['upstream'] ?? '')),
        ];
    }

    public function loadNginxCustomHostsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->nginx_custom_hosts_error = __('Provisioning and SSH must be ready before reading custom hosts.');

            return;
        }

        try {
            $result = app(NginxCustomHostsConfig::class)->read($this->server);
            $form = [];
            foreach ($result['hosts'] as $host) {
                $slug = (string) ($host['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $form[$slug] = [
                    'server_names' => implode("\n", $host['server_names'] ?? []),
                    'listen' => implode("\n", $host['listen'] ?? ['80', '[::]:80']),
                    'root' => (string) ($host['root'] ?? ''),
                    'upstream' => (string) ($host['upstream'] ?? ''),
                ];
            }
            $this->nginx_custom_hosts_form = $form;
            $this->nginx_custom_hosts_loaded = true;
            $this->nginx_custom_hosts_flash = null;
            $this->nginx_custom_hosts_error = null;
            if (! empty($result['unreadable'])) {
                $this->nginx_custom_hosts_error = __('Could not read custom host files — check sudo permissions for the deploy user.');
            }
        } catch (\Throwable $e) {
            $this->nginx_custom_hosts_error = __('Failed to read custom hosts: :msg', ['msg' => $e->getMessage()]);
            $this->nginx_custom_hosts_loaded = false;
        }
    }

    public function openAddNginxCustomHostForm(): void
    {
        $this->nginx_custom_hosts_show_add = true;
        $this->nginx_custom_hosts_new = [
            'slug' => '',
            'server_names' => '',
            'listen' => "80\n[::]:80",
            'root' => '',
            'upstream' => '',
        ];
        $this->nginx_custom_hosts_error = null;
        $this->nginx_custom_hosts_flash = null;
    }

    public function cancelAddNginxCustomHostForm(): void
    {
        $this->nginx_custom_hosts_show_add = false;
        $this->nginx_custom_hosts_new = [
            'slug' => '',
            'server_names' => '',
            'listen' => "80\n[::]:80",
            'root' => '',
            'upstream' => '',
        ];
    }

    public function submitAddNginxCustomHost(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->nginx_custom_hosts_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->nginx_custom_hosts_error = __('Provisioning and SSH must be ready before adding a custom host.');

            return;
        }

        $this->nginx_custom_hosts_flash = null;
        $this->nginx_custom_hosts_error = null;

        $fields = $this->nginxCustomHostFieldsFromForm($this->nginx_custom_hosts_new);
        $slug = (string) ($this->nginx_custom_hosts_new['slug'] ?? '');

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Add nginx custom host: :slug', ['slug' => $slug]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(NginxCustomHostsConfig::class)->add($this->server, $slug, $fields, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->nginx_custom_hosts_flash = __('Custom host :slug added and nginx reloaded.', ['slug' => $slug]);
            $this->nginx_custom_hosts_show_add = false;
            $this->nginx_custom_hosts_new = [
                'slug' => '',
                'server_names' => '',
                'listen' => "80\n[::]:80",
                'root' => '',
                'upstream' => '',
            ];
            $this->loadNginxCustomHostsConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->nginx_custom_hosts_error = $e->getMessage();
        }
    }

    public function saveNginxCustomHost(string $slug): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->nginx_custom_hosts_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->nginx_custom_hosts_error = __('Provisioning and SSH must be ready before saving custom hosts.');

            return;
        }

        if (! isset($this->nginx_custom_hosts_form[$slug])) {
            return;
        }

        $this->nginx_custom_hosts_flash = null;
        $this->nginx_custom_hosts_error = null;

        $fields = $this->nginxCustomHostFieldsFromForm($this->nginx_custom_hosts_form[$slug]);

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save nginx custom host: :slug', ['slug' => $slug]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(NginxCustomHostsConfig::class)->save($this->server, $slug, $fields, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->nginx_custom_hosts_flash = __('Custom host :slug saved and nginx reloaded.', ['slug' => $slug]);
            $this->loadNginxCustomHostsConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->nginx_custom_hosts_error = $e->getMessage();
        }
    }

    public function removeNginxCustomHost(string $slug): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->nginx_custom_hosts_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->nginx_custom_hosts_error = __('Provisioning and SSH must be ready before removing custom hosts.');

            return;
        }

        $this->nginx_custom_hosts_flash = null;
        $this->nginx_custom_hosts_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Remove nginx custom host: :slug', ['slug' => $slug]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(NginxCustomHostsConfig::class)->remove($this->server, $slug, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->nginx_custom_hosts_flash = __('Custom host :slug removed.', ['slug' => $slug]);
            $this->loadNginxCustomHostsConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->nginx_custom_hosts_error = $e->getMessage();
        }
    }

    public function loadApacheCustomVhostsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->apache_custom_vhosts_error = __('Provisioning and SSH must be ready before reading custom vhosts.');

            return;
        }

        try {
            $result = app(ApacheCustomVhostsConfig::class)->read($this->server);
            $form = [];
            foreach ($result['vhosts'] as $vhost) {
                $slug = (string) ($vhost['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }
                $form[$slug] = [
                    'server_name' => (string) ($vhost['server_name'] ?? ''),
                    'server_aliases' => implode("\n", $vhost['server_aliases'] ?? []),
                    'document_root' => (string) ($vhost['document_root'] ?? ''),
                    'php_socket' => (string) ($vhost['php_socket'] ?? ''),
                ];
            }
            $this->apache_custom_vhosts_form = $form;
            $this->apache_custom_vhosts_loaded = true;
            $this->apache_custom_vhosts_flash = null;
            $this->apache_custom_vhosts_error = null;
            if (! empty($result['unreadable'])) {
                $this->apache_custom_vhosts_error = __('Could not read custom vhost files — check sudo permissions for the deploy user.');
            }
        } catch (\Throwable $e) {
            $this->apache_custom_vhosts_error = __('Failed to read custom vhosts: :msg', ['msg' => $e->getMessage()]);
            $this->apache_custom_vhosts_loaded = false;
        }
    }

    public function openAddApacheCustomVhostForm(): void
    {
        $this->apache_custom_vhosts_show_add = true;
        $this->apache_custom_vhosts_new = [
            'slug' => '',
            'server_name' => '',
            'server_aliases' => '',
            'document_root' => '',
            'php_socket' => '',
        ];
        $this->apache_custom_vhosts_error = null;
        $this->apache_custom_vhosts_flash = null;
    }

    public function cancelAddApacheCustomVhostForm(): void
    {
        $this->apache_custom_vhosts_show_add = false;
        $this->apache_custom_vhosts_new = [
            'slug' => '',
            'server_name' => '',
            'server_aliases' => '',
            'document_root' => '',
            'php_socket' => '',
        ];
    }

    public function submitAddApacheCustomVhost(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->apache_custom_vhosts_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->apache_custom_vhosts_error = __('Provisioning and SSH must be ready before adding a custom vhost.');

            return;
        }

        $this->apache_custom_vhosts_flash = null;
        $this->apache_custom_vhosts_error = null;

        $fields = $this->apacheCustomVhostFieldsFromForm($this->apache_custom_vhosts_new);
        $slug = (string) ($this->apache_custom_vhosts_new['slug'] ?? '');

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Add Apache custom vhost: :slug', ['slug' => $slug]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(ApacheCustomVhostsConfig::class)->add($this->server, $slug, $fields, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->apache_custom_vhosts_flash = __('Custom vhost :slug added and Apache reloaded.', ['slug' => $slug]);
            $this->apache_custom_vhosts_show_add = false;
            $this->apache_custom_vhosts_new = [
                'slug' => '',
                'server_name' => '',
                'server_aliases' => '',
                'document_root' => '',
                'php_socket' => '',
            ];
            $this->loadApacheCustomVhostsConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->apache_custom_vhosts_error = $e->getMessage();
        }
    }

    public function saveApacheCustomVhost(string $slug): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->apache_custom_vhosts_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->apache_custom_vhosts_error = __('Provisioning and SSH must be ready before saving custom vhosts.');

            return;
        }

        if (! isset($this->apache_custom_vhosts_form[$slug])) {
            return;
        }

        $this->apache_custom_vhosts_flash = null;
        $this->apache_custom_vhosts_error = null;

        $fields = $this->apacheCustomVhostFieldsFromForm($this->apache_custom_vhosts_form[$slug]);

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save Apache custom vhost: :slug', ['slug' => $slug]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(ApacheCustomVhostsConfig::class)->save($this->server, $slug, $fields, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->apache_custom_vhosts_flash = __('Custom vhost :slug saved and Apache reloaded.', ['slug' => $slug]);
            $this->loadApacheCustomVhostsConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->apache_custom_vhosts_error = $e->getMessage();
        }
    }

    public function removeApacheCustomVhost(string $slug): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->apache_custom_vhosts_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->apache_custom_vhosts_error = __('Provisioning and SSH must be ready before removing custom vhosts.');

            return;
        }

        $this->apache_custom_vhosts_flash = null;
        $this->apache_custom_vhosts_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Remove Apache custom vhost: :slug', ['slug' => $slug]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(ApacheCustomVhostsConfig::class)->remove($this->server, $slug, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->apache_custom_vhosts_flash = __('Custom vhost :slug removed.', ['slug' => $slug]);
            $this->loadApacheCustomVhostsConfig();
            $this->ensureEngineLiveState(forceFresh: true);
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->apache_custom_vhosts_error = $e->getMessage();
        }
    }

    /**
     * @param  array{server_name?: string, server_aliases?: string, document_root?: string, php_socket?: string}  $form
     * @return array{server_name: string, server_aliases: list<string>, document_root: string, php_socket: string}
     */
    private function apacheCustomVhostFieldsFromForm(array $form): array
    {
        return [
            'server_name' => trim((string) ($form['server_name'] ?? '')),
            'server_aliases' => array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($form['server_aliases'] ?? '')) ?: []))),
            'document_root' => trim((string) ($form['document_root'] ?? '')),
            'php_socket' => trim((string) ($form['php_socket'] ?? '')),
        ];
    }

    /**
     * @param  array{server_names?: string, listen?: string, root?: string, upstream?: string}  $form
     * @return array{server_names: list<string>, listen: list<string>, root: string, upstream: string}
     */
    private function nginxCustomHostFieldsFromForm(array $form): array
    {
        return [
            'server_names' => array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($form['server_names'] ?? '')) ?: []))),
            'listen' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($form['listen'] ?? '')) ?: []))),
            'root' => trim((string) ($form['root'] ?? '')),
            'upstream' => trim((string) ($form['upstream'] ?? '')),
        ];
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

    /**
     * Load a config file's contents into the editor. Path safety is delegated
     * to the service — this method only routes the result and surfaces errors
     * via the toast/banner channel.
     */
    public function loadWebserverConfig(string $path, bool $forceRefresh = false): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if (! $this->engineSupportsConfig($this->workspace_tab)) {
            $this->toastError(__('No config editor for this engine.'));

            return;
        }

        // Fast path: a recent read of this exact file is still cached, so hydrate
        // the editor inline — no queue, no SSH, no poll wait. "Reload" passes
        // $forceRefresh to bypass this and re-read from the server. The cache is
        // dropped on every write/restore (see RunWebserverConfigOpJob), so this
        // never serves bytes that are stale relative to an edit made through dply.
        if (! $forceRefresh) {
            $cached = Cache::get(
                RunWebserverConfigOpJob::fileContentCacheKey($this->server->id, $this->workspace_tab, $path),
            );
            if (is_array($cached) && array_key_exists('contents', $cached)) {
                $this->config_selected_path = $path;
                $this->config_contents = (string) $cached['contents'];
                $this->config_original_contents = $this->config_contents;
                $this->config_truncated_on_load = (bool) ($cached['truncated'] ?? false);
                $this->config_backups = is_array($cached['backups'] ?? null) ? $cached['backups'] : [];
                $this->config_loaded_from_cache = true;
                $this->config_content_cached_at = is_string($cached['cached_at'] ?? null) ? $cached['cached_at'] : null;
                // Clear any in-flight load + transient validate/diff state.
                $this->pending_load_console_id = null;
                $this->pending_load_path = null;
                $this->config_validate_output = null;
                $this->config_validate_ok = null;
                $this->config_last_backup = null;
                $this->webserverConfigSaveDiffOpen = false;
                $this->closeWebserverConfigRevisionDiff();
                $this->refreshWebserverConfigRevisionState();

                return;
            }
        }

        // Queue the load so the banner shows queued→running→completed
        // exactly like the mutating ops. The worker stashes the file
        // contents in a cache key; pickupQueuedConfigLoad() (called from
        // render()) drops them into the editor buffer on the next poll
        // cycle once the row goes to completed.
        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Load webserver config: :path', ['path' => basename($path)]),
        );
        $this->pending_load_console_id = $consoleId;
        $this->pending_load_path = $path;
        // Clear stale buffer state so the textarea doesn't keep showing the
        // previous file while the new one loads on the worker.
        $this->config_selected_path = null;
        $this->config_contents = '';
        $this->config_loaded_from_cache = false;
        $this->config_content_cached_at = null;
        $this->config_truncated_on_load = false;
        $this->config_validate_output = null;
        $this->config_validate_ok = null;
        $this->config_last_backup = null;
        $this->config_backups = [];
        $this->config_original_contents = '';
        $this->webserverConfigSaveDiffOpen = false;
        $this->closeWebserverConfigRevisionDiff();

        RunWebserverConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'read',
            $this->workspace_tab,
            $path,
        );
    }

    /**
     * Watch for a pending queued file-load and, once the worker has
     * stashed the read result in cache, drop it into the editor buffer.
     * Called from render() each tick (the banner's wire:poll drives this).
     */
    protected function pickupQueuedConfigLoad(): void
    {
        if ($this->pending_load_console_id === null) {
            return;
        }
        $row = ConsoleAction::query()->find($this->pending_load_console_id);
        if ($row === null) {
            $this->pending_load_console_id = null;
            $this->pending_load_path = null;

            return;
        }
        if (! in_array($row->status, [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED], true)) {
            return; // still queued / running
        }

        if ($row->status === ConsoleAction::STATUS_COMPLETED) {
            $cached = Cache::pull(
                RunWebserverConfigOpJob::readResultCacheKey($this->pending_load_console_id),
            );
            if (is_array($cached)) {
                $this->config_selected_path = $this->pending_load_path;
                $this->config_contents = (string) ($cached['contents'] ?? '');
                $this->config_original_contents = $this->config_contents;
                $this->config_truncated_on_load = (bool) ($cached['truncated'] ?? false);
                // This is a fresh server read (the worker just ran it), not a
                // hydrate from the per-path content cache.
                $this->config_loaded_from_cache = false;
                $this->config_content_cached_at = null;
                // Bust the cached picker listing so the next render reflects
                // the freshly-read file size + mtime accurately.
                Cache::forget('dply.webserver-config-files:'.$this->server->id.':'.$this->workspace_tab);
                // Backups were gathered by the same worker job (see
                // RunWebserverConfigOpJob's read op) and ride along in the cached
                // payload, so the pickup stays a pure cache read — no inline SSH
                // on the render path. Fall back to the inline fetch only for an
                // older job that stashed a payload before backups were folded in.
                if (array_key_exists('backups', $cached)) {
                    $this->config_backups = is_array($cached['backups']) ? $cached['backups'] : [];
                } else {
                    $this->refreshConfigBackups();
                }
                $this->refreshWebserverConfigRevisionState();
            }
        }
        $this->pending_load_console_id = null;
        $this->pending_load_path = null;
    }

    /**
     * Validate the current on-disk config (NOT the editor buffer) — useful
     * after running `caddy fmt --overwrite` or after fixing a problem on the
     * server out of band. Result lands in the same surface the save flow uses.
     */
    public function validateWebserverConfig(): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if (! $this->engineSupportsConfig($this->workspace_tab)) {
            $this->toastError(__('No validate command for this engine.'));

            return;
        }

        try {
            $result = app(RemoteWebserverConfigService::class)->validate($this->server, $this->workspace_tab);
        } catch (\Throwable $e) {
            $this->toastError(__('Validate failed to run: :msg', ['msg' => $e->getMessage()]));

            return;
        }

        $this->config_validate_output = $result['output'];
        $this->config_validate_ok = $result['ok'];
        if ($result['ok']) {
            $this->toastSuccess(__('Config validated.'));
        } else {
            $this->toastError(__('Config validation reported problems — see output below.'));
        }
    }

    /**
     * Persist the editor contents to the loaded path. Backup + atomic write +
     * post-write validate is all done in the service; this method only
     * dispatches and surfaces the result.
     */
    public function saveWebserverConfig(): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }
        if ($this->config_truncated_on_load) {
            // Refuse to save when the editor only has a HEAD-truncated view of
            // the file — the operator would silently chop off everything past
            // the preview cap. They need to bump config_preview_max_bytes (or
            // edit the file on the server directly) first.
            $this->toastError(__('Refusing to save: this file is too large for the editor and was loaded truncated.'));

            return;
        }

        if ($this->webserverConfigRevisionsEnabled()) {
            app(ServerWebserverConfigEditor::class)->ensureBaseline(
                $this->server,
                $this->workspace_tab,
                (string) $this->config_selected_path,
                $this->config_original_contents,
                auth()->user(),
            );
        }

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save webserver config: :path', ['path' => basename((string) $this->config_selected_path)]),
        );
        $this->pending_write_console_id = $consoleId;
        RunWebserverConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'write',
            $this->workspace_tab,
            $this->config_selected_path,
            $this->config_contents,
            '',
            auth()->id(),
            $this->webserverConfigRevisionsEnabled(),
        );
        $this->webserverConfigSaveDiffOpen = false;
        $this->toastSuccess(__('Save queued — progress shows in the banner above.'));
    }

    /**
     * Dry-run the current buffer against the engine validator without
     * committing. The service stages the proposed content, swaps it into
     * the live path, runs the validator, and ALWAYS restores. Lets the
     * operator confirm syntax before clicking Save.
     */
    public function validateWebserverConfigBuffer(): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }
        if ($this->config_truncated_on_load) {
            $this->toastError(__('Buffer is truncated — validation would chop the file.'));

            return;
        }

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Validate webserver config buffer: :path', ['path' => basename((string) $this->config_selected_path)]),
        );
        $this->pending_validate_console_id = $consoleId;
        RunWebserverConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'validate',
            $this->workspace_tab,
            $this->config_selected_path,
            $this->config_contents,
        );
        $this->toastSuccess(__('Validation queued — progress shows in the banner above.'));
    }

    /**
     * Drop the dply-canonical content for the currently-loaded path into the
     * editor buffer. Doesn't write — the operator still has to click Save.
     * Limited to engines/paths dply owns a builder for (OLS httpd_config.conf
     * and per-site vhconf.conf for v1).
     */
    public function resetWebserverConfigToDefault(): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }

        try {
            $contents = app(RemoteWebserverConfigService::class)->defaultContent(
                $this->server,
                $this->workspace_tab,
                $this->config_selected_path,
            );
        } catch (\Throwable $e) {
            $this->toastError(__('Reset failed: :msg', ['msg' => $e->getMessage()]));

            return;
        }

        $this->config_contents = $contents;
        $this->config_validate_output = null;
        $this->config_validate_ok = null;
        $this->toastSuccess(__('Loaded the dply-canonical content. Review and click Save to commit.'));
    }

    /**
     * Restore a previous backup over the currently-loaded path. The service
     * snapshots the current state first, so a botched restore can be undone
     * by selecting the newest backup created at this step.
     */
    public function restoreWebserverConfigBackup(string $backup_path): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded — open one before restoring.'));

            return;
        }

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Restore revision: :path', ['path' => basename($backup_path)]),
        );
        RunWebserverConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'restore',
            $this->workspace_tab,
            $this->config_selected_path,
            '',
            $backup_path,
        );
        $this->toastSuccess(__('Restore queued — progress shows in the banner above.'));
    }

    /**
     * Refresh the buffer the Logs tab renders. `$kind` is one of `access`,
     * `error`, or `journal` — the available choices depend on the engine
     * layout. Limited to 300 lines unless explicitly overridden.
     */
    public function refreshWebserverLog(?string $kind = null, ?int $lines = null): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }

        if ($kind !== null) {
            $this->log_kind = in_array($kind, ['access', 'error', 'journal'], true) ? $kind : 'access';
        }
        if ($lines !== null) {
            $this->log_lines = max(50, min(2000, $lines));
        }

        $layout = (array) config('server_manage.webserver_config_layout.'.$this->workspace_tab, []);
        $path = match ($this->log_kind) {
            'access' => $layout['access_log'] ?? null,
            'error' => $layout['error_log'] ?? null,
            'journal' => null,
            default => null,
        };

        try {
            if ($this->log_kind === 'journal' || $path === null) {
                $unit = (string) ($layout['journal_unit'] ?? $this->workspace_tab);
                $script = sprintf(
                    '(sudo -n journalctl --no-pager -eu %1$s -n %2$d 2>&1 || journalctl --no-pager -eu %1$s -n %2$d 2>&1)',
                    escapeshellarg($unit),
                    $this->log_lines,
                );
            } else {
                $script = sprintf(
                    '(sudo -n tail -n %1$d %2$s 2>&1 || tail -n %1$d %2$s 2>&1)',
                    $this->log_lines,
                    escapeshellarg($path),
                );
            }
            $out = $this->runManageInlineBash(
                $this->server,
                'webserver-log:'.$this->workspace_tab.':'.$this->log_kind,
                $script,
                function (string $type, string $buffer): void {},
                30,
            );
            $this->log_output = ServerManageSshExecutor::stripSshClientNoise($out->getBuffer());
        } catch (\Throwable $e) {
            $this->log_output = '[error] '.$e->getMessage();
        }
    }

    public function toggleWebserverLogLive(): void
    {
        $this->log_live = ! $this->log_live;
        if ($this->log_live) {
            $this->refreshWebserverLog();
        }
    }

    public function toggleWebserverLogRaw(): void
    {
        $this->log_raw = ! $this->log_raw;
    }

    /**
     * Structured view of the current access-log buffer for the Logs tab.
     *
     * Only engages for the nginx-family "combined" format and only on the
     * access log; everything else (error log, journal, non-combined engines,
     * or operator-forced raw mode) returns ['structured' => false] so the
     * blade falls back to the existing <pre> dump. The SSH fetch itself is
     * unchanged — this purely parses the already-fetched text.
     *
     * @return array<string, mixed>
     */
    public function getParsedAccessLogProperty(): array
    {
        $off = ['structured' => false, 'rows' => [], 'summary' => null];

        if ($this->log_raw || $this->log_kind !== 'access' || $this->log_output === '') {
            return $off;
        }

        // Combined format is the nginx default. Other engines (caddy JSON,
        // apache, haproxy, …) won't match looksLikeCombined() and fall back.
        $parser = app(NginxAccessLogParser::class);
        if (! $parser->looksLikeCombined($this->log_output)) {
            return $off;
        }

        $rows = $parser->parse($this->log_output);

        return [
            'structured' => true,
            'rows' => $rows,
            'summary' => $parser->summarize($rows),
        ];
    }

    public function render(ServerManageToolsReport $toolsReport): View
    {
        $consoleLookup = app(ServerConsoleActionLookup::class);
        if ($consoleLookup->shouldRefreshServerMeta($this->server, 'webserver')) {
            $this->server->refresh();
        }

        if (auth()->check() && auth()->user()?->currentOrganization()) {
            Feature::loadMissing(['workspace.webserver_config_diff']);
        }

        $this->pickupQueuedConfigLoad();
        $this->pickupQueuedConfigWrite();
        $this->pickupQueuedConfigValidate();

        // listFiles does an SSH call. render() runs on every Livewire commit,
        // including every banner wire:poll tick — so without caching it,
        // every 4s poll fires a fresh SSH connection. Cache for 10s per
        // (server, engine) keeps the picker fresh enough but lets the polls
        // re-use the result. Also gate on the Config sub-tab so other sub-
        // tabs (Overview / Live-state) skip the SSH entirely.
        $configFiles = [];
        if ($this->engine_subtab === 'config'
            && in_array($this->workspace_tab, ['nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy', 'envoy', 'openresty'], true)
            && $this->serverOpsReady()) {
            $cacheKey = 'dply.webserver-config-files:'.$this->server->id.':'.$this->workspace_tab;
            try {
                $configFiles = Cache::remember(
                    $cacheKey,
                    10,
                    fn () => app(RemoteWebserverConfigService::class)->listFiles($this->server, $this->workspace_tab),
                );
            } catch (\Throwable) {
                $configFiles = [];
            }
            $configFiles = $this->annotateCachedConfigFiles($configFiles);
        }

        return view('livewire.servers.workspace-webserver', array_merge(
            WebserverWorkspaceViewData::for($this->server, $this),
            $this->webserverConfigRevisionViewData(),
            [
                'configPreviews' => config('server_manage.config_previews', []),
                'serviceActions' => config('server_manage.service_actions', []),
                'dangerousActions' => config('server_manage.dangerous_actions', []),
                'autoUpdateIntervals' => config('server_manage.auto_update_intervals', []),
                'webserverConfigLayout' => config('server_manage.webserver_config_layout', []),
                'webserverConfigFiles' => $configFiles,
                'deletionSummary' => $this->showRemoveServerModal
                    ? ServerRemovalAdvisor::summary($this->server)
                    : null,
            ],
        ));
    }

    /**
     * True when the active workspace tab is a webserver engine with a known
     * config layout. The {@see RemoteWebserverConfigService} would reject the
     * call anyway, but this lets the UI hide the affordances pre-emptively.
     */
    protected function engineSupportsConfig(string $engine): bool
    {
        return in_array($engine, ['nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy', 'envoy', 'openresty'], true);
    }

    /**
     * Tag each picker row with whether its contents are in the per-path content
     * cache, so the file list can show a "loads instantly" hint. Cheap: a cache
     * key existence check per file, evaluated live each render (not folded into
     * the 10s listing cache) so it tracks loads/saves as they happen.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, array<string, mixed>>
     */
    protected function annotateCachedConfigFiles(array $files): array
    {
        foreach ($files as $i => $f) {
            $path = (string) ($f['path'] ?? '');
            $files[$i]['cached'] = $path !== '' && Cache::has(
                RunWebserverConfigOpJob::fileContentCacheKey($this->server->id, $this->workspace_tab, $path),
            );
        }

        return $files;
    }

    /**
     * Common pre-flight for editor / log actions: requires ops-ready, refuses
     * deployers. Returns false (and emits a toast) when the guard trips, so
     * callers can early-return without duplicating the same boilerplate.
     */
    protected function guardConfigAction(): bool
    {
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot edit server config.'));

            return false;
        }
        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before this action.'));

            return false;
        }

        return true;
    }

    protected function refreshConfigBackups(): void
    {
        if ($this->config_selected_path === null) {
            $this->config_backups = [];

            return;
        }
        try {
            $this->config_backups = app(RemoteWebserverConfigService::class)->listBackups(
                $this->server,
                $this->workspace_tab,
                $this->config_selected_path,
            );
        } catch (\Throwable) {
            $this->config_backups = [];
        }
    }

    protected function resetConfigEditorState(): void
    {
        $this->config_selected_path = null;
        $this->config_contents = '';
        $this->config_validate_output = null;
        $this->config_validate_ok = null;
        $this->config_truncated_on_load = false;
        $this->config_last_backup = null;
        $this->config_backups = [];
        $this->config_loaded_from_cache = false;
        $this->config_content_cached_at = null;
    }

    protected function resetLogViewerState(): void
    {
        $this->log_kind = 'access';
        $this->log_output = '';
        $this->log_lines = 300;
        $this->log_live = false;
        $this->log_raw = false;
    }
}
