<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ApacheCustomVhostsConfig;
use App\Services\Servers\ApacheEngineCacheConfig;
use App\Services\Servers\ApacheEngineCachePurger;
use App\Services\Servers\ApacheGlobalOptionsConfig;
use App\Services\Servers\ApacheModulesConfig;
use Illuminate\Support\Facades\DB;

/**
 * Apache webserver-engine configuration for {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component. Every public property/method name
 * is unchanged, so Livewire snapshots and wire:* bindings keep resolving against
 * the composed class.
 */
trait ManagesApacheWebserver
{
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

    /** @var array<string, mixed> */
    public array $apache_cache_status = [];

    public bool $apache_cache_loaded = false;

    public bool $apache_mod_cache_enabled = false;

    public ?string $apache_cache_flash = null;

    public ?string $apache_cache_error = null;

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
}
