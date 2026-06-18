<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\CaddyCustomRoutesConfig;
use Illuminate\Support\Facades\DB;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCaddyCustomRoutes
{


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
