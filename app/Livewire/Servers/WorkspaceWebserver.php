<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Models\Server;
use App\Services\Servers\RemoteWebserverConfigService;
use App\Services\Servers\ServerManageSshExecutor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;

/**
 * Top-level "Webserver" workspace — gives the per-server webserver picker grid +
 * cascade modal + audit history its own sidebar entry, peer to PHP / Caches /
 * Cron, rather than living nested under Manage > Web.
 *
 * Extends {@see WorkspaceManage} so all the switch state, switch methods,
 * service-action plumbing (runAllowlistedAction et al), banner concerns, and
 * console-action dismissal are inherited unchanged. The only differences:
 *
 *   - `mount()` accepts no `?section` query string (this isn't a sub-tab
 *     anymore) — section is fixed at 'web' so the parent's render share +
 *     trait-internal asserts continue working.
 *   - `render()` points at a dedicated `workspace-webserver.blade.php` view
 *     that wraps the group-web partial in {@see <x-server-workspace-layout>}
 *     with `active="webserver"` (sidebar highlight).
 *   - Adds Tools / Logs / Config sub-tabs and their backing Livewire methods
 *     (load/save/validate/restore for config; tail for logs). Path safety and
 *     atomic-write semantics live in {@see RemoteWebserverConfigService}.
 *
 * Result: clicking "Webserver" in the sidebar lands on the same content,
 * scoped + framed as a peer workspace rather than nested.
 */
#[Layout('layouts.app')]
class WorkspaceWebserver extends WorkspaceManage
{
    /**
     * Second-level tab within this workspace — mirrors WorkspaceDatabases /
     * WorkspaceCaches: an "overview" tab, one tab per webserver in the
     * catalog (currently active gets an Active badge; the rest let the
     * operator open the cascade-switch modal), and an "advanced" tab that
     * collects PHP-FPM, TLS, and the switch-history table.
     *
     * Allowed values are validated in {@see setWorkspaceTab()}; an unknown
     * value falls back to 'overview' rather than throwing.
     */
    public string $workspace_tab = 'overview';

    /**
     * Per-engine sub-tab. Originally just `overview` / `info`; now also
     * `tools` (per-engine CLI commands), `logs` (live access + error tail),
     * and `config` (file editor). Validated in {@see setEngineSubtab()};
     * unknown values fall back to `overview`.
     */
    public string $engine_subtab = 'overview';

    // ---- Config editor state -----------------------------------------

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

    // ---- Log viewer state --------------------------------------------

    /** Which log to read: 'access', 'error', or 'journal'. */
    public string $log_kind = 'access';

    /** Last fetched log buffer; rendered in a <pre> on the Logs tab. */
    public string $log_output = '';

    /** How many trailing lines the last fetch grabbed. */
    public int $log_lines = 300;

    /** When true, the Logs tab adds a wire:poll so the buffer refreshes every few seconds. */
    public bool $log_live = false;

    /**
     * Time range for the per-engine Overview health charts. One of the
     * ServerMetricsRangeQuery::RANGES keys: '1h', '6h', '24h', '7d'.
     * Persisted in localStorage on the client (keyed per server) so the
     * operator's preference survives reloads.
     */
    public string $engine_metrics_range = '1h';

    public function mount(Server $server, ?string $section = null): void
    {
        // Force the inherited 'web' section state — the parent's render share
        // and any internal asserts on $section still resolve correctly without
        // requiring the operator to type `?section=web` on the URL.
        parent::mount($server, 'web');
    }

    public function setWorkspaceTab(string $tab): void
    {
        $allowed = ['overview', 'nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy', 'advanced'];
        $this->workspace_tab = in_array($tab, $allowed, true) ? $tab : 'overview';
        // Reset the sub-tab on every top-level switch so the operator always
        // lands on the actionable view first. Skipping this would leave
        // Caddy on `info` after they navigated away from Nginx's `info`.
        $this->engine_subtab = 'overview';
        $this->resetConfigEditorState();
        $this->resetLogViewerState();
    }

    /**
     * Range setter for the per-engine Overview health charts. Validates
     * against ServerMetricsRangeQuery's known ranges; falls back to '1h'.
     */
    public function setEngineMetricsRange(string $range): void
    {
        $allowed = array_keys(\App\Services\Servers\ServerMetricsRangeQuery::RANGES);
        $this->engine_metrics_range = in_array($range, $allowed, true) ? $range : '1h';
    }

    public function setEngineSubtab(string $subtab): void
    {
        $allowed = ['overview', 'info', 'tools', 'logs', 'config'];
        $this->engine_subtab = in_array($subtab, $allowed, true) ? $subtab : 'overview';
        if ($this->engine_subtab !== 'config') {
            $this->resetConfigEditorState();
        }
        if ($this->engine_subtab !== 'logs') {
            $this->resetLogViewerState();
        }
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

        try {
            $result = app(RemoteWebserverConfigService::class)->read($this->server, $this->workspace_tab, $path);
        } catch (\Throwable $e) {
            $this->toastError(__('Could not load config: :msg', ['msg' => $e->getMessage()]));

            return;
        }

        $this->config_selected_path = $path;
        $this->config_contents = $result['contents'];
        $this->config_truncated_on_load = $result['truncated'];
        $this->config_validate_output = null;
        $this->config_validate_ok = null;
        $this->config_last_backup = null;
        $this->refreshConfigBackups();
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

        try {
            $result = app(RemoteWebserverConfigService::class)->write(
                $this->server,
                $this->workspace_tab,
                $this->config_selected_path,
                $this->config_contents,
            );
        } catch (\Throwable $e) {
            $this->toastError(__('Save failed: :msg', ['msg' => $e->getMessage()]));

            return;
        }

        $this->config_validate_output = $result['validate_output'];
        $this->config_validate_ok = $result['validate_ok'];
        $this->config_last_backup = $result['backup'];
        $this->refreshConfigBackups();

        if ($result['validate_ok']) {
            $this->toastSuccess(__('Config saved and validated.'));
        } else {
            $this->toastError(__('Config saved, but validation reported problems — review the output before reloading.'));
        }
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

        try {
            $result = app(RemoteWebserverConfigService::class)->restoreBackup(
                $this->server,
                $this->workspace_tab,
                $backup_path,
                $this->config_selected_path,
            );
        } catch (\Throwable $e) {
            $this->toastError(__('Restore failed: :msg', ['msg' => $e->getMessage()]));

            return;
        }

        // After a successful restore, reload the editor buffer so the operator
        // sees the restored content rather than what they had typed.
        $this->loadWebserverConfig($this->config_selected_path);
        $this->config_validate_output = $result['validate_output'];
        $this->config_validate_ok = $result['validate_ok'];

        if ($result['validate_ok']) {
            $this->toastSuccess(__('Backup restored and validated.'));
        } else {
            $this->toastError(__('Backup restored, but validation reported problems.'));
        }
    }

    /**
     * Refresh the buffer the Logs tab renders. `$kind` is one of `access`,
     * `error`, or `journal` — the available choices depend on the engine
     * layout. Limited to 300 lines unless explicitly overridden.
     */
    public function refreshWebserverLog(?string $kind = null, ?int $lines = null): void
    {
        if (! $this->guardConfigAction()) {
            return;
        }

        if ($kind !== null) {
            $this->log_kind = in_array($kind, ['access', 'error', 'journal'], true) ? $kind : 'access';
        }
        if ($lines !== null) {
            $this->log_lines = max(50, min(2000, $lines));
        }

        $layout = (array) config('server_manage.webserver_config_layout.'.$this->workspace_tab, []);
        $path = match ($this->log_kind) {
            'access' => $layout['access_log'] ?? null,
            'error' => $layout['error_log'] ?? null,
            'journal' => null,
            default => null,
        };

        try {
            if ($this->log_kind === 'journal' || $path === null) {
                $unit = (string) ($layout['journal_unit'] ?? $this->workspace_tab);
                $script = sprintf(
                    '(sudo -n journalctl --no-pager -eu %1$s -n %2$d 2>&1 || journalctl --no-pager -eu %1$s -n %2$d 2>&1)',
                    escapeshellarg($unit),
                    $this->log_lines,
                );
            } else {
                $script = sprintf(
                    '(sudo -n tail -n %1$d %2$s 2>&1 || tail -n %1$d %2$s 2>&1)',
                    $this->log_lines,
                    escapeshellarg($path),
                );
            }
            $out = $this->runManageInlineBash(
                $this->server,
                'webserver-log:'.$this->workspace_tab.':'.$this->log_kind,
                $script,
                function (string $type, string $buffer): void {},
                30,
            );
            $this->log_output = ServerManageSshExecutor::stripSshClientNoise($out->getBuffer());
        } catch (\Throwable $e) {
            $this->log_output = '[error] '.$e->getMessage();
        }
    }

    public function toggleWebserverLogLive(): void
    {
        $this->log_live = ! $this->log_live;
        if ($this->log_live) {
            $this->refreshWebserverLog();
        }
    }

    public function render(): View
    {
        $this->server->refresh();

        $configFiles = [];
        if (in_array($this->workspace_tab, ['nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy'], true) && $this->serverOpsReady()) {
            try {
                $configFiles = app(RemoteWebserverConfigService::class)->listFiles($this->server, $this->workspace_tab);
            } catch (\Throwable) {
                $configFiles = [];
            }
        }

        return view('livewire.servers.workspace-webserver', [
            'configPreviews' => config('server_manage.config_previews', []),
            'serviceActions' => config('server_manage.service_actions', []),
            'dangerousActions' => config('server_manage.dangerous_actions', []),
            'autoUpdateIntervals' => config('server_manage.auto_update_intervals', []),
            'webserverConfigLayout' => config('server_manage.webserver_config_layout', []),
            'webserverConfigFiles' => $configFiles,
            'deletionSummary' => $this->showRemoveServerModal
                ? \App\Services\Servers\ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }

    /**
     * True when the active workspace tab is a webserver engine with a known
     * config layout. The {@see RemoteWebserverConfigService} would reject the
     * call anyway, but this lets the UI hide the affordances pre-emptively.
     */
    protected function engineSupportsConfig(string $engine): bool
    {
        return in_array($engine, ['nginx', 'caddy', 'apache', 'openlitespeed', 'traefik', 'haproxy'], true);
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
    }

    protected function resetLogViewerState(): void
    {
        $this->log_kind = 'access';
        $this->log_output = '';
        $this->log_lines = 300;
        $this->log_live = false;
    }
}
