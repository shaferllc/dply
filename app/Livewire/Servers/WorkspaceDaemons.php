<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\SupervisorProvisioner;
use App\Services\SshConnection;
use App\Support\SupervisorEnvFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceDaemons extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public string $new_sv_slug = '';

    public string $new_sv_type = 'queue';

    public string $new_sv_command = 'php artisan queue:work --sleep=3 --tries=3';

    public string $new_sv_directory = '';

    public string $new_sv_user = 'www-data';

    public int $new_sv_numprocs = 1;

    public string $new_sv_env_lines = '';

    public string $new_sv_stdout_logfile = '';

    /** @var 'programs'|'preview'|'output'|'drift'|'logs'|'inspect' */
    public string $daemons_workspace_tab = 'programs';

    public string $last_supervisor_sync_output = '';

    public ?string $inspect_supervisor_body = null;

    public string $preview_sync_output = '';

    public string $drift_output = '';

    public string $log_tail_body = '';

    public ?string $log_tail_program_id = null;

    /** null = not checked yet (wire:init), true/false from dpkg on server */
    public ?bool $supervisor_installed = null;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->new_sv_directory = '/var/www/app/current';
        $this->supervisor_installed = match ($server->supervisor_package_status) {
            Server::SUPERVISOR_PACKAGE_INSTALLED => true,
            Server::SUPERVISOR_PACKAGE_MISSING => false,
            default => null,
        };
    }

    public function applySupervisorPreset(string $preset): void
    {
        $this->authorize('update', $this->server);
        match ($preset) {
            'laravel-queue' => $this->applySupervisorPresetValues(
                'laravel-queue',
                'queue',
                'php artisan queue:work --sleep=3 --tries=3 --max-time=3600',
                '/var/www/app/current'
            ),
            'laravel-horizon' => $this->applySupervisorPresetValues(
                'laravel-horizon',
                'horizon',
                'php artisan horizon',
                '/var/www/app/current'
            ),
            'reverb' => $this->applySupervisorPresetValues(
                'laravel-reverb',
                'custom',
                'php artisan reverb:start',
                '/var/www/app/current'
            ),
            'laravel-schedule' => $this->applySupervisorPresetValues(
                'laravel-schedule',
                'custom',
                'php artisan schedule:work',
                '/var/www/app/current'
            ),
            'nodejs' => $this->applySupervisorPresetValues(
                'nodejs-app',
                'custom',
                'node server.js',
                '/var/www/app/current'
            ),
            'sidekiq' => $this->applySupervisorPresetValues(
                'sidekiq',
                'custom',
                'bundle exec sidekiq -C config/sidekiq.yml',
                '/var/www/app/current'
            ),
            default => null,
        };
        $this->flash_success = __('Preset loaded — adjust directory if needed, then add the program.');
        $this->flash_error = null;
    }

    protected function applySupervisorPresetValues(string $slug, string $type, string $command, string $directory): void
    {
        $this->new_sv_slug = $slug;
        $this->new_sv_type = $type;
        $this->new_sv_command = $command;
        $this->new_sv_directory = $directory;
        $this->new_sv_user = str_contains($slug, 'sidekiq') ? 'deploy' : 'www-data';
        $this->new_sv_numprocs = 1;
    }

    public function addSupervisorProgram(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_sv_slug' => 'required|string|max:64|regex:/^[a-z0-9\-]+$/',
            'new_sv_type' => 'required|string|max:32',
            'new_sv_command' => 'required|string|max:2000',
            'new_sv_directory' => 'required|string|max:512',
            'new_sv_user' => 'required|string|max:64',
            'new_sv_numprocs' => 'required|integer|min:1|max:32',
            'new_sv_env_lines' => 'nullable|string|max:12000',
            'new_sv_stdout_logfile' => 'nullable|string|max:512',
        ]);
        $env = SupervisorEnvFormatter::parseLines($this->new_sv_env_lines);
        SupervisorProgram::query()->create([
            'server_id' => $this->server->id,
            'site_id' => null,
            'slug' => $this->new_sv_slug,
            'program_type' => $this->new_sv_type,
            'command' => $this->new_sv_command,
            'directory' => $this->new_sv_directory,
            'user' => $this->new_sv_user,
            'numprocs' => $this->new_sv_numprocs,
            'is_active' => true,
            'env_vars' => $env === [] ? null : $env,
            'stdout_logfile' => $this->new_sv_stdout_logfile !== '' ? $this->new_sv_stdout_logfile : null,
        ]);
        $this->new_sv_slug = '';
        $this->new_sv_env_lines = '';
        $this->new_sv_stdout_logfile = '';
        $msg = __('Program saved. Sync Supervisor on the server to apply changes.');
        if ($this->new_sv_type === 'horizon' && $this->new_sv_numprocs > 1) {
            $msg .= ' '.__('Note: Horizon usually runs with numprocs 1; scaling is typically done inside Horizon.');
        }
        if ($this->new_sv_type === 'queue' && $this->new_sv_numprocs > 4) {
            $msg .= ' '.__('Note: Many queue workers are often better as separate programs or Horizon.');
        }
        $this->flash_success = $msg;
        $this->flash_error = null;
    }

    public function deleteSupervisorProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
        if ($prog) {
            $provisioner->deleteConfigFile($this->server, $prog->id);
            $prog->delete();
        }
        $this->flash_success = __('Removed. Sync Supervisor to reload on the server.');
        $this->flash_error = null;
    }

    public function refreshSupervisorInstallStatus(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->server->refresh();
        if ($this->server->supervisor_package_status !== null) {
            $this->supervisor_installed = $this->server->supervisor_package_status === Server::SUPERVISOR_PACKAGE_INSTALLED;

            return;
        }
        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->supervisor_installed = false;

            return;
        }
        $installed = $provisioner->isSupervisorPackageInstalled($this->server->fresh());
        $this->server->update([
            'supervisor_package_status' => $installed ? Server::SUPERVISOR_PACKAGE_INSTALLED : Server::SUPERVISOR_PACKAGE_MISSING,
        ]);
        $this->supervisor_installed = $installed;
    }

    public function installSupervisorPackage(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $out = $provisioner->installSupervisorPackage($this->server->fresh());
            $this->last_supervisor_sync_output = trim($out);
            $this->server->refresh();
            $this->supervisor_installed = $provisioner->isSupervisorPackageInstalled($this->server->fresh());
            if ($this->supervisor_installed) {
                $this->server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);
            } else {
                $this->server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_MISSING]);
            }
            $this->flash_success = __('Supervisor was installed on the server. You can add programs and sync.');
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
            $this->supervisor_installed = false;
        }
    }

    public function syncSupervisor(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $this->server->refresh();
            $out = $provisioner->sync($this->server);
            $trimmed = trim($out);
            $this->last_supervisor_sync_output = $trimmed;
            $this->flash_success = __('Supervisor sync: :snippet', ['snippet' => Str::limit($trimmed, 800)]);
            $this->supervisor_installed = true;
            $this->server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function loadPreviewSync(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->flash_error = null;
        try {
            $this->preview_sync_output = $provisioner->previewSyncDiff($this->server->fresh());
        } catch (\Throwable $e) {
            $this->preview_sync_output = '';
            $this->flash_error = $e->getMessage();
        }
    }

    public function loadDrift(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->flash_error = null;
        try {
            $this->drift_output = $provisioner->driftReport($this->server->fresh());
        } catch (\Throwable $e) {
            $this->drift_output = '';
            $this->flash_error = $e->getMessage();
        }
    }

    public function tailProgramLog(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->flash_error = null;
        $this->validate([
            'log_tail_program_id' => 'required|string',
        ]);
        $prog = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->log_tail_program_id)
            ->first();
        if (! $prog) {
            $this->flash_error = __('Program not found.');

            return;
        }
        try {
            $this->log_tail_body = $provisioner->tailProgramStdoutLog($this->server->fresh(), $prog, 200);
        } catch (\Throwable $e) {
            $this->log_tail_body = '';
            $this->flash_error = $e->getMessage();
        }
    }

    public function restartOneProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $out = $provisioner->restartProgramGroup($this->server->fresh(), $id);
            $this->flash_success = __('Restart: :out', ['out' => Str::limit($out, 500)]);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function restartAllPrograms(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $out = $provisioner->restartAllManagedPrograms($this->server->fresh());
            $this->flash_success = __('Restart all: :out', ['out' => Str::limit($out, 1200)]);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function loadSupervisorInspect(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->flash_error = null;
        $this->inspect_supervisor_body = null;

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            $this->flash_error = __('Server must be ready with SSH before inspecting Supervisor.');

            return;
        }

        $this->server->refresh();
        if ($this->server->supervisor_package_status === Server::SUPERVISOR_PACKAGE_MISSING) {
            $this->flash_error = __('Supervisor is not installed on this server yet. Use Install Supervisor at the top of this page.');

            return;
        }
        if ($this->server->supervisor_package_status !== Server::SUPERVISOR_PACKAGE_INSTALLED
            && ! $provisioner->isSupervisorPackageInstalled($this->server->fresh())) {
            $this->flash_error = __('Supervisor is not installed on this server yet. Use Install Supervisor at the top of this page.');

            return;
        }
        if ($this->server->supervisor_package_status !== Server::SUPERVISOR_PACKAGE_INSTALLED) {
            $this->server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);
        }

        try {
            $ssh = new SshConnection($this->server);
            $out = $ssh->exec('supervisorctl status 2>&1', 120);
            $ssh->disconnect();
            $this->inspect_supervisor_body = trim($out);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['supervisorPrograms']);

        return view('livewire.servers.workspace-daemons', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
