<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Services\Servers\SupervisorProvisioner;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDaemonLogs
{


    public function openProgramLogs(string $programId, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);

        $program = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->whereKey($programId)
            ->first();

        if ($program === null) {
            $this->toastError(__('Program not found.'));

            return;
        }

        $this->log_tail_program_id = $program->id;
        $this->log_tail_slug = $program->slug;
        $this->log_which = 'stdout';
        $this->log_follow_enabled = false;
        $this->log_tail_body = '';
        $this->dispatch('open-modal', 'daemon-program-logs-modal');
        $this->tailProgramLog($provisioner);
    }

    public function closeProgramLogsModal(): void
    {
        $this->log_follow_enabled = false;
        $this->dispatch('close-modal', 'daemon-program-logs-modal');
    }

    public function tailProgramLog(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->validate([
            'log_tail_program_id' => 'required|string',
            'log_which' => 'required|in:stdout,stderr',
        ]);
        $prog = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->log_tail_program_id)
            ->first();
        if (! $prog) {
            $this->toastError(__('Program not found.'));

            return;
        }
        $this->log_tail_slug = $prog->slug;
        try {
            $this->log_tail_body = $this->log_which === 'stderr'
                ? $provisioner->tailProgramStderrLog($this->server->fresh(), $prog, 200)
                : $provisioner->tailProgramStdoutLog($this->server->fresh(), $prog, 200);
        } catch (\Throwable $e) {
            $this->log_tail_body = '';
            $this->toastError($e->getMessage());
        }
    }

    public function updatedLogWhich(): void
    {
        if ($this->log_tail_program_id === null) {
            return;
        }

        $this->tailProgramLog(app(SupervisorProvisioner::class));
    }

    public function refreshLogTailFollow(SupervisorProvisioner $provisioner): void
    {
        if (! $this->log_follow_enabled || $this->log_tail_program_id === null) {
            return;
        }
        $this->tailProgramLog($provisioner);
    }

    public function loadSupervisorInspect(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->inspect_supervisor_body = null;

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->toastError(__('Server must be ready with SSH before inspecting Supervisor.'));

            return;
        }

        $this->server->refresh();
        if ($this->server->supervisor_package_status === Server::SUPERVISOR_PACKAGE_MISSING) {
            $this->toastError(__('Supervisor is not installed on this server yet. Use Install Supervisor at the top of this page.'));

            return;
        }
        if ($this->server->supervisor_package_status !== Server::SUPERVISOR_PACKAGE_INSTALLED
            && ! $provisioner->isSupervisorPackageInstalled($this->server->fresh())) {
            $this->toastError(__('Supervisor is not installed on this server yet. Use Install Supervisor at the top of this page.'));

            return;
        }
        if ($this->server->supervisor_package_status !== Server::SUPERVISOR_PACKAGE_INSTALLED) {
            $this->server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);
        }

        try {
            $this->inspect_supervisor_body = $provisioner->inspect($this->server->fresh());
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function tailSupervisordDaemonLog(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->supervisord_log_body = null;

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->toastError(__('Server must be ready with SSH before tailing the supervisord log.'));

            return;
        }

        $this->server->refresh();
        if ($this->server->supervisor_package_status === Server::SUPERVISOR_PACKAGE_MISSING) {
            $this->toastError(__('Supervisor is not installed on this server yet. Use Install Supervisor at the top of this page.'));

            return;
        }

        try {
            $body = $provisioner->tailSupervisordDaemonLog($this->server->fresh(), 200);
            $this->supervisord_log_body = $body !== '' ? $body : __('(supervisord log is empty)');
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }
}
