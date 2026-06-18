<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ServerManageRemoteSshJob;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\ServerManageSshExecutor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesServerToolActions
{
    /** Set by {@see WorkspaceWebserver::repairCaddyPhpFpmUpstream()} before {@see runAllowlistedAction()}. */
    public ?string $allowlistedActionPhpVersion = null;

    /** Server default PHP when it differs from the Caddy upstream socket version. */
    public ?string $allowlistedActionPhpVersionFallback = null;

    /** Stale PHP version from Caddy upstream when site configs need rewriting. */
    public ?string $allowlistedActionUpstreamPhpVersion = null;

    /** Action key (e.g. install_docker) while a Tools install/repair is in flight. */
    public ?string $pendingToolActionKey = null;

    /** Manage → Tools sub-panel: `tools` (catalog list) or `runtimes` (mise). */
    public string $toolsPanel = 'tools';

    public function setToolsPanel(string $panel): void
    {
        if (! in_array($panel, ['tools', 'runtimes'], true)) {
            return;
        }

        $this->toolsPanel = $panel;
    }

    public function runAllowlistedAction(string $key): void
    {
        $this->authorize('update', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;

        if ($this->currentUserIsDeployer()) {
            $this->remote_error = __('Deployers cannot run service actions on servers.');

            return;
        }

        $service = config('server_manage.service_actions', []);
        $danger = config('server_manage.dangerous_actions', []);
        if ($key === 'apply_edge_backend_configs') {
            $this->applyEdgeBackendConfigs();

            return;
        }
        $def = $service[$key] ?? $danger[$key] ?? null;
        if (! is_array($def) || empty($def['script'])) {
            $this->remote_error = __('Unknown action.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->remote_error = __('Provisioning and SSH must be ready before running actions.');

            return;
        }

        set_time_limit((int) ($def['timeout'] ?? 120) + 30);

        $script = (string) $def['script'];
        $meta = $this->server->meta ?? [];
        if ($key === 'repair_caddy_php_fpm_upstream') {
            $v = $this->allowlistedActionPhpVersion;
            if (! is_string($v) || preg_match('/^\d+\.\d+$/', $v) !== 1) {
                $this->remote_error = __('Could not determine PHP version for this repair.');

                return;
            }
            $script = 'export DPLY_PHP_VERSION='.escapeshellarg($v)."\n".$script;
            $upstream = $this->allowlistedActionUpstreamPhpVersion;
            if (is_string($upstream) && preg_match('/^\d+\.\d+$/', $upstream) === 1 && $upstream !== $v) {
                $script = 'export DPLY_UPSTREAM_PHP_VERSION='.escapeshellarg($upstream)."\n".$script;
            }
        } elseif (in_array($key, ['restart_php_fpm', 'reload_php_fpm'], true)) {
            $v = (string) ($meta['default_php_version'] ?? '8.3');
            if (! preg_match('/^\d+\.\d+$/', $v)) {
                $v = '8.3';
            }
            $script = 'export DPLY_PHP_VERSION='.escapeshellarg($v)."\n".$script;
        }
        if (str_starts_with($key, 'mysql_') && ! empty($meta['manage_internal_db_password']) && is_string($meta['manage_internal_db_password'])) {
            $script = 'export DPLY_DB_PASSWORD='.escapeshellarg($meta['manage_internal_db_password'])."\n".$script;
        }
        if (str_contains($script, '__DPLY_DEPLOY_USER__')) {
            $deployUser = trim((string) ($this->server->ssh_user ?? '')) !== ''
                ? (string) $this->server->ssh_user
                : (string) config('server_provision.deploy_ssh_user', 'dply');
            $script = str_replace('__DPLY_DEPLOY_USER__', $deployUser, $script);
        }

        $this->pendingToolActionKey = $key;

        try {
            $server = $this->server->fresh();
            $timeout = isset($def['timeout']) ? (int) $def['timeout'] : null;
            $flash = ($def['label'] ?? $key).' '.__('finished.');
            $label = (string) ($def['label'] ?? $key);
            $taskName = 'manage-action:'.$key;

            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedManageScript(
                    $server,
                    $taskName,
                    $script,
                    $timeout,
                    $flash,
                    __('TaskRunner (SSH)').' — '.$label,
                    $label,
                );

                return;
            }

            $logId = $this->logManageActionStart($server, $taskName, $label);
            ServerManageAction::query()
                ->where('id', $logId)
                ->update(['status' => ServerManageAction::STATUS_RUNNING]);

            // Sync path — seed the ConsoleAction row so the banner picks it up
            // in real time, then stream output lines into it as they arrive
            // alongside the existing remote_output buffer.
            $consoleId = $this->seedManageConsoleAction($server, $label);
            $emitter = new ConsoleEmitter($consoleId);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_RUNNING,
                'started_at' => now(),
                'updated_at' => now(),
            ]);

            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta(
                __('TaskRunner (SSH)').' — '.$label,
                $this->manageSshConnectionLabel($server)."\n".__('Remote script').":\n".$script
            );
            try {
                $out = $this->runManageInlineBash(
                    $server,
                    $taskName,
                    $script,
                    function (string $type, string $buffer) use ($emitter): void {
                        $this->remoteSshStreamAppendStdout($buffer);
                        foreach (preg_split('/\R/', rtrim($buffer, "\n")) ?: [] as $line) {
                            if ($line !== '') {
                                $emitter($line);
                            }
                        }
                    },
                    $timeout,
                );
                $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
                // Some daemons (e.g. `lshttpd -t`) write diagnostics into their
                // own log file rather than stdout/stderr, so the streaming
                // callback never fires and the banner shows "No output
                // recorded." Drop a placeholder line so the operator at least
                // sees that the command finished cleanly.
                if (trim((string) $this->remote_output) === '') {
                    $emitter->success(__('Command finished with no terminal output.'), 'dply');
                }
                DB::table('console_actions')->where('id', $consoleId)->update([
                    'status' => ConsoleAction::STATUS_COMPLETED,
                    'finished_at' => now(),
                    'error' => null,
                    'updated_at' => now(),
                ]);
                $this->toastSuccess($flash);
            } catch (\Throwable $inner) {
                DB::table('console_actions')->where('id', $consoleId)->update([
                    'status' => ConsoleAction::STATUS_FAILED,
                    'finished_at' => now(),
                    'error' => mb_substr($inner->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);
                throw $inner;
            }
        } catch (\Throwable $e) {
            $this->remote_error = $e->getMessage();
        } finally {
            if (! $this->shouldQueueManageRemoteTasks()) {
                $this->pendingToolActionKey = null;
            }
        }
    }

    /**
     * @return array<string, array{status: string, message: string, label: string}>
     */
    public function activeManageActionOperations(): array
    {
        return $this->activeToolActionOperations();
    }

    /**
     * @return array<string, array{status: string, message: string, label: string}>
     */
    protected function activeToolActionOperations(): array
    {
        $rows = ServerManageAction::query()
            ->where('server_id', $this->server->id)
            ->where('task_name', 'like', 'manage-action:%')
            ->whereIn('status', [
                ServerManageAction::STATUS_QUEUED,
                ServerManageAction::STATUS_RUNNING,
            ])
            ->orderByDesc('created_at')
            ->get(['task_name', 'status', 'label']);

        $ops = [];

        foreach ($rows as $row) {
            if (! preg_match('/^manage-action:(.+)$/', (string) $row->task_name, $matches)) {
                continue;
            }

            $key = $matches[1];
            if (isset($ops[$key])) {
                continue;
            }

            $status = (string) $row->status;
            $ops[$key] = [
                'status' => $status,
                'label' => (string) $row->label,
                'message' => $this->toolActionBusyMessage($key, $status, (string) $row->label),
            ];
        }

        if ($this->manageRemoteTaskId !== null
            && $this->manageRemoteTaskId !== ''
            && is_string($this->manageRemoteTaskName)
            && preg_match('/^manage-action:(.+)$/', $this->manageRemoteTaskName, $matches)) {
            $key = $matches[1];
            if (! isset($ops[$key])) {
                $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
                $status = is_array($payload) ? (string) ($payload['status'] ?? 'queued') : 'queued';

                if (in_array($status, ['queued', 'running'], true)) {
                    $label = config('server_manage.service_actions.'.$key.'.label')
                        ?? config('server_manage.dangerous_actions.'.$key.'.label')
                        ?? $key;

                    $ops[$key] = [
                        'status' => $status,
                        'label' => is_string($label) ? $label : $key,
                        'message' => $this->toolActionBusyMessage($key, $status, is_string($label) ? $label : $key),
                    ];
                }
            }
        }

        if ($this->pendingToolActionKey !== null
            && $this->pendingToolActionKey !== ''
            && ! isset($ops[$this->pendingToolActionKey])) {
            $key = $this->pendingToolActionKey;
            $label = config('server_manage.service_actions.'.$key.'.label')
                ?? config('server_manage.dangerous_actions.'.$key.'.label')
                ?? $key;

            $ops[$key] = [
                'status' => ServerManageAction::STATUS_RUNNING,
                'label' => is_string($label) ? $label : $key,
                'message' => $this->toolActionBusyMessage($key, ServerManageAction::STATUS_RUNNING, is_string($label) ? $label : $key),
            ];
        }

        return $ops;
    }

    protected function toolActionBusyMessage(string $key, string $status, string $label): string
    {
        if ($status === ServerManageAction::STATUS_QUEUED) {
            return __('Queuing :action…', ['action' => $label]);
        }

        if (str_starts_with($key, 'install_')) {
            return __('Installing :action…', ['action' => $label]);
        }

        if (str_starts_with($key, 'repair_') || str_starts_with($key, 'update_')) {
            return __('Updating :action…', ['action' => $label]);
        }

        return __('Running :action…', ['action' => $label]);
    }
}
