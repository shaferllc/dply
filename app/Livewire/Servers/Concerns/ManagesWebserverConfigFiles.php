<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RunWebserverConfigOpJob;
use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerWebserverConfigEditor;
use Illuminate\Support\Facades\Cache;

/**
 * Concern extracted from {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component. Every public property/method name
 * is unchanged, so Livewire snapshots and wire:* bindings keep resolving against
 * the composed class.
 */
trait ManagesWebserverConfigFiles
{
    /** The path the editor currently has loaded, scoped to the active engine. */
    public ?string $config_selected_path = null;

    /** Mutable buffer bound to the textarea; persists across re-renders. */
    public string $config_contents = '';

    /** Output of the last validate run (whether triggered by a save or the explicit button). */
    public ?string $config_validate_output = null;

    /** True if the last validate run looked OK (engine-specific heuristic). */
    public ?bool $config_validate_ok = null;

    /** Set when the last save raised the "file > preview cap" notice. */
    public bool $config_truncated_on_load = false;

    /** Path of the most-recent backup the editor created; surfaced as a quick-revert affordance. */
    public ?string $config_last_backup = null;

    /** Cached backup listing for the loaded file (path => row). */
    public array $config_backups = [];

    /**
     * Config-file picker listing for the active engine. Loaded via wire:init
     * when the Config sub-tab opens — NOT inside render(), so the pickup poll
     * (and any other wire:poll) doesn't trigger an inline SSH listing on every
     * tick (which blocked the request and produced "status: null" poll errors).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $webserverConfigFilesRaw = [];

    /** False until the first listing load finishes, so the picker shows a "discovering" state rather than a misleading "no files". */
    public bool $webserverConfigFilesLoaded = false;

    /**
     * Discover the config-file picker listing for the active engine over SSH.
     * Called from wire:init (loadActiveEngineSubtabData) when the Config sub-tab
     * opens — deliberately NOT from render(), so renders + wire:polls stay free
     * of SSH. Cached briefly so rapid re-entries coalesce.
     */
    public function loadWebserverConfigFiles(): void
    {
        if (! $this->engineSupportsConfig($this->workspace_tab) || ! $this->serverOpsReady()) {
            $this->webserverConfigFilesRaw = [];
            $this->webserverConfigFilesLoaded = true;

            return;
        }

        $cacheKey = 'dply.webserver-config-files:'.$this->server->id.':'.$this->workspace_tab;
        try {
            $this->webserverConfigFilesRaw = Cache::remember(
                $cacheKey,
                10,
                fn () => app(RemoteWebserverConfigService::class)->listFiles($this->server, $this->workspace_tab),
            );
        } catch (\Throwable) {
            $this->webserverConfigFilesRaw = [];
        }

        $this->webserverConfigFilesLoaded = true;
    }

    /**
     * Load a config file's contents into the editor. Path safety is delegated
     * to the service — this method only routes the result and surfaces errors
     * via the toast/banner channel.
     */
    public function loadWebserverConfig(string $path): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if (! $this->engineSupportsConfig($this->workspace_tab)) {
            $this->toastError(__('No config editor for this engine.'));

            return;
        }

        // Straight, synchronous SSH pull — no queue, no console banner, no poll.
        // RemoteWebserverConfigService::read() now reads over a single phpseclib
        // connection (capped well under the request limit), so the file lands in
        // the editor on this very request. This deliberately bypasses the queued
        // worker flow for file editing (the queue + poll round-trip was the
        // source of the "doesn't load" / status:null behaviour).
        try {
            $result = app(RemoteWebserverConfigService::class)->read($this->server, $this->workspace_tab, $path);
        } catch (\Throwable $e) {
            $this->toastError(__('Could not read :path: :msg', ['path' => basename($path), 'msg' => $e->getMessage()]));

            return;
        }

        $this->config_selected_path = $path;
        $this->config_contents = (string) ($result['contents'] ?? '');
        $this->config_original_contents = $this->config_contents;
        $this->config_truncated_on_load = (bool) ($result['truncated'] ?? false);
        $this->config_validate_output = null;
        $this->config_validate_ok = null;
        $this->config_last_backup = null;
        $this->webserverConfigSaveDiffOpen = false;
        $this->closeWebserverConfigRevisionDiff();
        $this->refreshConfigBackups();
        $this->refreshWebserverConfigRevisionState();
    }

    /**
     * Watch for a pending queued file-load and, once the worker has
     * stashed the read result in cache, drop it into the editor buffer.
     * Called from render() each tick (the banner's wire:poll drives this).
     */
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
            return; // still queued / running
        }

        if ($row->status === ConsoleAction::STATUS_COMPLETED) {
            $cached = Cache::pull(
                RunWebserverConfigOpJob::readResultCacheKey($this->pending_load_console_id),
            );
            if (is_array($cached)) {
                $this->config_selected_path = $this->pending_load_path;
                $this->config_contents = (string) ($cached['contents'] ?? '');
                $this->config_original_contents = $this->config_contents;
                $this->config_truncated_on_load = (bool) ($cached['truncated'] ?? false);
                // Bust the cached picker listing so the next render reflects
                // the freshly-read file size + mtime accurately.
                Cache::forget('dply.webserver-config-files:'.$this->server->id.':'.$this->workspace_tab);
                $this->refreshConfigBackups();
                $this->refreshWebserverConfigRevisionState();
            }
        }
        $this->pending_load_console_id = null;
        $this->pending_load_path = null;
    }

    /**
     * Validate the current on-disk config (NOT the editor buffer) — useful
     * after running `caddy fmt --overwrite` or after fixing a problem on the
     * server out of band. Result lands in the same surface the save flow uses.
     */
    public function validateWebserverConfig(): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if (! $this->engineSupportsConfig($this->workspace_tab)) {
            $this->toastError(__('No validate command for this engine.'));

            return;
        }

        try {
            $result = app(RemoteWebserverConfigService::class)->validate($this->server, $this->workspace_tab);
        } catch (\Throwable $e) {
            $this->toastError(__('Validate failed to run: :msg', ['msg' => $e->getMessage()]));

            return;
        }

        $this->config_validate_output = $result['output'];
        $this->config_validate_ok = $result['ok'];
        if ($result['ok']) {
            $this->toastSuccess(__('Config validated.'));
        } else {
            $this->toastError(__('Config validation reported problems — see output below.'));
        }
    }

    /**
     * Persist the editor contents to the loaded path. Backup + atomic write +
     * post-write validate is all done in the service; this method only
     * dispatches and surfaces the result.
     */
    public function saveWebserverConfig(): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }
        if ($this->config_truncated_on_load) {
            // Refuse to save when the editor only has a HEAD-truncated view of
            // the file — the operator would silently chop off everything past
            // the preview cap. They need to bump config_preview_max_bytes (or
            // edit the file on the server directly) first.
            $this->toastError(__('Refusing to save: this file is too large for the editor and was loaded truncated.'));

            return;
        }

        if ($this->webserverConfigRevisionsEnabled()) {
            app(ServerWebserverConfigEditor::class)->ensureBaseline(
                $this->server,
                $this->workspace_tab,
                (string) $this->config_selected_path,
                $this->config_original_contents,
                auth()->user(),
            );
        }

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save webserver config: :path', ['path' => basename((string) $this->config_selected_path)]),
        );
        $this->pending_write_console_id = $consoleId;
        RunWebserverConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'write',
            $this->workspace_tab,
            $this->config_selected_path,
            $this->config_contents,
            '',
            auth()->id(),
            $this->webserverConfigRevisionsEnabled(),
        );
        $this->webserverConfigSaveDiffOpen = false;
        $this->toastSuccess(__('Save queued — progress shows in the banner above.'));
    }

    /**
     * Dry-run the current buffer against the engine validator without
     * committing. The service stages the proposed content, swaps it into
     * the live path, runs the validator, and ALWAYS restores. Lets the
     * operator confirm syntax before clicking Save.
     */
    public function validateWebserverConfigBuffer(): void
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

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Validate webserver config buffer: :path', ['path' => basename((string) $this->config_selected_path)]),
        );
        $this->pending_validate_console_id = $consoleId;
        RunWebserverConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'validate',
            $this->workspace_tab,
            $this->config_selected_path,
            $this->config_contents,
        );
        $this->toastSuccess(__('Validation queued — progress shows in the banner above.'));
    }

    /**
     * Drop the dply-canonical content for the currently-loaded path into the
     * editor buffer. Doesn't write — the operator still has to click Save.
     * Limited to engines/paths dply owns a builder for (OLS httpd_config.conf
     * and per-site vhconf.conf for v1).
     */
    public function resetWebserverConfigToDefault(): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded.'));

            return;
        }

        try {
            $contents = app(RemoteWebserverConfigService::class)->defaultContent(
                $this->server,
                $this->workspace_tab,
                $this->config_selected_path,
            );
        } catch (\Throwable $e) {
            $this->toastError(__('Reset failed: :msg', ['msg' => $e->getMessage()]));

            return;
        }

        $this->config_contents = $contents;
        $this->config_validate_output = null;
        $this->config_validate_ok = null;
        $this->toastSuccess(__('Loaded the dply-canonical content. Review and click Save to commit.'));
    }

    /**
     * Restore a previous backup over the currently-loaded path. The service
     * snapshots the current state first, so a botched restore can be undone
     * by selecting the newest backup created at this step.
     */
    public function restoreWebserverConfigBackup(string $backup_path): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }
        if ($this->config_selected_path === null) {
            $this->toastError(__('No config file loaded — open one before restoring.'));

            return;
        }

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Restore revision: :path', ['path' => basename($backup_path)]),
        );
        RunWebserverConfigOpJob::dispatch(
            $this->server->id,
            $consoleId,
            'restore',
            $this->workspace_tab,
            $this->config_selected_path,
            '',
            $backup_path,
        );
        $this->toastSuccess(__('Restore queued — progress shows in the banner above.'));
    }

    /**
     * True when the active workspace tab is a webserver engine with a known
     * config layout. The {@see RemoteWebserverConfigService} would reject the
     * call anyway, but this lets the UI hide the affordances pre-emptively.
     */
    protected function engineSupportsConfig(string $engine): bool
    {
        return in_array($engine, ['nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy', 'envoy', 'openresty'], true);
    }

    /**
     * Common pre-flight for editor / log actions: requires ops-ready, refuses
     * deployers. Returns false (and emits a toast) when the guard trips, so
     * callers can early-return without duplicating the same boilerplate.
     */
    protected function guardConfigAction(): bool
    {
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot edit server config.'));

            return false;
        }
        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before this action.'));

            return false;
        }

        return true;
    }

    protected function refreshConfigBackups(): void
    {
        if ($this->config_selected_path === null) {
            $this->config_backups = [];

            return;
        }
        try {
            $this->config_backups = app(RemoteWebserverConfigService::class)->listBackups(
                $this->server,
                $this->workspace_tab,
                $this->config_selected_path,
            );
        } catch (\Throwable) {
            $this->config_backups = [];
        }
    }

    protected function resetConfigEditorState(): void
    {
        $this->config_selected_path = null;
        $this->config_contents = '';
        $this->config_validate_output = null;
        $this->config_validate_ok = null;
        $this->config_truncated_on_load = false;
        $this->config_last_backup = null;
        $this->config_backups = [];
        $this->webserverConfigFilesRaw = [];
        $this->webserverConfigFilesLoaded = false;
    }
}
