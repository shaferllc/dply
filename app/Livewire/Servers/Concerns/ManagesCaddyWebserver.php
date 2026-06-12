<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Enums\SiteType;
use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Models\ServerManageAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\CaddyCustomRoutesConfig;
use App\Services\Servers\CaddyGlobalOptionsConfig;
use App\Services\Servers\CaddyModulesManager;
use App\Services\Servers\OpenLiteSpeedVhostsConfig;
use App\Services\Servers\ServerPhpManager;
use App\Services\Sites\SiteCaddyProvisioner;
use App\Support\Servers\CaddyPhpFpmUpstreamAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Caddy webserver-engine configuration for {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component. Every public property/method name
 * is unchanged, so Livewire snapshots and wire:* bindings keep resolving against
 * the composed class.
 */
trait ManagesCaddyWebserver
{
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
}
