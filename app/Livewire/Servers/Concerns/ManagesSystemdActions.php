<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Events\Servers\ServerSystemdActionCompletedBroadcast;
use App\Jobs\ServerManageRemoteSshJob;
use App\Jobs\SyncServerSystemdServicesJob;
use App\Models\Server;
use App\Models\ServerSystemdServiceState;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerSystemdServicesCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSystemdActions
{


    /**
     * @param  'start'|'stop'|'restart'|'reload'|'disable'|'enable'  $action
     */
    public function runSystemdServiceAction(string $unit, string $action): void
    {
        $this->authorize('update', $this->server);
        $this->remote_error = null;

        if ($this->systemdDeployerWorkspaceBlocked()) {
            $this->setSystemdRemoteError(__('Deployers cannot control services on servers.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->setSystemdRemoteError(__('Provisioning and SSH must be ready before running actions.'));

            return;
        }

        $allowedActions = ['start', 'stop', 'restart', 'reload', 'disable', 'enable'];
        if (! in_array($action, $allowedActions, true)) {
            $this->setSystemdRemoteError(__('Unknown action.'));

            return;
        }

        try {
            $normalized = app(ServerSystemdServicesCatalog::class)->assertAllowedOnServer($this->server->fresh(), $unit);
        } catch (\InvalidArgumentException $e) {
            $this->setSystemdRemoteError($e->getMessage());

            return;
        }

        $catalog = app(ServerSystemdServicesCatalog::class);
        if ($catalog->isUnitStatusOnlyForServer($this->server->fresh(), $normalized)) {
            $this->setSystemdRemoteError(__('This unit is status-only for your organization. Inspect it with Status; mutating actions are disabled.'));

            return;
        }

        $script = $this->systemdActionBash($normalized, $action);
        $this->systemdPendingKind = 'action';
        $this->systemdRowBusyUnit = $normalized;
        $this->systemdRowBusyAction = $action;
        $this->systemdActiveRowUnit = $normalized;
        $this->systemdActiveRowAction = $action;
        $this->systemdPendingActionUnit = $normalized;
        $this->startSystemdActionBanner($action, $normalized);

        // Record the operator's intent on the state row so the table can
        // immediately render "Starting…" / "Stopping…" / etc. The next
        // inventory sync will clear this when it confirms the actual
        // active_state. A safety expiry inside the renderer auto-clears
        // stale rows (~3 min) if SSH fails to re-sync for any reason.
        ServerSystemdServiceState::query()
            ->where('server_id', $this->server->id)
            ->where('unit', $normalized)
            ->update([
                'pending_action' => $action,
                'pending_action_at' => now(),
            ]);

        $this->patchSystemdInventoryPendingAction($normalized, $action);

        set_time_limit((int) config('server_services.systemd_action_timeout', 180) + 30);
        $timeout = (int) config('server_services.systemd_action_timeout', 180);

        try {
            $server = $this->server->fresh();
            $flash = match ($action) {
                'reload' => __('Reload finished.'),
                'disable' => __('Disable at boot finished. The service may keep running until it is stopped.'),
                'enable' => __('Enable at boot finished.'),
                default => __('Service action finished.'),
            };
            $syncInventoryAfter = true;

            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedSystemdScript(
                    $server,
                    'services-systemd:'.$normalized.':'.$action,
                    $script,
                    $timeout,
                    $flash,
                    $syncInventoryAfter,
                );
                $this->systemdActionBannerStatus = 'queued';

                if ($server->organization) {
                    audit_log($server->organization, auth()->user(), 'server.service.'.$action, $server, null, [
                        'unit' => $normalized,
                        'queued' => true,
                    ]);
                }

                return;
            }

            $out = $this->runManageInlineBash(
                $server,
                'services-systemd:'.$normalized.':'.$action,
                $script,
                static function (string $type, string $buffer): void {},
                $timeout,
            );
            $this->appendSystemdActionBannerOutput(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->finishSystemdActionBanner('completed');
            $this->toastSuccess($flash);
            $this->remote_error = null;
            $this->clearPendingActionAndRehydrate();
            if ($server->organization) {
                audit_log($server->organization, auth()->user(), 'server.service.'.$action, $server, null, [
                    'unit' => $normalized,
                    'result' => 'success',
                ]);
            }
            if ((bool) config('server_services.systemd_inventory_job_enabled', true)) {
                SyncServerSystemdServicesJob::dispatch($this->server->id);
            }
        } catch (\Throwable $e) {
            $this->finishSystemdActionBanner('failed', $e->getMessage());
            $this->setSystemdRemoteError($e->getMessage());
            $this->clearPendingActionAndRehydrate();
            if ($this->server->organization) {
                audit_log($this->server->organization, auth()->user(), 'server.service.'.$action, $this->server, null, [
                    'unit' => $normalized,
                    'result' => 'failed',
                    'error' => mb_strimwidth($e->getMessage(), 0, 500),
                ]);
            }
        } finally {
            if (! $this->shouldQueueManageRemoteTasks()) {
                $this->clearSystemdActionBusyState();
            }
        }
    }

    public function bulkSystemdRestart(): void
    {
        $this->runBulkSystemd('restart');
    }

    public function bulkSystemdStop(): void
    {
        $this->runBulkSystemd('stop');
    }

    /**
     * @param  'restart'|'stop'  $action
     */
    protected function runBulkSystemd(string $action): void
    {
        $units = array_values(array_unique($this->systemdSelectedList));
        if ($units === []) {
            $this->toastError(__('Select at least one service.'));

            return;
        }
        $catalog = app(ServerSystemdServicesCatalog::class);
        $normalized = [];
        foreach ($units as $u) {
            try {
                $normalized[] = $catalog->assertAllowedOnServer($this->server->fresh(), $u);
            } catch (\InvalidArgumentException $e) {
                $this->toastError($e->getMessage());

                return;
            }
        }
        $normalized = array_unique($normalized);
        foreach ($normalized as $u) {
            if ($catalog->isUnitStatusOnlyForServer($this->server->fresh(), $u)) {
                $this->toastError(__('One or more selected units are status-only and cannot be changed from here.'));

                return;
            }
        }
        $script = implode("\n", array_map(
            fn (string $u) => $this->systemdActionBash($u, $action)."\n",
            $normalized
        ))."exit 0\n";

        $this->authorize('update', $this->server);
        $this->remote_error = null;

        if ($this->systemdDeployerWorkspaceBlocked()) {
            $this->setSystemdRemoteError(__('Deployers cannot control services on servers.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->setSystemdRemoteError(__('Provisioning and SSH must be ready before running actions.'));

            return;
        }

        $this->systemdPendingKind = 'action';
        $this->systemdBulkBusy = true;
        $bulkLabel = trans_choice(':count selected unit|:count selected units', count($normalized), ['count' => count($normalized)]);
        $this->startSystemdActionBanner('bulk-'.$action, (string) $bulkLabel);
        foreach ($normalized as $unit) {
            ServerSystemdServiceState::query()
                ->where('server_id', $this->server->id)
                ->where('unit', $unit)
                ->update([
                    'pending_action' => $action,
                    'pending_action_at' => now(),
                ]);
            $this->patchSystemdInventoryPendingAction($unit, $action);
        }
        $timeout = max(60, count($normalized) * (int) config('server_services.systemd_action_timeout', 180));

        try {
            $server = $this->server->fresh();
            $flash = __('Bulk service action finished.');

            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedSystemdScript(
                    $server,
                    'services-systemd-bulk:'.$action,
                    $script,
                    $timeout,
                    $flash,
                    true,
                );
                $this->systemdActionBannerStatus = 'queued';

                return;
            }

            $out = $this->runManageInlineBash(
                $server,
                'services-systemd-bulk:'.$action,
                $script,
                static function (string $type, string $buffer): void {},
                $timeout,
            );
            $this->appendSystemdActionBannerOutput(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
            $this->finishSystemdActionBanner('completed');
            $this->toastSuccess($flash);
            $this->clearPendingActionAndRehydrate();
            if ($server->organization) {
                audit_log($server->organization, auth()->user(), 'server.service.bulk_'.$action, $server, null, [
                    'units' => $normalized,
                    'count' => count($normalized),
                    'result' => 'success',
                ]);
            }
            if ((bool) config('server_services.systemd_inventory_job_enabled', true)) {
                SyncServerSystemdServicesJob::dispatch($this->server->id);
            }
        } catch (\Throwable $e) {
            $this->finishSystemdActionBanner('failed', $e->getMessage());
            $this->setSystemdRemoteError($e->getMessage());
            $this->clearPendingActionAndRehydrate();
            if ($this->server->organization) {
                audit_log($this->server->organization, auth()->user(), 'server.service.bulk_'.$action, $this->server, null, [
                    'units' => $normalized,
                    'count' => count($normalized),
                    'result' => 'failed',
                    'error' => mb_strimwidth($e->getMessage(), 0, 500),
                ]);
            }
        } finally {
            if (! $this->shouldQueueManageRemoteTasks()) {
                $this->clearSystemdActionBusyState();
            }
        }
    }

    public function addCustomSystemdUnit(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change custom services.'));

            return;
        }

        try {
            $normalized = app(ServerSystemdServicesCatalog::class)->validateAndNormalizeCustomUnit($this->newCustomSystemdUnit);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $meta = $this->server->meta ?? [];
        $list = $meta['custom_systemd_services'] ?? [];
        if (! is_array($list)) {
            $list = [];
        }
        $strings = [];
        foreach ($list as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }
        if (in_array($normalized, $strings, true)) {
            $this->toastError(__('That unit is already listed.'));

            return;
        }
        $strings[] = $normalized;
        $meta['custom_systemd_services'] = array_values(array_unique($strings));
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->newCustomSystemdUnit = '';
        if ((bool) config('server_services.systemd_inventory_job_enabled', true)) {
            SyncServerSystemdServicesJob::dispatch($this->server->id);
        }
        $this->toastSuccess(__('Custom unit saved. A background sync will refresh the list when the worker runs.'));
    }

    public function removeCustomSystemdUnit(string $unit): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change custom services.'));

            return;
        }

        try {
            $normalized = app(ServerSystemdServicesCatalog::class)->validateAndNormalizeCustomUnit($unit);
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $meta = $this->server->meta ?? [];
        $list = $meta['custom_systemd_services'] ?? [];
        if (! is_array($list)) {
            $list = [];
        }
        $strings = [];
        foreach ($list as $item) {
            if (is_string($item) && $item !== '' && $this->normalizeUnitStatic($item) !== $normalized) {
                $strings[] = $item;
            }
        }
        $meta['custom_systemd_services'] = array_values($strings);
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        if ((bool) config('server_services.systemd_inventory_job_enabled', true)) {
            SyncServerSystemdServicesJob::dispatch($this->server->id);
        }
        $this->toastSuccess(__('Custom unit removed. A background sync will refresh the list when the worker runs.'));
    }

    public function isCustomSystemdUnit(string $normalizedUnit): bool
    {
        $meta = $this->server->meta ?? [];
        $list = $meta['custom_systemd_services'] ?? [];
        if (! is_array($list)) {
            return false;
        }
        foreach ($list as $item) {
            if (! is_string($item)) {
                continue;
            }
            if ($this->normalizeUnitStatic($item) === $normalizedUnit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reverb-driven fast path: the bootstrap.js Echo binder dispatches this Livewire event when a
     * `.server.systemd.action.completed` broadcast arrives for the active task id. We just call
     * the existing cache-poll path — the broadcast fires *after* the cache write, so the cache
     * already says finished/failed. Variadic payload mirrors {@see WorkspaceCron::onCronRunFinished}
     * so we accept both `(array)` and `(runId, success, ...)` Livewire dispatch shapes.
     */
    #[On('systemd-action-completed')]
    public function onSystemdActionCompletedBroadcast(mixed ...$payload): void
    {
        $runId = '';
        $first = $payload[0] ?? null;
        if (is_array($first)) {
            $runId = (string) ($first['runId'] ?? $first['run_id'] ?? '');
        } elseif (is_string($first)) {
            $runId = $first;
        }

        if ($runId === '' || $runId !== (string) ($this->systemdRemoteTaskId ?? '')) {
            return;
        }

        $this->syncSystemdRemoteTaskFromCache();
    }

    public function syncSystemdRemoteTaskFromCache(): void
    {
        if ($this->systemdRemoteTaskId === null || $this->systemdRemoteTaskId === '') {
            return;
        }

        $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->systemdRemoteTaskId));
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

        $statusHint = match ($status) {
            'queued' => $stalledQueued
                ? __('Still preparing this task. If it stays stuck, contact your administrator.')
                : __('Task queued…'),
            'running' => __('Running on server…'),
            default => '',
        };

        $err = $payload['error'] ?? null;
        $this->setSystemdRemoteError(is_string($err) && $err !== '' ? $err : null);

        $pendingKind = $this->systemdPendingKind;
        if ($pendingKind === 'status_modal') {
            $this->systemdStatusModalOutput = $out !== ''
                ? $out
                : $statusHint;
            $this->systemdStatusModalLoading = ! in_array($status, ['finished', 'failed'], true);
            if ($this->remote_error !== null) {
                $this->systemdStatusModalError = $this->remote_error;
            }
        }

        if ($pendingKind === 'action' && $this->systemdActionBannerStatus !== '') {
            // Mirror the queued/running cache state into the action banner so the operator
            // sees live progress without an inventory poll.
            $this->systemdActionBannerStatus = match ($status) {
                'queued' => 'queued',
                'running' => 'running',
                default => $this->systemdActionBannerStatus,
            };
            if ($out !== '') {
                $this->systemdActionBannerLines = [];
                $this->appendSystemdActionBannerOutput($out);
            }
        }

        if (! in_array($status, ['finished', 'failed'], true)) {
            return;
        }

        if ($pendingKind === 'status_modal') {
            $this->systemdStatusModalLoading = false;
            if ($status === 'finished') {
                $this->systemdStatusModalOutput = trim($out !== '' ? $out : $this->systemdStatusModalOutput);
                $this->systemdStatusModalError = null;
                $this->remote_error = null;
            } else {
                $this->systemdStatusModalError = $this->remote_error ?? __('Remote command failed.');
            }

            Cache::forget(ServerManageRemoteSshJob::cacheKey($this->systemdRemoteTaskId));
            $this->systemdRemoteTaskId = null;
            $this->systemdPendingKind = null;
            $this->systemdQueueInventoryAfterRemoteTask = null;
            $this->remote_error = null;

            return;
        }

        $shouldSyncInventory = (bool) ($this->systemdQueueInventoryAfterRemoteTask ?? false);

        if ($status === 'finished' && $pendingKind === 'action') {
            $flash = $payload['flash_success'] ?? null;
            if ($out !== '' && $this->systemdActionBannerStatus !== '') {
                $this->systemdActionBannerLines = [];
                $this->appendSystemdActionBannerOutput($out);
            }
            $this->finishSystemdActionBanner('completed');
            $this->toastSuccess(is_string($flash) && $flash !== ''
                ? $flash
                : __('Service action finished.'));
            $this->remote_error = null;
            $this->clearPendingActionAndRehydrate();
            if ($shouldSyncInventory && (bool) config('server_services.systemd_inventory_job_enabled', true)) {
                SyncServerSystemdServicesJob::dispatch($this->server->id);
            }
        } elseif ($status === 'failed' && $pendingKind === 'action') {
            if ($out !== '' && $this->systemdActionBannerStatus !== '') {
                $this->systemdActionBannerLines = [];
                $this->appendSystemdActionBannerOutput($out);
            }
            $this->finishSystemdActionBanner('failed', is_string($err) && $err !== '' ? $err : null);
            $this->clearPendingActionAndRehydrate();
        }

        Cache::forget(ServerManageRemoteSshJob::cacheKey($this->systemdRemoteTaskId));
        $this->systemdRemoteTaskId = null;
        $this->systemdPendingKind = null;
        $this->systemdQueueInventoryAfterRemoteTask = null;
        $this->clearSystemdActionBusyState();
    }

    protected function systemdActionBash(string $normalizedUnit, string $action): string
    {
        $u = escapeshellarg($normalizedUnit);

        return match ($action) {
            'status' => '(systemctl status '.$u.' --no-pager -l 2>&1); exit 0',
            'logs' => '(journalctl --no-pager --output=short-iso -u '.$u.' -n 200 2>&1); exit 0',
            'start', 'stop', 'restart', 'reload', 'disable', 'enable' => '(sudo -n systemctl '.$action.' '.$u.' || systemctl '.$action.' '.$u.') 2>&1',
            default => throw new \InvalidArgumentException,
        };
    }

    protected function dispatchQueuedSystemdScript(
        Server $server,
        string $taskName,
        string $inlineBash,
        ?int $timeoutSeconds,
        ?string $flashSuccess,
        bool $dispatchInventorySyncWhenFinished = true,
    ): void {
        $this->systemdRemoteTaskId = null;
        $this->systemdQueueInventoryAfterRemoteTask = $dispatchInventorySyncWhenFinished;

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

        ServerManageRemoteSshJob::dispatch(
            $server->id,
            $id,
            $taskName,
            $inlineBash,
            $timeoutSeconds ?? (int) config('task-runner.default_timeout', 60),
            $flashSuccess,
            null,
            ServerSystemdActionCompletedBroadcast::class,
        );

        $this->systemdRemoteTaskId = $id;
        $this->remote_error = null;
        $this->toastSuccess(__('SSH task queued. The list will refresh automatically while you stay on this page.'));

        // Tell the front-end Echo binder which task id the operator is currently watching, so
        // the .server.systemd.action.completed broadcast filter accepts only the active run.
        $this->js('window.__dplySystemdActionActiveId = '.json_encode($id).';');
    }

    protected function normalizeUnitStatic(string $name): string
    {
        return app(ServerSystemdServicesCatalog::class)->normalizeUnit($name);
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
}
