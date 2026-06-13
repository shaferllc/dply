<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RunSupervisorOperationJob;
use App\Models\Server;
use App\Models\Site;
use App\Services\Servers\SupervisorDaemonAudit;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDaemonOperations
{


    public function setDaemonsWorkspaceTab(string $tab): void
    {
        $allowed = ['programs', 'service', 'sync', 'logs', 'inspect', 'activity'];
        $this->daemons_workspace_tab = in_array($tab, $allowed, true) ? $tab : 'programs';
    }

    /**
     * Whether this site can use Dply-managed Supervisor on the VM.
     */
    protected function siteSupportsVmManagedDaemons(Site $site): bool
    {
        return $this->server->hostCapabilities()->supportsSsh()
            && ! $site->usesFunctionsRuntime()
            && ! $site->usesDockerRuntime()
            && ! $site->usesKubernetesRuntime();
    }

    public function updatedDaemonsWorkspaceTab(string $value): void
    {
        if ($value !== 'logs') {
            $this->log_follow_enabled = false;
        }

        if ($value === 'service') {
            $this->loadSupervisorServiceStates(app(SupervisorProvisioner::class));
        }
    }

    public function loadSupervisorServiceStates(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);

        if ($this->supervisor_installed !== true) {
            return;
        }

        try {
            $server = $this->server->fresh();
            $activeOut = $provisioner->manageSupervisorService($server, 'is-active');
            $this->supervisor_service_state = (str_contains(strtolower(trim($activeOut)), 'active') && ! str_contains(strtolower(trim($activeOut)), 'inactive'))
                ? 'active'
                : 'inactive';

            $enabledOut = $provisioner->manageSupervisorService($server, 'is-enabled');
            $this->supervisor_boot_state = str_contains(strtolower(trim($enabledOut)), 'enabled') ? 'enabled' : 'disabled';
        } catch (\Throwable) {
            // Non-fatal — buttons stay in default state
        }
    }

    public function pollDaemonOperation(): void
    {
        if ($this->daemon_op_run_id === null || ! $this->daemon_op_busy) {
            return;
        }

        $payload = Cache::get(RunSupervisorOperationJob::cacheKey($this->daemon_op_run_id));
        if (! is_array($payload)) {
            return;
        }

        $status = (string) ($payload['status'] ?? 'running');
        $output = (string) ($payload['output'] ?? '');

        if (! in_array($status, ['done', 'failed'], true)) {
            return;
        }

        $lines = array_values(array_filter(explode("\n", $output)));
        $this->daemon_op_busy = false;
        $this->daemon_op_run_id = null;

        if ($status === 'done') {
            $this->last_supervisor_sync_output = $output;
            $this->emitPanelEvent(__('Operation completed.'), $lines, 'completed');
            // Install/sync may have changed the package state — re-check so the
            // "not installed" banner clears and the tabs become usable.
            $this->server->refresh();
            $this->supervisor_installed = $this->server->supervisor_package_status === Server::SUPERVISOR_PACKAGE_INSTALLED;
            $this->refreshProgramStatusMap();
        } else {
            $this->emitPanelEvent($output ?: __('Operation failed.'), $lines, 'failed');
        }
    }

    protected function dispatchDaemonOperation(string $operation): void
    {
        $this->authorize('update', $this->server);
        $runId = (string) Str::ulid();
        $this->daemon_op_run_id = $runId;
        $this->daemon_op_busy = true;

        $label = match ($operation) {
            'sync' => __('Syncing Supervisor config…'),
            'install' => __('Installing Supervisor…'),
            'restart_all' => __('Restarting all programs…'),
            default => __('Running operation…'),
        };
        $this->emitPanelEvent($label, [], 'running');

        RunSupervisorOperationJob::dispatch($this->server->id, $operation, $runId);
    }

    public function supervisorServiceAction(SupervisorProvisioner $provisioner, string $action): void
    {
        $readOnly = in_array($action, ['status', 'is-active', 'is-enabled'], true);
        $this->authorize($readOnly ? 'view' : 'update', $this->server);
        try {
            $out = $provisioner->manageSupervisorService($this->server->fresh(), $action);
            $this->supervisor_service_output = $out;

            // Infer state so Start/Stop buttons can be shown contextually.
            match ($action) {
                'start' => $this->supervisor_service_state = 'active',
                'stop' => $this->supervisor_service_state = 'inactive',
                'is-active' => $this->supervisor_service_state = (str_contains(strtolower(trim($out)), 'active') && ! str_contains(strtolower(trim($out)), 'inactive')) ? 'active' : 'inactive',
                'status' => $this->supervisor_service_state = str_contains($out, 'Active: active') ? 'active' : (str_contains($out, 'Active: inactive') || str_contains($out, 'Active: failed') ? 'inactive' : $this->supervisor_service_state),
                'enable' => $this->supervisor_boot_state = 'enabled',
                'disable' => $this->supervisor_boot_state = 'disabled',
                'is-enabled' => $this->supervisor_boot_state = str_contains(strtolower(trim($out)), 'enabled') ? 'enabled' : 'disabled',
                default => null,
            };

            if (! $readOnly) {
                SupervisorDaemonAudit::log($this->server->fresh(), null, 'supervisor_service_'.$action, [
                    'output' => Str::limit($out, 2000),
                ]);
                $this->toastSuccess(__('Service command completed. Review output below.'));
            }
        } catch (\Throwable $e) {
            $this->supervisor_service_output = '';
            $this->toastError($e->getMessage());
        }
    }
}
