<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Services\Servers\ServerPhpConfigEditor;
use App\Services\Servers\ServerPhpConfigValidationException;
use App\Services\Servers\ServerPhpManager;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspacePhp extends Component
{
    use InteractsWithServerWorkspace;

    public ?string $remote_output = null;

    public ?string $remote_error = null;

    public bool $phpConfigEditorOpen = false;

    public ?string $phpConfigEditorVersion = null;

    public ?string $phpConfigEditorTarget = null;

    public ?string $phpConfigEditorTargetLabel = null;

    public ?string $phpConfigEditorPath = null;

    public string $phpConfigEditorContent = '';

    public ?string $phpConfigEditorReloadGuidance = null;

    public ?string $phpConfigEditorValidationOutput = null;

    public function runPhpPackageAction(string $action, string $version): void
    {
        $this->authorize('update', $this->server);

        $this->flash_success = null;
        $this->flash_error = null;
        $this->remote_error = null;
        $this->remote_output = __('Running PHP :action for version :version on the server…', [
            'action' => str_replace('_', ' ', $action),
            'version' => $version,
        ]);

        if (! $this->serverOpsReady()) {
            $this->flash_error = __('Provisioning and SSH must be ready before managing PHP packages.');
            $this->remote_error = $this->flash_error;
            $this->remote_output = null;

            return;
        }

        try {
            $result = app(ServerPhpManager::class)->applyPackageAction($this->server, $action, $version);
            $this->server->refresh();

            if (($result['status'] ?? null) === 'stale') {
                $this->flash_error = $result['message'] ?? __('PHP inventory may be stale.');
                $this->remote_error = $this->flash_error;
                $this->remote_output = $result['output'] ?? $this->remote_output;

                return;
            }

            $this->flash_success = $result['message'] ?? __('PHP action completed.');
            $this->remote_output = $result['output'] ?? $this->remote_output;
        } catch (\Throwable $e) {
            $this->server->refresh();
            $this->flash_error = $e->getMessage();
            $this->remote_error = $e->getMessage();
        }
    }

    public function refreshPhpInventory(): void
    {
        $this->authorize('update', $this->server);

        $this->flash_success = null;
        $this->flash_error = null;
        $this->remote_error = null;
        $this->remote_output = __('Refreshing PHP inventory on the server…');

        if (! $this->serverOpsReady()) {
            $this->flash_error = __('Provisioning and SSH must be ready before refreshing PHP inventory.');
            $this->remote_error = $this->flash_error;
            $this->remote_output = null;

            return;
        }

        try {
            $result = app(ServerPhpManager::class)->refreshInventory($this->server);
            $this->server->refresh();

            if (($result['status'] ?? null) === 'stale') {
                $this->flash_error = $result['message'] ?? __('PHP inventory may be stale.');
                $this->remote_error = $this->flash_error;
                $this->remote_output = $result['output'] ?? $this->remote_output;

                return;
            }

            $this->flash_success = $result['message'] ?? __('PHP inventory refreshed.');
            $this->remote_output = $result['output'] ?? $this->remote_output;
        } catch (\Throwable $e) {
            $this->server->refresh();
            $this->flash_error = $e->getMessage();
            $this->remote_error = $e->getMessage();
        }
    }

    public function openPhpConfigEditor(string $version, string $target): void
    {
        $this->authorize('update', $this->server);

        $this->flash_success = null;
        $this->flash_error = null;
        $this->phpConfigEditorValidationOutput = null;
        $this->remote_error = null;
        $this->remote_output = __('Loading PHP config from the server…');

        if (! $this->serverOpsReady()) {
            $this->flash_error = __('Provisioning and SSH must be ready before editing PHP config.');
            $this->remote_error = $this->flash_error;
            $this->remote_output = null;

            return;
        }

        try {
            $result = app(ServerPhpConfigEditor::class)->openTarget($this->server, $version, $target);

            $this->phpConfigEditorOpen = true;
            $this->phpConfigEditorVersion = $result['version'];
            $this->phpConfigEditorTarget = $result['target'];
            $this->phpConfigEditorTargetLabel = $result['label'];
            $this->phpConfigEditorPath = $result['path'];
            $this->phpConfigEditorContent = $result['content'];
            $this->phpConfigEditorReloadGuidance = $result['reload_guidance'] ?? null;
            $this->remote_output = __('Loaded :label from :path', [
                'label' => $result['label'],
                'path' => $result['path'],
            ]);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
            $this->remote_error = $e->getMessage();
        }
    }

    public function closePhpConfigEditor(): void
    {
        $this->phpConfigEditorOpen = false;
        $this->phpConfigEditorVersion = null;
        $this->phpConfigEditorTarget = null;
        $this->phpConfigEditorTargetLabel = null;
        $this->phpConfigEditorPath = null;
        $this->phpConfigEditorContent = '';
        $this->phpConfigEditorReloadGuidance = null;
        $this->phpConfigEditorValidationOutput = null;
    }

    public function savePhpConfigEditor(): void
    {
        $this->authorize('update', $this->server);

        $this->flash_success = null;
        $this->flash_error = null;
        $this->phpConfigEditorValidationOutput = null;
        $this->remote_error = null;
        $this->remote_output = __('Saving PHP config on the server…');

        if (! $this->serverOpsReady()) {
            $this->flash_error = __('Provisioning and SSH must be ready before editing PHP config.');
            $this->remote_error = $this->flash_error;
            $this->remote_output = null;

            return;
        }

        if ($this->phpConfigEditorVersion === null || $this->phpConfigEditorTarget === null) {
            $this->flash_error = __('Choose a PHP config target before saving.');
            $this->remote_error = $this->flash_error;
            $this->remote_output = null;

            return;
        }

        try {
            $result = app(ServerPhpConfigEditor::class)->saveTarget(
                $this->server,
                $this->phpConfigEditorVersion,
                $this->phpConfigEditorTarget,
                $this->phpConfigEditorContent
            );

            $this->flash_success = $result['message'] ?? __('PHP config saved.');
            $this->phpConfigEditorReloadGuidance = $result['reload_guidance'] ?? null;
            $this->phpConfigEditorValidationOutput = $result['verification_output'] ?? null;
            $this->remote_output = $result['output'] ?? $this->phpConfigEditorValidationOutput ?? $this->remote_output;
        } catch (ServerPhpConfigValidationException $e) {
            $this->flash_error = $e->getMessage();
            $this->phpConfigEditorValidationOutput = $e->validationOutput();
            $this->remote_error = $e->getMessage();
            $this->remote_output = $e->validationOutput();
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
            $this->remote_error = $e->getMessage();
        }
    }

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function render(): View
    {
        $this->server->refresh();

        $phpData = app(ServerPhpManager::class)->workspaceData($this->server);
        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $refreshMeta = is_array($meta['php_inventory_refresh'] ?? null) ? $meta['php_inventory_refresh'] : [];
        $inventoryMeta = is_array($meta['php_inventory'] ?? null) ? $meta['php_inventory'] : [];
        $sshUnavailable = $this->server->isReady() && blank($this->server->ssh_private_key);
        $opsReady = $this->serverOpsReady();

        return view('livewire.servers.workspace-php', [
            'opsReady' => $opsReady,
            'phpSummary' => $phpData['summary'],
            'phpVersionRows' => $phpData['version_rows'],
            'sshUnavailable' => $sshUnavailable,
            'phpInventoryRefreshRunning' => ($refreshMeta['status'] ?? null) === 'running',
            'phpInventoryRefreshFailed' => ($refreshMeta['status'] ?? null) === 'failed',
            'phpInventoryStale' => ($refreshMeta['status'] ?? null) === 'stale',
            'phpInventoryRefreshError' => is_string($refreshMeta['error'] ?? null) ? $refreshMeta['error'] : null,
            'phpEnvironmentUnsupported' => array_key_exists('supported', $inventoryMeta) && ($inventoryMeta['supported'] === false),
            'phpInventoryNeverRun' => $opsReady
                && $refreshMeta === []
                && $inventoryMeta === []
                && ((int) ($phpData['summary']['installed_count'] ?? 0) === 0),
        ]);
    }
}
