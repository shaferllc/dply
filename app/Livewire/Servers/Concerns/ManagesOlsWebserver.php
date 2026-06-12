<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\OpenLiteSpeedCacheModuleConfig;
use App\Services\Servers\OpenLiteSpeedExtAppsConfig;
use App\Services\Servers\OpenLiteSpeedListenersConfig;
use App\Services\Servers\OpenLiteSpeedLscachePurger;
use App\Services\Servers\OpenLiteSpeedModulesConfig;
use App\Services\Servers\OpenLiteSpeedVhostsConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Ols webserver-engine configuration for {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component. Every public property/method name
 * is unchanged, so Livewire snapshots and wire:* bindings keep resolving against
 * the composed class.
 */
trait ManagesOlsWebserver
{
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
}
