<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\TraefikCustomMiddlewaresConfig;
use App\Services\Servers\TraefikCustomRoutesConfig;
use Illuminate\Support\Facades\DB;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesTraefikRoutesMiddlewares
{


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
}
