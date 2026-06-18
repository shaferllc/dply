<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\CaddyGlobalOptionsConfig;
use Illuminate\Support\Facades\DB;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCaddyGlobalsSnippets
{


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
}
