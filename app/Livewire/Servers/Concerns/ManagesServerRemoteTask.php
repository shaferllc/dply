<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ServerManageRemoteSshJob;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ServerAptLockBash;
use App\Services\Servers\ServerManageSshExecutor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesServerRemoteTask
{
    public ?string $remote_output = null;

    public ?string $remote_error = null;

    /**
     * When set, {@see syncManageRemoteTaskFromCache} polls cache until the queued SSH task finishes.
     */
    public ?string $manageRemoteTaskId = null;

    /** Full task name for the in-flight queued SSH job (used to trigger post-run reprobe). */
    public ?string $manageRemoteTaskName = null;

    public function cancelQueuedManageTasks(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot cancel queued tasks.'));

            return;
        }

        $cleared = 0;
        if ($this->manageRemoteTaskId !== null && $this->manageRemoteTaskId !== '') {
            Cache::forget(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
            $cleared++;
        }
        $this->manageRemoteTaskId = null;
        $this->remote_output = null;
        $this->remote_error = null;
        $this->resetRemoteSshStreamTargets();
        $this->toastSuccess(__('Cleared :n queued task(s) from this view.', ['n' => $cleared]));
    }

    public function syncManageRemoteTaskFromCache(): void
    {
        if ($this->manageRemoteTaskId === null || $this->manageRemoteTaskId === '') {
            return;
        }

        $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
        if (! is_array($payload)) {
            return;
        }

        $status = (string) ($payload['status'] ?? '');
        $out = (string) ($payload['output'] ?? '');
        $queuedAt = isset($payload['queued_at']) && is_numeric($payload['queued_at'])
            ? (int) $payload['queued_at']
            : null;
        $stalledAfter = (int) config('server_manage.remote_task_stalled_queued_seconds', 45);
        $stalledQueued = $status === 'queued'
            && $queuedAt !== null
            && (time() - $queuedAt) > $stalledAfter;

        $this->remote_output = $out !== ''
            ? $out
            : match ($status) {
                'queued' => $stalledQueued
                    ? __('Still preparing this task. If it stays stuck, contact your administrator.')
                    : __('Task queued…'),
                'running' => __('Running on server…'),
                default => '',
            };

        $err = $payload['error'] ?? null;
        $this->remote_error = is_string($err) && $err !== '' ? $err : null;

        if (! in_array($status, ['finished', 'failed'], true)) {
            return;
        }

        $taskName = $this->manageRemoteTaskName;
        $this->finalizeManageRemoteTaskLog($status, $taskName);

        if ($status === 'finished') {
            $flash = $payload['flash_success'] ?? null;
            if (is_string($flash) && $flash !== '') {
                $this->toastSuccess($flash);
            }

            if ($this->shouldRefreshInventoryAfterRemoteTask($taskName)) {
                $this->runPostMiseInventoryRefresh();
            }

            if ($this->section === 'tools' && $taskName === 'manage-action:set_deploy_git_identity') {
                $this->hydrateGitDeployIdentityForm();
            }
        }

        Cache::forget(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
        $this->manageRemoteTaskId = null;
        $this->manageRemoteTaskName = null;
        $this->pendingToolActionKey = null;
    }

    protected function finalizeManageRemoteTaskLog(string $cacheStatus, ?string $taskName): void
    {
        if (! is_string($taskName) || $taskName === '') {
            return;
        }

        if (! in_array($cacheStatus, ['finished', 'failed'], true)) {
            return;
        }

        $rowStatus = $cacheStatus === 'finished'
            ? ServerManageAction::STATUS_FINISHED
            : ServerManageAction::STATUS_FAILED;

        ServerManageAction::query()
            ->where('server_id', $this->server->id)
            ->where('task_name', $taskName)
            ->whereIn('status', [
                ServerManageAction::STATUS_QUEUED,
                ServerManageAction::STATUS_RUNNING,
            ])
            ->update([
                'status' => $rowStatus,
                'finished_at' => now(),
            ]);
    }

    protected function shouldRefreshInventoryAfterRemoteTask(?string $taskName): bool
    {
        if (! is_string($taskName) || $taskName === '') {
            return false;
        }

        if (str_starts_with($taskName, 'mise-runtime:')) {
            return true;
        }

        if (! preg_match('/^manage-action:(.+)$/', $taskName, $matches)) {
            return false;
        }

        $key = $matches[1];
        if ($key === 'set_deploy_git_identity') {
            return true;
        }

        $entry = config('server_manage.service_actions', [])[$key]
            ?? config('server_manage.dangerous_actions', [])[$key]
            ?? null;

        return is_array($entry) && (bool) ($entry['rerun_probe_after_finish'] ?? false);
    }

    protected function shouldRefreshWebserverLiveStateAfterRemoteTask(?string $taskName): bool
    {
        if (! is_string($taskName) || $taskName === '') {
            return false;
        }

        if (! preg_match('/^manage-action:(.+)$/', $taskName, $matches)) {
            return false;
        }

        $key = $matches[1];
        $entry = config('server_manage.service_actions', [])[$key]
            ?? config('server_manage.dangerous_actions', [])[$key]
            ?? null;

        return is_array($entry) && (bool) ($entry['refresh_webserver_live_state_after_finish'] ?? false);
    }

    /**
     * @param  callable(string, string):void  $onOutput
     */
    protected function runManageInlineBash(
        Server $server,
        string $taskName,
        string $inlineBash,
        callable $onOutput,
        ?int $timeoutSeconds,
    ): ProcessOutput {
        return app(ServerManageSshExecutor::class)->runInlineBash(
            $server,
            $taskName,
            $inlineBash,
            $timeoutSeconds,
            $onOutput,
        );
    }

    protected function shouldQueueManageRemoteTasks(): bool
    {
        return (bool) config('server_manage.queue_remote_tasks', true);
    }

    protected function dispatchQueuedManageScript(
        Server $server,
        string $taskName,
        string $inlineBash,
        ?int $timeoutSeconds,
        ?string $flashSuccess,
        string $streamTitle,
        ?string $consoleLabel = null,
    ): void {
        $this->manageRemoteTaskId = null;
        $this->manageRemoteTaskName = $taskName;

        if (preg_match('/^manage-action:(.+)$/', $taskName, $matches)) {
            $this->pendingToolActionKey = $matches[1];
        }

        $inlineBash = ServerAptLockBash::wrapManageScript($inlineBash);

        $id = (string) Str::uuid();
        $ttl = (int) config('server_manage.remote_task_cache_ttl_seconds', 900);

        Cache::put(ServerManageRemoteSshJob::cacheKey($id), [
            'status' => 'queued',
            'output' => '',
            'error' => null,
            'flash_success' => null,
            'queued_at' => time(),
        ], now()->addSeconds(max(120, $ttl)));

        if (config('server_manage.supersede_duplicate_remote_tasks', true)) {
            Cache::put(
                ServerManageRemoteSshJob::activeRequestCacheKey($server->id, $taskName),
                $id,
                now()->addSeconds(max(120, $ttl)),
            );
        }

        // Persist a recent-activity row so Overview can show what's happened.
        $logId = $this->logManageActionStart($server, $taskName, $streamTitle);

        // Seed a `manage_action` ConsoleAction row so workspaces that opt in to
        // the streaming banner partial (currently: Webserver) show progress in
        // the same banner-and-View-output UI the switch job uses. Other
        // workspaces ignore the row and continue rendering the legacy
        // "Command output" panel — no UX regression there.
        $consoleId = $this->seedManageConsoleAction($server, $consoleLabel ?? $streamTitle);

        ServerManageRemoteSshJob::dispatch(
            $server->id,
            $id,
            $taskName,
            $inlineBash,
            $timeoutSeconds ?? (int) config('task-runner.default_timeout', 60),
            $flashSuccess,
            $logId,
            null,
            $consoleId,
        );

        $this->manageRemoteTaskId = $id;
        $this->remote_output = __('Task queued. This page will update when the server responds.');
        $this->remote_error = null;
        $this->resetRemoteSshStreamTargets();
        $this->remoteSshStreamSetMeta(
            $streamTitle,
            $this->manageSshConnectionLabel($server)."\n".__('Remote script').":\n".$inlineBash."\n\n"
            .__('Runs in the background so the browser does not block on SSH.')
        );
    }

    /**
     * Create a `manage_action` ConsoleAction row scoped to the given server so
     * the streaming banner partial can render it. Auto-dismisses any prior
     * terminal manage_action rows for this subject so the banner-getter (which
     * picks the most recent non-dismissed run) doesn't get stuck showing an
     * old completed action.
     *
     * Scoping dismissal to `kind = manage_action` is deliberate — the
     * webserver_switch banner has its own lifecycle and we don't want one
     * manage button press to wipe out a "switch failed" surface.
     */
    protected function seedManageConsoleAction(Server $server, string $label): string
    {
        ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->whereIn('status', [
                ConsoleAction::STATUS_COMPLETED,
                ConsoleAction::STATUS_FAILED,
            ])
            ->update(['dismissed_at' => now()]);

        $row = ConsoleAction::query()->create([
            'subject_type' => $server->getMorphClass(),
            'subject_id' => $server->id,
            'kind' => 'manage_action',
            'status' => ConsoleAction::STATUS_QUEUED,
            'user_id' => auth()->id(),
            'label' => $label.' …',
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);

        return (string) $row->id;
    }

    protected function manageSshConnectionLabel(Server $server): string
    {
        $host = (string) $server->ip_address;
        $deploy = trim((string) $server->ssh_user) ?: 'root';
        if (! (bool) config('server_manage.use_root_ssh', true)) {
            return $deploy.'@'.$host;
        }

        if ($deploy === 'root') {
            return 'root@'.$host;
        }

        return 'root@'.$host.' ('.__('falls back to').' '.$deploy.'@'.$host.')';
    }

    /**
     * Persist a recent-activity row so Overview can show "what just happened".
     * Returns the row id so the queued job can update status as it progresses.
     */
    protected function logManageActionStart(Server $server, string $taskName, string $streamTitle): string
    {
        $serviceActions = config('server_manage.service_actions', []);
        $dangerous = config('server_manage.dangerous_actions', []);

        // Best-effort label: try the action key after the colon (e.g. "manage-action:reload_nginx").
        $label = $streamTitle;
        if (preg_match('/^manage-action:(.+)$/', $taskName, $m)) {
            $key = $m[1];
            $label = (string) ($serviceActions[$key]['label'] ?? $dangerous[$key]['label'] ?? $key);
        } elseif (str_starts_with($taskName, 'manage-config-preview')) {
            $label = __('Configuration preview');
        }

        $row = ServerManageAction::create([
            'server_id' => $server->id,
            'user_id' => auth()->id(),
            'task_name' => $taskName,
            'label' => $label,
            'status' => ServerManageAction::STATUS_QUEUED,
        ]);

        return $row->id;
    }
}
