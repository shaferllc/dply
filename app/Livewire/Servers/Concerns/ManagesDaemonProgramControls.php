<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\SupervisorProgram;
use App\Services\Servers\SupervisorDaemonAudit;
use App\Services\Servers\SupervisorProvisioner;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDaemonProgramControls
{


    public function loadProgramStatuses(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->refreshProgramStatusMap($provisioner);
    }

    protected function refreshProgramStatusMap(?SupervisorProvisioner $provisioner = null): void
    {
        $provisioner ??= app(SupervisorProvisioner::class);
        $this->program_status_map = [];
        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            return;
        }
        try {
            $out = $provisioner->fetchSupervisorctlStatus($this->server->fresh());
            $this->program_status_map = $provisioner->parseManagedProgramStatuses($this->server->fresh(), $out);
        } catch (\Throwable) {
            // Non-fatal — statuses stay empty
        }
    }

    public function runPreflightPathCheck(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        try {
            $result = $provisioner->preflightPathCheck($this->server->fresh());
            $this->preflight_messages = $result['messages'];
            $this->toastSuccess($result['ok']
                ? __('Working directories look OK on the server.')
                : __('Some paths failed checks — see messages below.'));
        } catch (\Throwable $e) {
            $this->preflight_messages = [];
            $this->toastError($e->getMessage());
        }
    }

    public function stopOneProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        try {
            $out = $provisioner->stopProgramGroup($this->server->fresh(), $id);
            if ($provisioner->indicatesUnregisteredProgram($out)) {
                $this->toastUnregisteredProgram();

                return;
            }
            $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
            SupervisorDaemonAudit::log($this->server->fresh(), $prog, 'stop_one', ['output' => Str::limit($out, 500)]);
            $this->toastSuccess(__('Stop: :out', ['out' => Str::limit($out, 500)]));
        } catch (\Throwable $e) {
            if ($provisioner->indicatesUnregisteredProgram($e->getMessage())) {
                $this->toastUnregisteredProgram();

                return;
            }
            $this->toastError($e->getMessage());
        }
    }

    public function startOneProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        try {
            $out = $provisioner->startProgramGroup($this->server->fresh(), $id);
            if ($provisioner->indicatesUnregisteredProgram($out)) {
                $this->toastUnregisteredProgram();

                return;
            }
            $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
            SupervisorDaemonAudit::log($this->server->fresh(), $prog, 'start_one', ['output' => Str::limit($out, 500)]);
            $this->toastSuccess(__('Start: :out', ['out' => Str::limit($out, 500)]));
        } catch (\Throwable $e) {
            if ($provisioner->indicatesUnregisteredProgram($e->getMessage())) {
                $this->toastUnregisteredProgram();

                return;
            }
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Re-register a single program that is missing from supervisorctl ("NOT
     * REPORTED"): (re)write its conf on the box and run reread/update, then
     * re-probe statuses so the row reflects the real state. Remediation for the
     * "no such process" failure on Start/Stop.
     */
    public function syncOneProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        try {
            $result = $provisioner->syncProgramResult($this->server->fresh(), $id);
            $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
            SupervisorDaemonAudit::log($this->server->fresh(), $prog, 'sync_one', [
                'output' => Str::limit($result['log'], 800),
                'state' => $result['state'],
                'ok' => $result['ok'],
            ]);
            // Re-probe first so the row's state badge and Start/Stop gating update.
            $this->loadProgramStatuses($provisioner);
            if ($result['ok']) {
                $this->toastSuccess($result['message']);
            } else {
                $this->toastError($result['message']);
            }
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    protected function toastUnregisteredProgram(): void
    {
        $this->toastError(__('This program isn’t registered with Supervisor on the server (no such process). Use Sync to re-apply its config to the host, then try again.'));
    }

    public function loadPreviewSync(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        try {
            $this->preview_sync_output = $provisioner->previewSyncDiff($this->server->fresh());
        } catch (\Throwable $e) {
            $this->preview_sync_output = '';
            $this->toastError($e->getMessage());
        }
    }

    public function loadDrift(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        try {
            $this->drift_output = $provisioner->driftReport($this->server->fresh());
        } catch (\Throwable $e) {
            $this->drift_output = '';
            $this->toastError($e->getMessage());
        }
    }

    public function restartOneProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        try {
            $out = $provisioner->restartProgramGroup($this->server->fresh(), $id);
            if ($provisioner->indicatesUnregisteredProgram($out)) {
                $this->toastUnregisteredProgram();

                return;
            }
            $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
            SupervisorDaemonAudit::log($this->server->fresh(), $prog, 'restart_one', ['output' => Str::limit($out, 500)]);
            $this->toastSuccess(__('Restart: :out', ['out' => Str::limit($out, 500)]));
        } catch (\Throwable $e) {
            if ($provisioner->indicatesUnregisteredProgram($e->getMessage())) {
                $this->toastUnregisteredProgram();

                return;
            }
            $this->toastError($e->getMessage());
        }
    }

    public function restartAllPrograms(bool $override = false): void
    {
        $this->authorize('update', $this->server);
        if (! $this->disruptiveActionAllowed(__('Restart all programs'), $override)) {
            return;
        }
        SupervisorDaemonAudit::log($this->server->fresh(), null, 'restart_all_queued', []);
        $this->dispatchDaemonOperation('restart_all');
    }
}
