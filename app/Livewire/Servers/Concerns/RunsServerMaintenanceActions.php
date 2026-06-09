<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ServerManageRemoteSshJob;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Services\Servers\ServerAptLockBash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Run a curated subset of {@see config('server_manage.service_actions')} /
 * .dangerous_actions on the Maintenance workspace — OS package upkeep, cleanup
 * prunes, and reboot. Reuses the exact Manage-page plumbing: the same queued
 * {@see ServerManageRemoteSshJob}, the same {@see ServerManageAction} activity
 * stream, and the same cache-key/supersession helpers. We only constrain which
 * keys may run (the {@see config('server_maintenance.operations')} allowlist)
 * and label the task `manage-action:{key}` so RecentActionsLog resolves the
 * human label from server_manage config exactly like WorkspaceManage does.
 *
 * @phpstan-require-extends Component
 *
 * @property Server $server
 */
trait RunsServerMaintenanceActions
{
    public ?string $remote_output = null;

    public ?string $remote_error = null;

    /** Cache-task id while a maintenance action is queued/running. */
    public ?string $maintenanceRemoteTaskId = null;

    /** Terminal/in-flight status of the current task: queued|running|finished|failed. */
    public ?string $maintenanceTaskStatus = null;

    /** Human label of the action currently shown in the console banner. */
    public ?string $maintenanceActionLabel = null;

    /**
     * Flat allowlist of action keys permitted on the Maintenance page.
     *
     * @return list<string>
     */
    protected function maintenanceActionKeys(): array
    {
        $groups = config('server_maintenance.operations', []);

        return collect(is_array($groups) ? $groups : [])
            ->flatten()
            ->map(fn ($key): string => (string) $key)
            ->values()
            ->all();
    }

    /**
     * Resolve a server_manage action definition, honoring the same
     * service-then-dangerous precedence as WorkspaceManage.
     *
     * @return array<string, mixed>|null
     */
    protected function maintenanceActionDef(string $key): ?array
    {
        $service = config('server_manage.service_actions', []);
        $danger = config('server_manage.dangerous_actions', []);
        $def = $service[$key] ?? $danger[$key] ?? null;

        return is_array($def) && ! empty($def['script']) ? $def : null;
    }

    public function runMaintenanceAction(string $key): void
    {
        $this->authorize('update', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;

        if ($this->currentUserIsDeployer()) {
            $this->remote_error = __('Deployers cannot run maintenance operations on servers.');

            return;
        }

        if (! in_array($key, $this->maintenanceActionKeys(), true)) {
            $this->remote_error = __('Unknown action.');

            return;
        }

        $def = $this->maintenanceActionDef($key);
        if ($def === null) {
            $this->remote_error = __('Unknown action.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->remote_error = __('Provisioning and SSH must be ready before running maintenance operations.');

            return;
        }

        $timeout = isset($def['timeout']) ? (int) $def['timeout'] : null;
        $label = (string) ($def['label'] ?? $key);
        set_time_limit(($timeout ?? 120) + 30);

        $server = $this->server->fresh() ?? $this->server;
        $script = ServerAptLockBash::wrapManageScript((string) $def['script']);

        $this->dispatchQueuedMaintenanceScript(
            $server,
            'manage-action:'.$key,
            $script,
            $timeout,
            $label.' '.__('finished.'),
            $label,
        );
    }

    public function syncMaintenanceRemoteTaskFromCache(): void
    {
        if ($this->maintenanceRemoteTaskId === null || $this->maintenanceRemoteTaskId === '') {
            return;
        }

        $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->maintenanceRemoteTaskId));
        if (! is_array($payload)) {
            return;
        }

        $status = (string) ($payload['status'] ?? '');
        $out = (string) ($payload['output'] ?? '');
        $this->maintenanceTaskStatus = $status;

        $this->remote_output = $out !== ''
            ? $out
            : match ($status) {
                'queued' => __('Task queued…'),
                'running' => __('Running on server…'),
                default => '',
            };

        $err = $payload['error'] ?? null;
        $this->remote_error = is_string($err) && $err !== '' ? $err : null;

        if (! in_array($status, ['finished', 'failed'], true)) {
            return;
        }

        if ($status === 'finished') {
            $flash = $payload['flash_success'] ?? null;
            if (is_string($flash) && $flash !== '') {
                $this->toastSuccess($flash);
            }
        }

        // Keep the completed/failed banner (with its output) on screen until the
        // operator dismisses it. We drop the cache entry and let the terminal
        // status stop the poll; the ServerManageAction row persists the run.
        Cache::forget(ServerManageRemoteSshJob::cacheKey($this->maintenanceRemoteTaskId));
    }

    public function dismissMaintenanceTask(): void
    {
        $this->maintenanceRemoteTaskId = null;
        $this->maintenanceTaskStatus = null;
        $this->maintenanceActionLabel = null;
        $this->remote_output = null;
        $this->remote_error = null;
    }

    /** True while a maintenance action is queued or running (drives spinner + poll). */
    public function maintenanceTaskBusy(): bool
    {
        return $this->maintenanceRemoteTaskId !== null
            && in_array((string) $this->maintenanceTaskStatus, ['', 'queued', 'running'], true);
    }

    protected function dispatchQueuedMaintenanceScript(
        Server $server,
        string $taskName,
        string $inlineBash,
        ?int $timeoutSeconds,
        ?string $flashSuccess,
        string $label,
    ): void {
        $this->maintenanceRemoteTaskId = null;

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

        // Persist a recent-activity row so progress survives a page reload and
        // shares the Manage page's activity stream. The job advances this row
        // through queued → running → finished/failed via updateLog().
        $logRow = ServerManageAction::create([
            'server_id' => $server->id,
            'user_id' => auth()->id(),
            'task_name' => $taskName,
            'label' => $label,
            'status' => ServerManageAction::STATUS_QUEUED,
        ]);

        ServerManageRemoteSshJob::dispatch(
            $server->id,
            $id,
            $taskName,
            $inlineBash,
            $timeoutSeconds ?? (int) config('task-runner.default_timeout', 60),
            $flashSuccess,
            $logRow->id,
        );

        $this->maintenanceRemoteTaskId = $id;
        $this->maintenanceTaskStatus = 'queued';
        $this->maintenanceActionLabel = $label;
        $this->remote_output = __('Task queued. This page will update when the server responds.');
        $this->remote_error = null;
    }
}
