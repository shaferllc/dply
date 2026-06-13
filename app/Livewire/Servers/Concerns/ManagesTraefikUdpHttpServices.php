<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\TraefikHttpServicesConfig;
use App\Services\Servers\TraefikUdpRoutesConfig;
use Illuminate\Support\Facades\DB;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesTraefikUdpHttpServices
{


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
