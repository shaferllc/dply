<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
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
use Illuminate\Support\Facades\DB;

/**
 * Traefik edge-proxy configuration for {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component to keep it maintainable. Every
 * public property/method name is unchanged, so Livewire snapshots and wire:*
 * bindings in the Blade view continue to resolve against the composed class.
 */
trait ManagesTraefikWebserver
{
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
}
