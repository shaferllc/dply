<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\NginxCustomHostsConfig;
use App\Services\Servers\NginxEngineCacheConfig;
use App\Services\Servers\NginxEngineCachePurger;
use App\Services\Servers\NginxGlobalOptionsConfig;
use App\Services\Servers\NginxModulesConfig;
use App\Services\Servers\NginxUpstreamsConfig;
use Illuminate\Support\Facades\DB;

/**
 * Nginx webserver-engine configuration for {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component. Every public property/method name
 * is unchanged, so Livewire snapshots and wire:* bindings keep resolving against
 * the composed class.
 */
trait ManagesNginxWebserver
{
    // ---- nginx Global Options form (Workers sub-tab on the nginx engine).
    /** @var array<string, string> */
    public array $nginx_globals_form = [];

    public bool $nginx_globals_loaded = false;

    public ?string $nginx_globals_flash = null;

    public ?string $nginx_globals_error = null;

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
}
