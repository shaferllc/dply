<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Models\Server;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerManageSshExecutor;
use Illuminate\Contracts\View\View;
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
        if ($this->workspace_tab === 'openlitespeed' && $this->engine_subtab === 'extapps') {
            $this->loadOlsExtAppsConfig();
        }
        if ($this->workspace_tab === 'openlitespeed' && $this->engine_subtab === 'listeners') {
            $this->loadOlsListenersConfig();
        }
        if ($this->workspace_tab === 'openlitespeed' && $this->engine_subtab === 'vhosts') {
            $this->loadOlsVhostsConfig();
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
    }

    /**
     * Range setter for the per-engine Overview health charts. Validates
     * against ServerMetricsRangeQuery's known ranges; falls back to '1h'.
     */
    public function setEngineMetricsRange(string $range): void
    {
        $allowed = array_keys(\App\Services\Servers\ServerMetricsRangeQuery::RANGES);
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
            // caddy (routes/upstreams/certs share with nginx; admin is unique)
            'routes', 'admin',
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
            $result = app(\App\Services\Servers\OpenLiteSpeedCacheModuleConfig::class)->read($this->server);
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
        \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => \App\Models\ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new \App\Services\ConsoleActions\ConsoleEmitter($consoleId);

        try {
            app(\App\Services\Servers\OpenLiteSpeedCacheModuleConfig::class)
                ->save($this->server, $this->ols_cache_form, $emitter);
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_cache_flash = __('Cache config saved and OpenLiteSpeed reloaded.');
            // Re-read to catch any directive the parser normalized (e.g. 1/0
            // round-tripped from on/off) so the form reflects what's on disk.
            $this->loadOlsCacheConfig();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_FAILED,
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
            $result = app(\App\Services\Servers\OpenLiteSpeedExtAppsConfig::class)->read($this->server);
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
        \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => \App\Models\ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new \App\Services\ConsoleActions\ConsoleEmitter($consoleId);

        try {
            app(\App\Services\Servers\OpenLiteSpeedExtAppsConfig::class)
                ->save($this->server, $this->ols_extapps_form, $emitter);
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_extapps_flash = __('ExtApp config saved and OpenLiteSpeed reloaded.');
            $this->loadOlsExtAppsConfig();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_FAILED,
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
        \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => \App\Models\ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new \App\Services\ConsoleActions\ConsoleEmitter($consoleId);

        try {
            app(\App\Services\Servers\OpenLiteSpeedExtAppsConfig::class)
                ->addApp($this->server, $this->ols_extapps_new_app, [], $emitter);
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_extapps_flash = __('ExtApp :name added and OpenLiteSpeed reloaded.', ['name' => $this->ols_extapps_new_app['name']]);
            $this->ols_extapps_show_add = false;
            $this->ols_extapps_new_app = ['name' => '', 'type' => 'lsapi', 'address' => '', 'path' => ''];
            $this->loadOlsExtAppsConfig();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->ols_extapps_error = $e->getMessage();
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
            $result = app(\App\Services\Servers\OpenLiteSpeedVhostsConfig::class)->read($this->server);
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
        \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => \App\Models\ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new \App\Services\ConsoleActions\ConsoleEmitter($consoleId);

        try {
            app(\App\Services\Servers\OpenLiteSpeedVhostsConfig::class)
                ->save($this->server, $updates, $emitter);
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_vhosts_flash = __('Vhost config saved and OpenLiteSpeed reloaded.');
            $this->loadOlsVhostsConfig();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_FAILED,
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
            $result = app(\App\Services\Servers\OpenLiteSpeedListenersConfig::class)->read($this->server);
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
        \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => \App\Models\ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new \App\Services\ConsoleActions\ConsoleEmitter($consoleId);

        try {
            app(\App\Services\Servers\OpenLiteSpeedListenersConfig::class)
                ->save($this->server, $this->ols_listeners_form, $emitter);
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_listeners_flash = __('Listener config saved and OpenLiteSpeed reloaded.');
            $this->loadOlsListenersConfig();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_FAILED,
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
        \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => \App\Models\ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new \App\Services\ConsoleActions\ConsoleEmitter($consoleId);

        try {
            app(\App\Services\Servers\OpenLiteSpeedListenersConfig::class)
                ->addListener($this->server, $this->ols_listeners_new, [], $emitter);
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_listeners_flash = __('Listener :name added and OpenLiteSpeed reloaded.', ['name' => $this->ols_listeners_new['name']]);
            $this->ols_listeners_show_add = false;
            $this->ols_listeners_new = ['name' => '', 'address' => '', 'secure' => '0', 'keyFile' => '', 'certFile' => ''];
            $this->loadOlsListenersConfig();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_FAILED,
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
        \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => \App\Models\ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new \App\Services\ConsoleActions\ConsoleEmitter($consoleId);

        try {
            app(\App\Services\Servers\OpenLiteSpeedListenersConfig::class)
                ->removeListener($this->server, $name, $emitter);
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_listeners_flash = __('Listener :name removed and OpenLiteSpeed reloaded.', ['name' => $name]);
            $this->loadOlsListenersConfig();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_FAILED,
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
        \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => \App\Models\ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new \App\Services\ConsoleActions\ConsoleEmitter($consoleId);

        try {
            app(\App\Services\Servers\OpenLiteSpeedExtAppsConfig::class)
                ->removeApp($this->server, $name, $emitter);
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->ols_extapps_flash = __('ExtApp :name removed and OpenLiteSpeed reloaded.', ['name' => $name]);
            $this->loadOlsExtAppsConfig();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_FAILED,
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
    private function resolveLiveStateProbe(string $engine): ?\App\Services\Servers\LiveState\EngineLiveStateProbe
    {
        return match ($engine) {
            'openlitespeed' => app(\App\Services\Servers\LiveState\OlsLiveStateProbe::class),
            'traefik' => app(\App\Services\Servers\LiveState\TraefikLiveStateProbe::class),
            'haproxy' => app(\App\Services\Servers\LiveState\HaproxyLiveStateProbe::class),
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

        // File reads are sub-second cats. Running them through a banner row
        // would mean every click flashes a queued→completed banner before
        // the buffer even paints — noisier than helpful. Stay sync, no
        // banner. The actual mutating actions (save/validate/restore) get
        // queued so their banner appears immediately and progresses.
        try {
            $result = app(RemoteWebserverConfigService::class)->read($this->server, $this->workspace_tab, $path);
        } catch (\Throwable $e) {
            $this->toastError(__('Could not load config: :msg', ['msg' => $e->getMessage()]));

            return;
        }

        $this->config_selected_path = $path;
        $this->config_contents = $result['contents'];
        $this->config_truncated_on_load = $result['truncated'];
        $this->config_validate_output = null;
        $this->config_validate_ok = null;
        $this->config_last_backup = null;
        $this->refreshConfigBackups();
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
        \App\Jobs\RunWebserverConfigOpJob::dispatch(
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
        \App\Jobs\RunWebserverConfigOpJob::dispatch(
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
        \App\Jobs\RunWebserverConfigOpJob::dispatch(
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

        $configFiles = [];
        if (in_array($this->workspace_tab, ['nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy'], true) && $this->serverOpsReady()) {
            try {
                $configFiles = app(RemoteWebserverConfigService::class)->listFiles($this->server, $this->workspace_tab);
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
                ? \App\Services\Servers\ServerRemovalAdvisor::summary($this->server)
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
