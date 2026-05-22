<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\RunWebserverConfigOpJob;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ApacheGlobalOptionsConfig;
use App\Services\Servers\ApacheModulesConfig;
use App\Services\Servers\CaddyGlobalOptionsConfig;
use App\Services\Servers\CaddySnippetsConfig;
use App\Services\Servers\HaproxyBackendsConfig;
use App\Services\Servers\HaproxyFrontendsConfig;
use App\Services\Servers\HaproxyGlobalOptionsConfig;
use App\Services\Servers\LiveState\ApacheLiveStateProbe;
use App\Services\Servers\LiveState\CaddyLiveStateProbe;
use App\Services\Servers\LiveState\EngineLiveStateProbe;
use App\Services\Servers\LiveState\HaproxyLiveStateProbe;
use App\Services\Servers\LiveState\NginxLiveStateProbe;
use App\Services\Servers\LiveState\OlsLiveStateProbe;
use App\Services\Servers\LiveState\TraefikLiveStateProbe;
use App\Services\Servers\NginxGlobalOptionsConfig;
use App\Services\Servers\NginxUpstreamsConfig;
use App\Services\Servers\OpenLiteSpeedCacheModuleConfig;
use App\Services\Servers\OpenLiteSpeedExtAppsConfig;
use App\Services\Servers\OpenLiteSpeedListenersConfig;
use App\Services\Servers\OpenLiteSpeedVhostsConfig;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerMetricsRangeQuery;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\TraefikStaticConfigOptions;
use App\Services\Servers\WebserverCertsAggregator;
use App\Services\Servers\WebserverConfigDriftDetector;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 *   - `render()` points at a dedicated `workspace-webserver.blade.php` view
 *     that wraps the group-web partial in {@see <x-server-workspace-layout>}
 *     with `active="webserver"` (sidebar highlight).
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

    // ---- Log viewer state --------------------------------------------

    /** Which log to read: 'access', 'error', or 'journal'. */
    public string $log_kind = 'access';

    /** Last fetched log buffer; rendered in a <pre> on the Logs tab. */
    public string $log_output = '';

    /** How many trailing lines the last fetch grabbed. */
    public int $log_lines = 300;

    /** When true, the Logs tab adds a wire:poll so the buffer refreshes every few seconds. */
    public bool $log_live = false;

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

    // ---- Cross-engine TLS certificates dashboard (Overview tab card).
    /**
     * The aggregated list. Each entry: path / subject / issuer / not_after /
     * expires_at / days_until_expiry / urgency / engine_hint / error.
     *
     * @var list<array<string, mixed>>
     */
    public array $tls_certs = [];

    public ?string $tls_certs_scanned_at_iso = null;

    public bool $tls_certs_unreadable = false;

    public bool $tls_certs_loaded = false;

    public ?string $tls_certs_error = null;

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

        // Eager-load the Overview cards (TLS certs + drift detector) so
        // they paint with data on first render. Services cache for 60s so
        // subsequent navigations are cheap.
        if ($this->workspace_tab === 'overview' && $this->serverOpsReady()) {
            $this->loadTlsCertsDashboard();
            $this->loadDriftDetector();
        }
        if ($this->workspace_tab === 'openlitespeed' && $this->engine_subtab === 'extapps') {
            $this->loadOlsExtAppsConfig();
        }
        if ($this->workspace_tab === 'openlitespeed' && $this->engine_subtab === 'listeners') {
            $this->loadOlsListenersConfig();
        }
        if ($this->workspace_tab === 'openlitespeed' && $this->engine_subtab === 'vhosts') {
            $this->loadOlsVhostsConfig();
        }
        if ($this->workspace_tab === 'caddy' && $this->engine_subtab === 'admin') {
            $this->loadCaddyGlobalsConfig();
        }
        if ($this->workspace_tab === 'caddy' && $this->engine_subtab === 'snippets') {
            $this->loadCaddySnippetsConfig();
        }
        if ($this->workspace_tab === 'nginx' && $this->engine_subtab === 'workers') {
            $this->loadNginxGlobalsConfig();
        }
        if ($this->workspace_tab === 'apache' && $this->engine_subtab === 'workers') {
            $this->loadApacheGlobalsConfig();
        }
        if ($this->workspace_tab === 'nginx' && $this->engine_subtab === 'upstreams') {
            $this->loadNginxUpstreamsConfig();
        }
        if ($this->workspace_tab === 'apache' && $this->engine_subtab === 'modules') {
            $this->loadApacheModulesConfig();
        }
        if ($this->workspace_tab === 'haproxy' && $this->engine_subtab === 'runtime') {
            $this->loadHaproxyGlobalsConfig();
        }
        if ($this->workspace_tab === 'traefik' && $this->engine_subtab === 'providers') {
            $this->loadTraefikStaticConfig();
        }
        if ($this->workspace_tab === 'haproxy' && $this->engine_subtab === 'frontends') {
            $this->loadHaproxyFrontendsConfig();
        }
        if ($this->workspace_tab === 'haproxy' && $this->engine_subtab === 'backends') {
            $this->loadHaproxyBackendsConfig();
        }
    }

    public function setWorkspaceTab(string $tab): void
    {
        $allowed = ['overview', 'nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy', 'advanced'];
        $this->workspace_tab = in_array($tab, $allowed, true) ? $tab : 'overview';
        // Reset the sub-tab on every top-level switch so the operator always
        // lands on the actionable view first. Skipping this would leave
        // Caddy on `info` after they navigated away from Nginx's `info`.
        $this->engine_subtab = 'overview';
        $this->resetConfigEditorState();
        $this->resetLogViewerState();

        // Eager-load the TLS dashboard + drift detector when landing on
        // Overview so the cards paint with data on first render rather
        // than blanking until the operator clicks "Rescan".
        if ($this->workspace_tab === 'overview' && $this->serverOpsReady()) {
            $this->loadTlsCertsDashboard();
            $this->loadDriftDetector();
        }
    }

    /**
     * Force a fresh SSH scan (bypassing the 60s cache) — wired to the
     * "Rescan" button on the Overview TLS card.
     */
    public function refreshTlsCertsDashboard(): void
    {
        $this->loadTlsCertsDashboard(forceFresh: true);
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
        // Engine-specific live-state sub-tabs (Vhosts/Listeners/etc.) live
        // alongside the common ones (overview/info/logs/config). The Tools
        // sub-tab was retired — its diagnostic buttons now render inline in
        // the Overview's Tools row. We keep 'tools' silently mapping to
        // overview so an old bookmark / URL doesn't break the render.
        $allowed = [
            'overview', 'info', 'logs', 'config',
            // OLS
            'vhosts', 'listeners', 'extapps', 'cache',
            // nginx
            'hosts', 'upstreams', 'certs', 'workers',
            // caddy (routes/upstreams/certs share with nginx; admin + snippets are unique)
            'routes', 'admin', 'snippets',
            // apache (vhosts/workers/certs shared; modules unique)
            'modules',
            // traefik
            'routers', 'services', 'middlewares', 'providers',
            // haproxy
            'frontends', 'backends', 'ssl', 'runtime',
        ];
        if ($subtab === 'tools') {
            $subtab = 'overview';
        }
        $this->engine_subtab = in_array($subtab, $allowed, true) ? $subtab : 'overview';
        if ($this->engine_subtab !== 'config') {
            $this->resetConfigEditorState();
        }
        if ($this->engine_subtab !== 'logs') {
            $this->resetLogViewerState();
        }
        if ($this->engine_subtab !== 'cache') {
            $this->ols_cache_loaded = false;
            $this->ols_cache_form = [];
            $this->ols_cache_flash = null;
            $this->ols_cache_error = null;
        } elseif ($this->workspace_tab === 'openlitespeed') {
            $this->loadOlsCacheConfig();
        }

        if ($this->engine_subtab !== 'extapps') {
            $this->ols_extapps_loaded = false;
            $this->ols_extapps_form = [];
            $this->ols_extapps_identity = [];
            $this->ols_extapps_flash = null;
            $this->ols_extapps_error = null;
        } elseif ($this->workspace_tab === 'openlitespeed') {
            $this->loadOlsExtAppsConfig();
        }

        if ($this->engine_subtab !== 'listeners') {
            $this->ols_listeners_loaded = false;
            $this->ols_listeners_form = [];
            $this->ols_listeners_identity = [];
            $this->ols_listeners_maps = [];
            $this->ols_listeners_flash = null;
            $this->ols_listeners_error = null;
            $this->ols_listeners_show_add = false;
        } elseif ($this->workspace_tab === 'openlitespeed') {
            $this->loadOlsListenersConfig();
        }

        if ($this->engine_subtab !== 'vhosts') {
            $this->ols_vhosts_loaded = false;
            $this->ols_vhosts_form = [];
            $this->ols_vhosts_identity = [];
            $this->ols_vhosts_flash = null;
            $this->ols_vhosts_error = null;
        } elseif ($this->workspace_tab === 'openlitespeed') {
            $this->loadOlsVhostsConfig();
        }

        // Caddy global options live on the Admin sub-tab (alongside the
        // version/admin endpoint live-state we already render there).
        if ($this->engine_subtab !== 'admin') {
            $this->caddy_globals_loaded = false;
            $this->caddy_globals_form = [];
            $this->caddy_globals_flash = null;
            $this->caddy_globals_error = null;
        } elseif ($this->workspace_tab === 'caddy') {
            $this->loadCaddyGlobalsConfig();
        }

        if ($this->engine_subtab !== 'snippets') {
            $this->caddy_snippets_loaded = false;
            $this->caddy_snippets_form = [];
            $this->caddy_snippets_flash = null;
            $this->caddy_snippets_error = null;
            $this->caddy_snippets_show_add = false;
        } elseif ($this->workspace_tab === 'caddy') {
            $this->loadCaddySnippetsConfig();
        }

        if ($this->engine_subtab !== 'upstreams') {
            $this->nginx_upstreams_loaded = false;
            $this->nginx_upstreams_form = [];
            $this->nginx_upstreams_servers_text = [];
            $this->nginx_upstreams_flash = null;
            $this->nginx_upstreams_error = null;
            $this->nginx_upstreams_show_add = false;
        } elseif ($this->workspace_tab === 'nginx') {
            $this->loadNginxUpstreamsConfig();
        }

        if ($this->engine_subtab !== 'modules') {
            $this->apache_modules_loaded = false;
            $this->apache_modules_list = [];
            $this->apache_modules_flash = null;
            $this->apache_modules_error = null;
        } elseif ($this->workspace_tab === 'apache') {
            $this->loadApacheModulesConfig();
        }

        if ($this->engine_subtab !== 'runtime') {
            $this->haproxy_globals_loaded = false;
            $this->haproxy_globals_form = [];
            $this->haproxy_globals_flash = null;
            $this->haproxy_globals_error = null;
        } elseif ($this->workspace_tab === 'haproxy') {
            $this->loadHaproxyGlobalsConfig();
        }

        if ($this->engine_subtab !== 'frontends') {
            $this->haproxy_frontends_loaded = false;
            $this->haproxy_frontends_form = [];
            $this->haproxy_frontends_binds_text = [];
            $this->haproxy_frontends_flash = null;
            $this->haproxy_frontends_error = null;
            $this->haproxy_frontends_show_add = false;
        } elseif ($this->workspace_tab === 'haproxy') {
            $this->loadHaproxyFrontendsConfig();
        }

        if ($this->engine_subtab !== 'backends') {
            $this->haproxy_backends_loaded = false;
            $this->haproxy_backends_form = [];
            $this->haproxy_backends_servers_text = [];
            $this->haproxy_backends_flash = null;
            $this->haproxy_backends_error = null;
            $this->haproxy_backends_show_add = false;
        } elseif ($this->workspace_tab === 'haproxy') {
            $this->loadHaproxyBackendsConfig();
        }

        // Traefik static config lives on the Providers sub-tab (since that's
        // where the file-provider that loads /etc/traefik/dynamic/* is
        // declared — natural home for the rest of the static settings).
        if ($this->engine_subtab !== 'providers') {
            $this->traefik_static_loaded = false;
            $this->traefik_static_form = [];
            $this->traefik_static_flash = null;
            $this->traefik_static_error = null;
        } elseif ($this->workspace_tab === 'traefik') {
            $this->loadTraefikStaticConfig();
        }

        // nginx global options live on the Workers sub-tab (same row as
        // the runtime worker counters from stub_status). Apache reuses the
        // same Workers sub-tab for its global options + MPM tuning.
        if ($this->engine_subtab !== 'workers') {
            $this->nginx_globals_loaded = false;
            $this->nginx_globals_form = [];
            $this->nginx_globals_flash = null;
            $this->nginx_globals_error = null;
            $this->apache_globals_loaded = false;
            $this->apache_globals_form = [];
            $this->apache_globals_flash = null;
            $this->apache_globals_error = null;
        } else {
            if ($this->workspace_tab === 'nginx') {
                $this->loadNginxGlobalsConfig();
            } elseif ($this->workspace_tab === 'apache') {
                $this->loadApacheGlobalsConfig();
            }
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

    public function loadCaddyGlobalsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->caddy_globals_error = __('Provisioning and SSH must be ready before reading the Caddyfile.');

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

    public function loadTlsCertsDashboard(bool $forceFresh = false): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->tls_certs_error = __('Provisioning and SSH must be ready before scanning TLS certs.');

            return;
        }

        try {
            $result = app(WebserverCertsAggregator::class)->aggregate($this->server, $forceFresh);
            $this->tls_certs = array_map(function (array $row): array {
                $row['expires_at'] = $row['expires_at'] instanceof CarbonImmutable
                    ? $row['expires_at']->toIso8601String()
                    : null;

                return $row;
            }, $result['certs']);
            $this->tls_certs_scanned_at_iso = $result['scanned_at'] instanceof CarbonImmutable
                ? $result['scanned_at']->toIso8601String()
                : null;
            $this->tls_certs_unreadable = $result['unreadable'];
            $this->tls_certs_loaded = true;
            $this->tls_certs_error = null;
        } catch (\Throwable $e) {
            $this->tls_certs_error = __('Failed to scan TLS certs: :msg', ['msg' => $e->getMessage()]);
            $this->tls_certs_loaded = false;
        }
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
        $this->apache_modules_filter = in_array($filter, ['all', 'mpm', 'tls', 'auth', 'proxy', 'perf', 'observability', 'core', 'other'], true) ? $filter : 'all';
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

    public function loadCaddySnippetsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->caddy_snippets_error = __('Provisioning and SSH must be ready before reading the Caddyfile.');

            return;
        }

        try {
            $result = app(CaddySnippetsConfig::class)->read($this->server);
            $form = [];
            foreach ($result['snippets'] as $snippet) {
                $form[$snippet['name']] = $snippet['body'];
            }
            $this->caddy_snippets_form = $form;
            $this->caddy_snippets_loaded = true;
            $this->caddy_snippets_flash = null;
            $this->caddy_snippets_error = null;
            if (! empty($result['unreadable'])) {
                $this->caddy_snippets_error = __('Could not read /etc/caddy/Caddyfile — check sudo permissions for the deploy user.');
            } elseif (empty($result['snippets'])) {
                $this->caddy_snippets_flash = __('No snippet blocks found in the Caddyfile yet.');
            }
        } catch (\Throwable $e) {
            $this->caddy_snippets_error = __('Failed to read snippets: :msg', ['msg' => $e->getMessage()]);
            $this->caddy_snippets_loaded = false;
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
                $this->ols_vhosts_flash = __('No vhTemplate blocks found in httpd_config.conf — add a site to populate this list.');
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
     * Refresh-now action for the per-engine live-state sub-tabs. Runs a
     * fresh probe (synchronous SSH) and updates the cached state on
     * Server.meta. Returns nothing — the blade re-renders against the
     * new cached state on next paint.
     */
    public function refreshEngineLiveState(): void
    {
        $this->authorize('view', $this->server);
        $engine = $this->workspace_tab;
        $probe = $this->resolveLiveStateProbe($engine);
        if ($probe === null) {
            $this->toastError(__('No live-state probe registered for :engine.', ['engine' => $engine]));

            return;
        }
        try {
            $probe->probe($this->server->fresh(), forceFresh: true);
            $this->server->refresh();
            $this->toastSuccess(__('Refreshed.'));
        } catch (\Throwable $e) {
            $this->toastError(__('Refresh failed: :msg', ['msg' => $e->getMessage()]));
        }
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
            default => null,
        };
    }

    /**
     * Load a config file's contents into the editor. Path safety is delegated
     * to the service — this method only routes the result and surfaces errors
     * via the toast/banner channel.
     */
    public function loadWebserverConfig(string $path): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if (! $this->engineSupportsConfig($this->workspace_tab)) {
            $this->toastError(__('No config editor for this engine.'));

            return;
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
        $this->config_truncated_on_load = false;
        $this->config_validate_output = null;
        $this->config_validate_ok = null;
        $this->config_last_backup = null;
        $this->config_backups = [];

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
                $this->config_truncated_on_load = (bool) ($cached['truncated'] ?? false);
                // Bust the cached picker listing so the next render reflects
                // the freshly-read file size + mtime accurately.
                Cache::forget('dply.webserver-config-files:'.$this->server->id.':'.$this->workspace_tab);
                $this->refreshConfigBackups();
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

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save webserver config: :path', ['path' => basename((string) $this->config_selected_path)]),
        );
        RunWebserverConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'write',
            $this->workspace_tab,
            $this->config_selected_path,
            $this->config_contents,
        );
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

    public function render(): View
    {
        $this->server->refresh();
        $this->pickupQueuedConfigLoad();

        // listFiles does an SSH call. render() runs on every Livewire commit,
        // including every banner wire:poll tick — so without caching it,
        // every 4s poll fires a fresh SSH connection. Cache for 10s per
        // (server, engine) keeps the picker fresh enough but lets the polls
        // re-use the result. Also gate on the Config sub-tab so other sub-
        // tabs (Overview / Live-state) skip the SSH entirely.
        $configFiles = [];
        if ($this->engine_subtab === 'config'
            && in_array($this->workspace_tab, ['nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy'], true)
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
        }

        return view('livewire.servers.workspace-webserver', [
            'configPreviews' => config('server_manage.config_previews', []),
            'serviceActions' => config('server_manage.service_actions', []),
            'dangerousActions' => config('server_manage.dangerous_actions', []),
            'autoUpdateIntervals' => config('server_manage.auto_update_intervals', []),
            'webserverConfigLayout' => config('server_manage.webserver_config_layout', []),
            'webserverConfigFiles' => $configFiles,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }

    /**
     * True when the active workspace tab is a webserver engine with a known
     * config layout. The {@see RemoteWebserverConfigService} would reject the
     * call anyway, but this lets the UI hide the affordances pre-emptively.
     */
    protected function engineSupportsConfig(string $engine): bool
    {
        return in_array($engine, ['nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy'], true);
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
    }

    protected function resetLogViewerState(): void
    {
        $this->log_kind = 'access';
        $this->log_output = '';
        $this->log_lines = 300;
        $this->log_live = false;
    }
}
