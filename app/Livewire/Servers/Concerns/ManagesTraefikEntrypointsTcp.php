<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\TraefikEntrypointsConfig;
use App\Services\Servers\TraefikTcpRoutesConfig;
use Illuminate\Support\Facades\DB;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesTraefikEntrypointsTcp
{


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
}
