<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\HaproxyBackendsConfig;
use App\Services\Servers\HaproxyFrontendsConfig;
use App\Services\Servers\HaproxyGlobalOptionsConfig;
use Illuminate\Support\Facades\DB;

/**
 * Haproxy webserver-engine configuration for {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component. Every public property/method name
 * is unchanged, so Livewire snapshots and wire:* bindings keep resolving against
 * the composed class.
 */
trait ManagesHaproxyWebserver
{
    // ---- HAProxy Global Options form (Runtime sub-tab on the HAProxy edge proxy).
    /** @var array<string, string> */
    public array $haproxy_globals_form = [];

    public bool $haproxy_globals_loaded = false;

    public ?string $haproxy_globals_flash = null;

    public ?string $haproxy_globals_error = null;

    // ---- HAProxy Frontends editor (Frontends sub-tab on the HAProxy edge proxy).
    /**
     * Per-frontend: ['binds' => list<string>, 'values' => array<string,string>]
     *
     * @var array<string, array{binds: list<string>, values: array<string, string>}>
     */
    public array $haproxy_frontends_form = [];

    /** Textarea-friendly mirror of `binds` per frontend (newline-separated). */
    /** @var array<string, string> */
    public array $haproxy_frontends_binds_text = [];

    public bool $haproxy_frontends_loaded = false;

    public ?string $haproxy_frontends_flash = null;

    public ?string $haproxy_frontends_error = null;

    public bool $haproxy_frontends_show_add = false;

    /** @var array<string, string> */
    public array $haproxy_frontends_new = ['name' => '', 'binds' => '', 'default_backend' => ''];

    // ---- HAProxy Backends editor (Backends sub-tab).
    /**
     * Per-backend: ['servers' => list<string>, 'values' => array<string,string>]
     *
     * @var array<string, array{servers: list<string>, values: array<string, string>}>
     */
    public array $haproxy_backends_form = [];

    /** @var array<string, string>  Textarea-friendly mirror of `servers` per backend. */
    public array $haproxy_backends_servers_text = [];

    public bool $haproxy_backends_loaded = false;

    public ?string $haproxy_backends_flash = null;

    public ?string $haproxy_backends_error = null;

    public bool $haproxy_backends_show_add = false;

    /** @var array<string, string> */
    public array $haproxy_backends_new = ['name' => '', 'servers' => '', 'balance' => 'roundrobin'];

    public function loadHaproxyBackendsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->haproxy_backends_error = __('Provisioning and SSH must be ready before reading haproxy.cfg.');

            return;
        }

        try {
            $result = app(HaproxyBackendsConfig::class)->read($this->server);
            $form = [];
            $serversText = [];
            foreach ($result['backends'] as $b) {
                $form[$b['name']] = ['servers' => $b['servers'], 'values' => $b['values']];
                $serversText[$b['name']] = implode("\n", $b['servers']);
            }
            $this->haproxy_backends_form = $form;
            $this->haproxy_backends_servers_text = $serversText;
            $this->haproxy_backends_loaded = true;
            $this->haproxy_backends_flash = null;
            $this->haproxy_backends_error = null;
            if (! empty($result['unreadable'])) {
                $this->haproxy_backends_error = __('Could not read /etc/haproxy/haproxy.cfg — check sudo permissions for the deploy user.');
            } elseif (empty($result['backends'])) {
                $this->haproxy_backends_flash = __('No `backend <name>` blocks found in haproxy.cfg yet.');
            }
        } catch (\Throwable $e) {
            $this->haproxy_backends_error = __('Failed to read backends: :msg', ['msg' => $e->getMessage()]);
            $this->haproxy_backends_loaded = false;
        }
    }

    public function saveHaproxyBackendsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_backends_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_backends_error = __('Provisioning and SSH must be ready before saving haproxy.cfg.');

            return;
        }

        $this->haproxy_backends_flash = null;
        $this->haproxy_backends_error = null;

        foreach ($this->haproxy_backends_servers_text as $name => $text) {
            if (! isset($this->haproxy_backends_form[$name])) {
                continue;
            }
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text) ?: []), fn (string $l) => $l !== ''));
            $this->haproxy_backends_form[$name]['servers'] = $lines;
        }

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save HAProxy backends'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(HaproxyBackendsConfig::class)
                ->save($this->server, $this->haproxy_backends_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_backends_flash = __('Backends saved and HAProxy reloaded.');
            $this->loadHaproxyBackendsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_backends_error = $e->getMessage();
        }
    }

    public function openAddHaproxyBackendForm(): void
    {
        $this->haproxy_backends_show_add = true;
        $this->haproxy_backends_new = ['name' => '', 'servers' => '', 'balance' => 'roundrobin'];
        $this->haproxy_backends_error = null;
        $this->haproxy_backends_flash = null;
    }

    public function cancelAddHaproxyBackendForm(): void
    {
        $this->haproxy_backends_show_add = false;
        $this->haproxy_backends_new = ['name' => '', 'servers' => '', 'balance' => 'roundrobin'];
    }

    public function submitAddHaproxyBackend(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_backends_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_backends_error = __('Provisioning and SSH must be ready before adding a backend.');

            return;
        }

        $this->haproxy_backends_flash = null;
        $this->haproxy_backends_error = null;

        $name = (string) ($this->haproxy_backends_new['name'] ?? '');
        $servers = array_values(array_filter(
            array_map('trim', preg_split('/\R/', (string) ($this->haproxy_backends_new['servers'] ?? '')) ?: []),
            fn (string $l) => $l !== '',
        ));
        $balance = trim((string) ($this->haproxy_backends_new['balance'] ?? 'roundrobin'));

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Add HAProxy backend: :name', ['name' => trim($name)]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            $values = ['balance' => $balance];
            app(HaproxyBackendsConfig::class)
                ->addBackend($this->server, $name, $servers, $values, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_backends_flash = __('Backend :name added and HAProxy reloaded.', ['name' => $name]);
            $this->haproxy_backends_show_add = false;
            $this->haproxy_backends_new = ['name' => '', 'servers' => '', 'balance' => 'roundrobin'];
            $this->loadHaproxyBackendsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_backends_error = $e->getMessage();
        }
    }

    public function removeHaproxyBackend(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_backends_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_backends_error = __('Provisioning and SSH must be ready before removing a backend.');

            return;
        }

        $this->haproxy_backends_flash = null;
        $this->haproxy_backends_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Remove HAProxy backend: :name', ['name' => $name]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(HaproxyBackendsConfig::class)
                ->removeBackend($this->server, $name, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_backends_flash = __('Backend :name removed and HAProxy reloaded.', ['name' => $name]);
            $this->loadHaproxyBackendsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_backends_error = $e->getMessage();
        }
    }

    public function loadHaproxyFrontendsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->haproxy_frontends_error = __('Provisioning and SSH must be ready before reading haproxy.cfg.');

            return;
        }

        try {
            $result = app(HaproxyFrontendsConfig::class)->read($this->server);
            $form = [];
            $bindsText = [];
            foreach ($result['frontends'] as $f) {
                $form[$f['name']] = ['binds' => $f['binds'], 'values' => $f['values']];
                $bindsText[$f['name']] = implode("\n", $f['binds']);
            }
            $this->haproxy_frontends_form = $form;
            $this->haproxy_frontends_binds_text = $bindsText;
            $this->haproxy_frontends_loaded = true;
            $this->haproxy_frontends_flash = null;
            $this->haproxy_frontends_error = null;
            if (! empty($result['unreadable'])) {
                $this->haproxy_frontends_error = __('Could not read /etc/haproxy/haproxy.cfg — check sudo permissions for the deploy user.');
            } elseif (empty($result['frontends'])) {
                $this->haproxy_frontends_flash = __('No `frontend <name>` blocks found in haproxy.cfg yet.');
            }
        } catch (\Throwable $e) {
            $this->haproxy_frontends_error = __('Failed to read frontends: :msg', ['msg' => $e->getMessage()]);
            $this->haproxy_frontends_loaded = false;
        }
    }

    public function saveHaproxyFrontendsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_frontends_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_frontends_error = __('Provisioning and SSH must be ready before saving haproxy.cfg.');

            return;
        }

        $this->haproxy_frontends_flash = null;
        $this->haproxy_frontends_error = null;

        // Sync binds from textarea mirror back into the form payload.
        foreach ($this->haproxy_frontends_binds_text as $name => $text) {
            if (! isset($this->haproxy_frontends_form[$name])) {
                continue;
            }
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text) ?: []), fn (string $l) => $l !== ''));
            $this->haproxy_frontends_form[$name]['binds'] = $lines;
        }

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save HAProxy frontends'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(HaproxyFrontendsConfig::class)
                ->save($this->server, $this->haproxy_frontends_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_frontends_flash = __('Frontends saved and HAProxy reloaded.');
            $this->loadHaproxyFrontendsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_frontends_error = $e->getMessage();
        }
    }

    public function openAddHaproxyFrontendForm(): void
    {
        $this->haproxy_frontends_show_add = true;
        $this->haproxy_frontends_new = ['name' => '', 'binds' => '', 'default_backend' => ''];
        $this->haproxy_frontends_error = null;
        $this->haproxy_frontends_flash = null;
    }

    public function cancelAddHaproxyFrontendForm(): void
    {
        $this->haproxy_frontends_show_add = false;
        $this->haproxy_frontends_new = ['name' => '', 'binds' => '', 'default_backend' => ''];
    }

    public function submitAddHaproxyFrontend(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_frontends_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_frontends_error = __('Provisioning and SSH must be ready before adding a frontend.');

            return;
        }

        $this->haproxy_frontends_flash = null;
        $this->haproxy_frontends_error = null;

        $name = (string) ($this->haproxy_frontends_new['name'] ?? '');
        $binds = array_values(array_filter(
            array_map('trim', preg_split('/\R/', (string) ($this->haproxy_frontends_new['binds'] ?? '')) ?: []),
            fn (string $l) => $l !== '',
        ));
        $defaultBackend = trim((string) ($this->haproxy_frontends_new['default_backend'] ?? ''));

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Add HAProxy frontend: :name', ['name' => trim($name)]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            $values = [];
            if ($defaultBackend !== '') {
                $values['default_backend'] = $defaultBackend;
            }
            app(HaproxyFrontendsConfig::class)
                ->addFrontend($this->server, $name, $binds, $values, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_frontends_flash = __('Frontend :name added and HAProxy reloaded.', ['name' => $name]);
            $this->haproxy_frontends_show_add = false;
            $this->haproxy_frontends_new = ['name' => '', 'binds' => '', 'default_backend' => ''];
            $this->loadHaproxyFrontendsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_frontends_error = $e->getMessage();
        }
    }

    public function removeHaproxyFrontend(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_frontends_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_frontends_error = __('Provisioning and SSH must be ready before removing a frontend.');

            return;
        }

        $this->haproxy_frontends_flash = null;
        $this->haproxy_frontends_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Remove HAProxy frontend: :name', ['name' => $name]),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(HaproxyFrontendsConfig::class)
                ->removeFrontend($this->server, $name, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_frontends_flash = __('Frontend :name removed and HAProxy reloaded.', ['name' => $name]);
            $this->loadHaproxyFrontendsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_frontends_error = $e->getMessage();
        }
    }

    public function loadHaproxyGlobalsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->haproxy_globals_error = __('Provisioning and SSH must be ready before reading haproxy.cfg.');

            return;
        }

        try {
            $result = app(HaproxyGlobalOptionsConfig::class)->read($this->server);
            $this->haproxy_globals_form = $result['values'];
            $this->haproxy_globals_loaded = true;
            $this->haproxy_globals_flash = null;
            $this->haproxy_globals_error = null;
            if (! empty($result['unreadable'])) {
                $this->haproxy_globals_error = __('Could not read /etc/haproxy/haproxy.cfg — check sudo permissions for the deploy user.');
            }
        } catch (\Throwable $e) {
            $this->haproxy_globals_error = __('Failed to read HAProxy globals: :msg', ['msg' => $e->getMessage()]);
            $this->haproxy_globals_loaded = false;
        }
    }

    public function saveHaproxyGlobalsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->haproxy_globals_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->haproxy_globals_error = __('Provisioning and SSH must be ready before saving haproxy.cfg.');

            return;
        }

        $this->haproxy_globals_flash = null;
        $this->haproxy_globals_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save HAProxy global options'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(HaproxyGlobalOptionsConfig::class)
                ->save($this->server, $this->haproxy_globals_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->haproxy_globals_flash = __('HAProxy global options saved and HAProxy reloaded.');
            $this->loadHaproxyGlobalsConfig();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->haproxy_globals_error = $e->getMessage();
        }
    }
}
