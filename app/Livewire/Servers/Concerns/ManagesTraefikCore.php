<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\TraefikDashboardExposure;
use App\Services\Servers\TraefikDynamicConfigInventory;
use App\Services\Servers\TraefikProvidersConfig;
use App\Services\Servers\TraefikStaticConfigOptions;
use Illuminate\Support\Facades\DB;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesTraefikCore
{


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
}
