<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\RunServerConfigOpJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesServerConfigRevisions;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\Servers\RemoteServerConfigService;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerConfigFileCatalog;
use App\Services\Servers\ServerConfigFileEditor;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Unified server configuration editor — allowlisted paths across webserver,
 * PHP, Redis/DB, system, and supervisor with CodeMirror editing.
 */
#[Layout('layouts.app')]
class WorkspaceConfiguration extends Component
{
    use ConfirmsActionWithModal;
    use DismissesServerConsoleActionRun;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesServerConfigRevisions;

    public ?string $config_selected_path = null;

    public string $config_contents = '';

    public ?string $config_validate_output = null;

    public ?bool $config_validate_ok = null;

    public bool $config_truncated_on_load = false;

    public ?string $config_last_backup = null;

    /** @var array<int, array{path: string, mtime: int, size: int}> */
    public array $config_backups = [];

    /** @var array<string, string> */
    public array $config_drafts = [];

    #[Url(as: 'scope', except: '')]
    public string $config_scope = '';

    #[Url(as: 'q', except: '')]
    public string $config_search = '';

    public ?string $pending_load_console_id = null;

    public ?string $pending_load_path = null;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function updatedConfigSearch(): void
    {
        // URL binding refreshes the grouped picker.
    }

    public function setConfigScope(string $scope): void
    {
        $this->config_scope = $scope;
    }

    public function clearConfigScope(): void
    {
        $this->config_scope = '';
    }

    public function loadConfigFile(string $path): void
    {
        if (! $this->guardConfigAction(allowReadOnly: true)) {
            return;
        }

        try {
            $this->assertCatalogPath($path);
        } catch (\InvalidArgumentException) {
            $this->toastError(__('Path not allowed.'));

            return;
        }

        if ($this->config_selected_path !== null && $this->config_selected_path !== $path) {
            $this->stashConfigDraft((string) $this->config_selected_path, $this->config_contents);
        }

        if (isset($this->config_drafts[$path])) {
            $this->config_selected_path = $path;
            $this->config_contents = $this->config_drafts[$path];
            $this->config_original_contents = $this->config_contents;
            $this->config_truncated_on_load = false;
            $this->config_validate_output = null;
            $this->config_validate_ok = null;
            $this->closeConfigSaveDiff();
            $this->closeConfigRevisionDiff();
            $this->refreshRemoteConfigBackups();
            $this->refreshConfigRevisionState();

            return;
        }

        $consoleId = $this->seedConfigurationConsoleAction(
            (string) __('Load config: :path', ['path' => basename($path)]),
        );
        $this->pending_load_console_id = $consoleId;
        $this->pending_load_path = $path;
        $this->config_selected_path = null;
        $this->config_contents = '';
        $this->config_truncated_on_load = false;
        $this->config_validate_output = null;
        $this->config_validate_ok = null;
        $this->config_last_backup = null;
        $this->config_backups = [];
        $this->config_original_contents = '';
        $this->closeConfigSaveDiff();
        $this->closeConfigRevisionDiff();

        RunServerConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'read',
            $path,
            engine: $this->resolvedConfigEngineForPath($path),
        );

        $this->toastSuccess(__('Load queued — progress shows in the banner above.'));
    }

    public function saveConfigFile(): void
    {
        $this->openConfigSaveConfirm();
    }

    public function confirmConfigSave(): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }
        if ($this->config_truncated_on_load) {
            $this->toastError(__('Refusing to save: this file is too large for the editor and was loaded truncated.'));

            return;
        }
        if ($this->config_contents === $this->config_original_contents) {
            $this->toastError(__('No changes to save.'));

            return;
        }

        $path = (string) $this->config_selected_path;
        $engine = $this->resolvedConfigEngineForPath($path);

        if ($this->configRevisionsEnabled()) {
            app(ServerConfigFileEditor::class)->ensureBaseline(
                $this->server,
                $path,
                $this->config_original_contents,
                auth()->user(),
                $engine,
            );
        }

        $consoleId = $this->seedConfigurationConsoleAction(
            (string) __('Save config: :path', ['path' => basename($path)]),
        );
        $this->pending_write_console_id = $consoleId;

        RunServerConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'write',
            $path,
            $this->config_contents,
            '',
            auth()->id(),
            true,
            null,
            $engine,
        );

        $this->closeConfigSaveDiff();
        $this->toastSuccess(__('Save queued — progress shows in the banner above.'));
    }

    public function validateConfigBuffer(): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }
        if ($this->config_truncated_on_load) {
            $this->toastError(__('Buffer is truncated — validation would chop the file.'));

            return;
        }

        $path = (string) $this->config_selected_path;
        $consoleId = $this->seedConfigurationConsoleAction(
            (string) __('Validate config buffer: :path', ['path' => basename($path)]),
        );
        $this->pending_validate_console_id = $consoleId;

        RunServerConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'validate',
            $path,
            $this->config_contents,
            engine: $this->resolvedConfigEngineForPath($path),
        );

        $this->toastSuccess(__('Validation queued — progress shows in the banner above.'));
    }

    public function restoreConfigBackup(string $backupPath): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }

        $path = (string) $this->config_selected_path;
        $consoleId = $this->seedConfigurationConsoleAction(
            (string) __('Restore config backup: :path', ['path' => basename($path)]),
        );

        RunServerConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'restore',
            $path,
            backupPath: $backupPath,
            engine: $this->resolvedConfigEngineForPath($path),
        );

        $this->toastSuccess(__('Restore queued — progress shows in the banner above.'));
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->pickupQueuedConfigLoad();
        $this->pickupQueuedConfigWrite();
        $this->pickupQueuedConfigValidate();

        $catalog = app(ServerConfigFileCatalog::class);
        $scope = $this->config_scope !== '' ? $this->config_scope : null;
        $search = $this->config_search !== '' ? $this->config_search : null;

        $groupedFiles = [];
        if ($this->serverOpsReady()) {
            $cacheKey = 'dply.server-config-catalog:'.$this->server->id.':'.md5(($scope ?? '').'|'.($search ?? ''));
            try {
                $groupedFiles = Cache::remember(
                    $cacheKey,
                    10,
                    fn () => $catalog->groupedFiles($this->server, $scope, $search),
                );
            } catch (\Throwable) {
                $groupedFiles = [];
            }
        }

        $autocomplete = [];
        if ($this->config_selected_path !== null) {
            $autocomplete = $catalog->autocompleteForPath((string) $this->config_selected_path);
        }

        $configConsoleRun = ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();

        return view('livewire.servers.workspace-configuration', array_merge(
            $this->configRevisionViewData(),
            [
                'server' => $this->server,
                'groupedConfigFiles' => $groupedFiles,
                'configAutocomplete' => $autocomplete,
                'configFileType' => $this->config_selected_path !== null
                    ? $catalog->fileTypeForPath((string) $this->config_selected_path)
                    : 'conf',
                'opsReady' => $this->serverOpsReady(),
                'isDeployer' => $this->currentUserIsDeployer(),
                'configConsoleRun' => $configConsoleRun,
                'deletionSummary' => $this->showRemoveServerModal
                    ? ServerRemovalAdvisor::summary($this->server)
                    : null,
            ],
        ));
    }

    protected function stashConfigDraft(string $path, string $contents): void
    {
        $this->config_drafts[$path] = $contents;
    }

    protected function pickupQueuedConfigLoad(): void
    {
        if ($this->pending_load_console_id === null) {
            return;
        }

        $row = ConsoleAction::query()->find($this->pending_load_console_id);
        if ($row === null) {
            $this->pending_load_console_id = null;
            $this->pending_load_path = null;

            return;
        }

        if (! in_array($row->status, [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED], true)) {
            return;
        }

        if ($row->status === ConsoleAction::STATUS_COMPLETED && $this->pending_load_path !== null) {
            $cached = Cache::pull(
                RunServerConfigOpJob::readResultCacheKey($this->pending_load_console_id),
            );
            if (is_array($cached)) {
                $path = $this->pending_load_path;
                $this->config_selected_path = $path;
                $this->config_contents = (string) ($cached['contents'] ?? '');
                $this->config_original_contents = $this->config_contents;
                $this->config_truncated_on_load = (bool) ($cached['truncated'] ?? false);
                $this->stashConfigDraft($path, $this->config_contents);
                Cache::forget('dply.server-config-catalog:'.$this->server->id.':'.md5(($this->config_scope !== '' ? $this->config_scope : '').'|'.($this->config_search !== '' ? $this->config_search : '')));
                $this->refreshRemoteConfigBackups();
                $this->refreshConfigRevisionState();
            }
        } elseif ($row->status === ConsoleAction::STATUS_FAILED) {
            $this->toastError(__('Failed to load config file.'));
        }

        $this->pending_load_console_id = null;
        $this->pending_load_path = null;
    }

    protected function refreshRemoteConfigBackups(): void
    {
        if ($this->config_selected_path === null) {
            $this->config_backups = [];

            return;
        }

        $path = (string) $this->config_selected_path;
        $engine = $this->resolvedConfigEngineForPath($path);

        try {
            $this->config_backups = $engine !== null
                ? app(RemoteWebserverConfigService::class)->listBackups($this->server, $engine, $path)
                : app(RemoteServerConfigService::class)->listBackups($this->server, $path);
        } catch (\Throwable) {
            $this->config_backups = [];
        }
    }

    protected function guardConfigAction(bool $allowReadOnly = false): bool
    {
        if (! $allowReadOnly && $this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot edit server config.'));

            return false;
        }
        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before this action.'));

            return false;
        }

        return true;
    }

    protected function assertCatalogPath(string $path): void
    {
        $normalized = str_starts_with($path, '/') ? $path : '/'.$path;
        if (str_contains($normalized, '..')) {
            throw new \InvalidArgumentException;
        }

        foreach (config('server_manage.allowed_config_paths_exact', []) as $exact) {
            if ($normalized === $exact) {
                return;
            }
        }

        foreach (config('server_manage.allowed_config_path_prefixes', []) as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return;
            }
        }

        throw new \InvalidArgumentException;
    }

    protected function resolvedConfigEngineForPath(string $path): ?string
    {
        return app(ServerConfigFileCatalog::class)->webserverEngineForPath($path);
    }

    protected function seedConfigurationConsoleAction(string $label): string
    {
        ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->whereIn('status', [
                ConsoleAction::STATUS_COMPLETED,
                ConsoleAction::STATUS_FAILED,
            ])
            ->update(['dismissed_at' => now()]);

        $row = ConsoleAction::query()->create([
            'subject_type' => $this->server->getMorphClass(),
            'subject_id' => $this->server->id,
            'kind' => 'manage_action',
            'status' => ConsoleAction::STATUS_QUEUED,
            'user_id' => auth()->id(),
            'label' => $label.' …',
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);

        return (string) $row->id;
    }
}
