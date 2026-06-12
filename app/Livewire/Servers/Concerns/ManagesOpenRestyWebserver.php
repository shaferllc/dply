<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\OpenRestyCustomServersConfig;
use App\Services\Servers\OpenRestyCustomUpstreamsConfig;
use App\Services\Servers\OpenRestyStaticConfigOptions;
use Illuminate\Support\Facades\DB;

/**
 * OpenResty webserver-engine configuration for {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component. Every public property/method name
 * is unchanged, so Livewire snapshots and wire:* bindings keep resolving against
 * the composed class.
 */
trait ManagesOpenRestyWebserver
{
    /** @var array<string, string> */
    public array $openresty_static_form = [];

    public bool $openresty_static_loaded = false;

    public ?string $openresty_static_flash = null;

    public ?string $openresty_static_error = null;

    /** @var array<string, array{servers: list<string>}> */
    public array $openresty_upstreams_form = [];

    /** @var array<string, string> */
    public array $openresty_upstreams_servers_text = [];

    public bool $openresty_upstreams_loaded = false;

    public ?string $openresty_upstreams_flash = null;

    public ?string $openresty_upstreams_error = null;

    public bool $openresty_upstreams_show_add = false;

    /** @var array{name: string, servers: string} */
    public array $openresty_upstreams_new = ['name' => '', 'servers' => ''];

    /** @var array<string, array{server_names: list<string>, upstream: string}> */
    public array $openresty_servers_form = [];

    /** @var array<string, string> */
    public array $openresty_servers_names_text = [];

    public bool $openresty_servers_loaded = false;

    public ?string $openresty_servers_flash = null;

    public ?string $openresty_servers_error = null;

    public bool $openresty_servers_show_add = false;

    /** @var array{name: string, server_names: string, upstream: string} */
    public array $openresty_servers_new = ['name' => '', 'server_names' => '', 'upstream' => ''];

    public function loadOpenRestyStaticConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            $this->openresty_static_error = __('Provisioning and SSH must be ready before reading OpenResty settings.');

            return;
        }
        try {
            $result = app(OpenRestyStaticConfigOptions::class)->read($this->server);
            $this->openresty_static_form = $result['values'];
            $this->openresty_static_loaded = true;
            $this->openresty_static_flash = null;
            $this->openresty_static_error = null;
        } catch (\Throwable $e) {
            $this->openresty_static_error = __('Failed to read OpenResty settings: :msg', ['msg' => $e->getMessage()]);
            $this->openresty_static_loaded = false;
        }
    }

    public function saveOpenRestyStaticConfig(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save OpenResty static settings'));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyStaticConfigOptions::class)->save($this->server, $this->openresty_static_form, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_static_flash = __('OpenResty static settings saved and edge routing regenerated.');
            $this->loadOpenRestyStaticConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_static_error = $e->getMessage();
        }
    }

    public function loadOpenRestyUpstreamsConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            $this->openresty_upstreams_error = __('Provisioning and SSH must be ready.');

            return;
        }
        try {
            $rows = app(OpenRestyCustomUpstreamsConfig::class)->read($this->server);
            $form = [];
            $text = [];
            foreach ($rows as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $form[$name] = ['servers' => $row['servers'] ?? []];
                $text[$name] = implode("\n", $row['servers'] ?? []);
            }
            $this->openresty_upstreams_form = $form;
            $this->openresty_upstreams_servers_text = $text;
            $this->openresty_upstreams_loaded = true;
            $this->openresty_upstreams_flash = null;
            $this->openresty_upstreams_error = null;
        } catch (\Throwable $e) {
            $this->openresty_upstreams_error = $e->getMessage();
            $this->openresty_upstreams_loaded = false;
        }
    }

    public function saveOpenRestyUpstreamsConfig(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $upstreams = [];
        foreach ($this->openresty_upstreams_servers_text as $name => $raw) {
            if (! isset($this->openresty_upstreams_form[$name])) {
                continue;
            }
            $servers = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $raw) ?: []), fn (string $s): bool => $s !== ''));
            $upstreams[] = ['name' => $name, 'servers' => $servers];
        }
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save OpenResty custom upstreams'));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyCustomUpstreamsConfig::class)->save($this->server, $upstreams, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_upstreams_flash = __('Custom upstreams saved and edge routing regenerated.');
            $this->loadOpenRestyUpstreamsConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_upstreams_error = $e->getMessage();
        }
    }

    public function openAddOpenRestyUpstreamForm(): void
    {
        $this->openresty_upstreams_show_add = true;
        $this->openresty_upstreams_new = ['name' => '', 'servers' => ''];
        $this->openresty_upstreams_error = null;
        $this->openresty_upstreams_flash = null;
    }

    public function cancelAddOpenRestyUpstreamForm(): void
    {
        $this->openresty_upstreams_show_add = false;
    }

    public function submitAddOpenRestyUpstream(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $name = trim((string) ($this->openresty_upstreams_new['name'] ?? ''));
        $servers = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($this->openresty_upstreams_new['servers'] ?? '')) ?: []), fn (string $s): bool => $s !== ''));
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add OpenResty upstream: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyCustomUpstreamsConfig::class)->add($this->server, $name, $servers, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_upstreams_flash = __('Upstream added.');
            $this->openresty_upstreams_show_add = false;
            $this->loadOpenRestyUpstreamsConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_upstreams_error = $e->getMessage();
        }
    }

    public function removeOpenRestyUpstream(string $name): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove OpenResty upstream: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyCustomUpstreamsConfig::class)->remove($this->server, $name, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_upstreams_flash = __('Upstream removed.');
            $this->loadOpenRestyUpstreamsConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_upstreams_error = $e->getMessage();
        }
    }

    public function loadOpenRestyServersConfig(): void
    {
        $this->authorize('view', $this->server);
        if (! $this->serverOpsReady()) {
            $this->openresty_servers_error = __('Provisioning and SSH must be ready.');

            return;
        }
        try {
            $rows = app(OpenRestyCustomServersConfig::class)->read($this->server);
            $form = [];
            $text = [];
            foreach ($rows as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $form[$name] = ['server_names' => $row['server_names'] ?? [], 'upstream' => (string) ($row['upstream'] ?? '')];
                $text[$name] = implode("\n", $row['server_names'] ?? []);
            }
            $this->openresty_servers_form = $form;
            $this->openresty_servers_names_text = $text;
            $this->openresty_servers_loaded = true;
            $this->openresty_servers_flash = null;
            $this->openresty_servers_error = null;
        } catch (\Throwable $e) {
            $this->openresty_servers_error = $e->getMessage();
            $this->openresty_servers_loaded = false;
        }
    }

    public function saveOpenRestyServersConfig(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $servers = [];
        foreach ($this->openresty_servers_names_text as $name => $raw) {
            if (! isset($this->openresty_servers_form[$name])) {
                continue;
            }
            $serverNames = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $raw) ?: []), fn (string $d): bool => $d !== ''));
            $servers[] = [
                'name' => $name,
                'server_names' => $serverNames,
                'upstream' => trim((string) ($this->openresty_servers_form[$name]['upstream'] ?? '')),
            ];
        }
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save OpenResty custom servers'));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyCustomServersConfig::class)->save($this->server, $servers, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_servers_flash = __('Custom server blocks saved and edge routing regenerated.');
            $this->loadOpenRestyServersConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_servers_error = $e->getMessage();
        }
    }

    public function openAddOpenRestyServerForm(): void
    {
        $this->openresty_servers_show_add = true;
        $this->openresty_servers_new = ['name' => '', 'server_names' => '', 'upstream' => ''];
        $this->openresty_servers_error = null;
        $this->openresty_servers_flash = null;
    }

    public function cancelAddOpenRestyServerForm(): void
    {
        $this->openresty_servers_show_add = false;
    }

    public function submitAddOpenRestyServer(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $name = trim((string) ($this->openresty_servers_new['name'] ?? ''));
        $serverNames = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($this->openresty_servers_new['server_names'] ?? '')) ?: []), fn (string $d): bool => $d !== ''));
        $upstream = trim((string) ($this->openresty_servers_new['upstream'] ?? ''));
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add OpenResty server: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyCustomServersConfig::class)->add($this->server, $name, $serverNames, $upstream, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_servers_flash = __('Server block added.');
            $this->openresty_servers_show_add = false;
            $this->loadOpenRestyServersConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_servers_error = $e->getMessage();
        }
    }

    public function removeOpenRestyServer(string $name): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }
        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove OpenResty server: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_RUNNING, 'started_at' => now(), 'updated_at' => now()]);
        try {
            app(OpenRestyCustomServersConfig::class)->remove($this->server, $name, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_COMPLETED, 'finished_at' => now(), 'updated_at' => now()]);
            $this->openresty_servers_flash = __('Server block removed.');
            $this->loadOpenRestyServersConfig();
            $this->refreshOpenRestyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update(['status' => ConsoleAction::STATUS_FAILED, 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
            $this->openresty_servers_error = $e->getMessage();
        }
    }

    private function refreshOpenRestyLiveStateAfterServiceAction(): void
    {
        if ($this->server->edgeProxy() !== 'openresty') {
            return;
        }
        $this->ensureEngineLiveState(forceFresh: true);
    }
}
