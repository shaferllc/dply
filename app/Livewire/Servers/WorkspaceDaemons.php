<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\OrganizationSupervisorProgramTemplate;
use App\Models\Server;
use App\Models\SupervisorProgram;
use App\Models\SupervisorProgramAuditLog;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\SupervisorDaemonAudit;
use App\Services\Servers\SupervisorProvisioner;
use App\Support\SupervisorEnvFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    public ?string $new_sv_site_id = null;

    public ?int $new_sv_priority = null;

    public ?int $new_sv_startsecs = null;

    public ?int $new_sv_stopwaitsecs = null;

    public string $new_sv_autorestart = '';

    public bool $new_sv_redirect_stderr = true;

    public string $new_sv_stderr_logfile = '';

    /** @var 'programs'|'service'|'sync'|'logs'|'inspect'|'activity' */
    public string $daemons_workspace_tab = 'programs';

    /** @var 'preview'|'drift'|'output' */
    public string $daemons_sync_subtab = 'preview';

    public string $supervisor_service_output = '';

    public string $last_supervisor_sync_output = '';

    public ?string $inspect_supervisor_body = null;

    public string $preview_sync_output = '';

    public string $drift_output = '';

    public string $log_tail_body = '';

    public ?string $log_tail_program_id = null;

    public string $log_which = 'stdout';

    public bool $log_follow_enabled = false;

    /** @var array<string, array{state: string, lines: array<int, string>}> */
    public array $program_status_map = [];

    /** @var array<int, string> */
    public array $preflight_messages = [];

    public ?string $editing_program_id = null;

    public string $template_save_name = '';

    public string $copy_source_program_id = '';

    public string $copy_target_server_id = '';

    public string $copy_new_slug = '';

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

    public function updatedDaemonsWorkspaceTab(string $value): void
    {
        if ($value !== 'logs') {
            $this->log_follow_enabled = false;
        }
    }

    public function supervisorServiceAction(SupervisorProvisioner $provisioner, string $action): void
    {
        $readOnly = in_array($action, ['status', 'is-active', 'is-enabled'], true);
        $this->authorize($readOnly ? 'view' : 'update', $this->server);
        $this->flash_error = null;
        if (! $readOnly) {
            $this->flash_success = null;
        }
        try {
            $out = $provisioner->manageSupervisorService($this->server->fresh(), $action);
            $this->supervisor_service_output = $out;
            if (! $readOnly) {
                SupervisorDaemonAudit::log($this->server->fresh(), null, 'supervisor_service_'.$action, [
                    'output' => Str::limit($out, 2000),
                ]);
                $this->flash_success = __('Service command completed. Review output below.');
            }
        } catch (\Throwable $e) {
            $this->supervisor_service_output = '';
            $this->flash_error = $e->getMessage();
        }
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
        $this->resetExpertFormFields();
    }

    protected function resetExpertFormFields(): void
    {
        $this->new_sv_priority = null;
        $this->new_sv_startsecs = null;
        $this->new_sv_stopwaitsecs = null;
        $this->new_sv_autorestart = '';
        $this->new_sv_redirect_stderr = true;
        $this->new_sv_stderr_logfile = '';
    }

    protected function rulesForProgramForm(): array
    {
        return [
            'new_sv_slug' => 'required|string|max:64|regex:/^[a-z0-9\-]+$/',
            'new_sv_type' => 'required|string|max:32',
            'new_sv_command' => 'required|string|max:2000',
            'new_sv_directory' => 'required|string|max:512',
            'new_sv_user' => 'required|string|max:64',
            'new_sv_numprocs' => 'required|integer|min:1|max:32',
            'new_sv_env_lines' => 'nullable|string|max:12000',
            'new_sv_stdout_logfile' => 'nullable|string|max:512',
            'new_sv_site_id' => [
                'nullable',
                'ulid',
                Rule::exists('sites', 'id')->where(fn ($q) => $q->where('server_id', $this->server->id)),
            ],
            'new_sv_priority' => 'nullable|integer|min:1|max:999',
            'new_sv_startsecs' => 'nullable|integer|min:0|max:3600',
            'new_sv_stopwaitsecs' => 'nullable|integer|min:0|max:86400',
            'new_sv_autorestart' => 'nullable|string|max:32',
            'new_sv_redirect_stderr' => 'boolean',
            'new_sv_stderr_logfile' => 'nullable|string|max:512',
        ];
    }

    protected function programAttributesFromForm(): array
    {
        $env = SupervisorEnvFormatter::parseLines($this->new_sv_env_lines);

        return [
            'site_id' => $this->new_sv_site_id !== null && $this->new_sv_site_id !== '' ? $this->new_sv_site_id : null,
            'slug' => $this->new_sv_slug,
            'program_type' => $this->new_sv_type,
            'command' => $this->new_sv_command,
            'directory' => $this->new_sv_directory,
            'user' => $this->new_sv_user,
            'numprocs' => $this->new_sv_numprocs,
            'is_active' => true,
            'env_vars' => $env === [] ? null : $env,
            'stdout_logfile' => $this->new_sv_stdout_logfile !== '' ? $this->new_sv_stdout_logfile : null,
            'priority' => $this->new_sv_priority,
            'startsecs' => $this->new_sv_startsecs,
            'stopwaitsecs' => $this->new_sv_stopwaitsecs,
            'autorestart' => $this->new_sv_autorestart !== '' ? $this->new_sv_autorestart : null,
            'redirect_stderr' => $this->new_sv_redirect_stderr,
            'stderr_logfile' => $this->new_sv_stderr_logfile !== '' ? $this->new_sv_stderr_logfile : null,
        ];
    }

    public function saveSupervisorProgram(): void
    {
        $this->authorize('update', $this->server);
        $this->validate($this->rulesForProgramForm());

        $attrs = $this->programAttributesFromForm();

        if ($this->editing_program_id !== null) {
            $prog = SupervisorProgram::query()
                ->where('server_id', $this->server->id)
                ->whereKey($this->editing_program_id)
                ->first();
            if (! $prog) {
                $this->flash_error = __('Program not found.');
                $this->cancelEditProgram();

                return;
            }
            $prog->update($attrs);
            $this->flash_success = __('Program updated. Sync Supervisor on the server to apply changes.');
            $this->cancelEditProgram();
        } else {
            $type = $this->new_sv_type;
            $nproc = $this->new_sv_numprocs;
            SupervisorProgram::query()->create(array_merge($attrs, [
                'server_id' => $this->server->id,
            ]));
            $this->cancelEditProgram();
            $this->resetDefaultsForNewProgramForm();
            $msg = __('Program saved. Sync Supervisor on the server to apply changes.');
            if ($type === 'horizon' && $nproc > 1) {
                $msg .= ' '.__('Note: Horizon usually runs with numprocs 1; scaling is typically done inside Horizon.');
            }
            if ($type === 'queue' && $nproc > 4) {
                $msg .= ' '.__('Note: Many queue workers are often better as separate programs or Horizon.');
            }
            $this->flash_success = $msg;
        }

        $this->flash_error = null;
    }

    protected function resetDefaultsForNewProgramForm(): void
    {
        $this->new_sv_type = 'queue';
        $this->new_sv_command = 'php artisan queue:work --sleep=3 --tries=3';
        $this->new_sv_directory = '/var/www/app/current';
        $this->new_sv_user = 'www-data';
        $this->new_sv_numprocs = 1;
    }

    public function beginEditProgram(string $id): void
    {
        $this->authorize('update', $this->server);
        $prog = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->whereKey($id)
            ->first();
        if (! $prog) {
            return;
        }
        $this->editing_program_id = $prog->id;
        $this->new_sv_slug = $prog->slug;
        $this->new_sv_type = $prog->program_type;
        $this->new_sv_command = $prog->command;
        $this->new_sv_directory = $prog->directory;
        $this->new_sv_user = $prog->user;
        $this->new_sv_numprocs = (int) $prog->numprocs;
        $this->new_sv_site_id = $prog->site_id;
        $this->new_sv_stdout_logfile = $prog->stdout_logfile ?? '';
        $this->new_sv_stderr_logfile = $prog->stderr_logfile ?? '';
        $this->new_sv_priority = $prog->priority;
        $this->new_sv_startsecs = $prog->startsecs;
        $this->new_sv_stopwaitsecs = $prog->stopwaitsecs;
        $this->new_sv_autorestart = $prog->autorestart ?? '';
        $this->new_sv_redirect_stderr = $prog->redirect_stderr ?? true;
        $env = is_array($prog->env_vars) ? $prog->env_vars : [];
        $lines = [];
        foreach ($env as $k => $v) {
            $lines[] = $k.'='.$v;
        }
        $this->new_sv_env_lines = implode("\n", $lines);
        $this->flash_success = __('Editing — change fields and save.');
        $this->flash_error = null;
    }

    public function cancelEditProgram(): void
    {
        $this->editing_program_id = null;
        $this->new_sv_slug = '';
        $this->new_sv_env_lines = '';
        $this->new_sv_stdout_logfile = '';
        $this->new_sv_site_id = null;
        $this->resetExpertFormFields();
    }

    public function saveOrgTemplate(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'template_save_name' => 'required|string|max:160',
            ...$this->rulesForProgramForm(),
        ]);

        $env = SupervisorEnvFormatter::parseLines($this->new_sv_env_lines);
        $base = Str::slug($this->template_save_name) ?: 'template';
        $slug = $base;
        $i = 0;
        while (OrganizationSupervisorProgramTemplate::query()
            ->where('organization_id', $this->server->organization_id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.(++$i);
        }

        $savedTemplateName = $this->template_save_name;

        OrganizationSupervisorProgramTemplate::query()->create([
            'organization_id' => $this->server->organization_id,
            'name' => $savedTemplateName,
            'slug' => $slug,
            'program_type' => $this->new_sv_type,
            'command' => $this->new_sv_command,
            'directory' => $this->new_sv_directory,
            'user' => $this->new_sv_user,
            'numprocs' => $this->new_sv_numprocs,
            'env_vars' => $env === [] ? null : $env,
            'stdout_logfile' => $this->new_sv_stdout_logfile !== '' ? $this->new_sv_stdout_logfile : null,
            'stderr_logfile' => $this->new_sv_stderr_logfile !== '' ? $this->new_sv_stderr_logfile : null,
            'priority' => $this->new_sv_priority,
            'startsecs' => $this->new_sv_startsecs,
            'stopwaitsecs' => $this->new_sv_stopwaitsecs,
            'autorestart' => $this->new_sv_autorestart !== '' ? $this->new_sv_autorestart : null,
            'redirect_stderr' => $this->new_sv_redirect_stderr,
            'description' => null,
        ]);

        SupervisorDaemonAudit::log($this->server->fresh(), null, 'template_saved', ['name' => $savedTemplateName, 'slug' => $slug]);
        $this->template_save_name = '';
        $this->flash_success = __('Organization template saved. Use “Apply” on a template to load it into the form.');
        $this->flash_error = null;
    }

    public function applyOrgTemplate(string $templateId): void
    {
        $this->authorize('update', $this->server);
        $tpl = OrganizationSupervisorProgramTemplate::query()
            ->where('organization_id', $this->server->organization_id)
            ->whereKey($templateId)
            ->first();
        if (! $tpl) {
            return;
        }
        $this->new_sv_slug = $tpl->slug;
        $this->new_sv_type = $tpl->program_type;
        $this->new_sv_command = $tpl->command;
        $this->new_sv_directory = $tpl->directory;
        $this->new_sv_user = $tpl->user;
        $this->new_sv_numprocs = (int) $tpl->numprocs;
        $this->new_sv_stdout_logfile = $tpl->stdout_logfile ?? '';
        $this->new_sv_stderr_logfile = $tpl->stderr_logfile ?? '';
        $this->new_sv_priority = $tpl->priority;
        $this->new_sv_startsecs = $tpl->startsecs;
        $this->new_sv_stopwaitsecs = $tpl->stopwaitsecs;
        $this->new_sv_autorestart = $tpl->autorestart ?? '';
        $this->new_sv_redirect_stderr = $tpl->redirect_stderr ?? true;
        $env = is_array($tpl->env_vars) ? $tpl->env_vars : [];
        $lines = [];
        foreach ($env as $k => $v) {
            $lines[] = $k.'='.$v;
        }
        $this->new_sv_env_lines = implode("\n", $lines);
        $this->flash_success = __('Template loaded — review slug and site, then add the program.');
        $this->flash_error = null;
    }

    public function deleteOrgTemplate(string $templateId): void
    {
        $this->authorize('update', $this->server);
        OrganizationSupervisorProgramTemplate::query()
            ->where('organization_id', $this->server->organization_id)
            ->whereKey($templateId)
            ->delete();
        $this->flash_success = __('Template removed.');
        $this->flash_error = null;
    }

    public function copyProgramToServer(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'copy_source_program_id' => 'required|ulid',
            'copy_target_server_id' => [
                'required',
                'ulid',
                Rule::exists('servers', 'id')->where(
                    fn ($q) => $q->where('organization_id', $this->server->organization_id)
                ),
            ],
            'copy_new_slug' => 'required|string|max:64|regex:/^[a-z0-9\-]+$/',
        ]);

        if ($this->copy_target_server_id === $this->server->id) {
            $this->flash_error = __('Choose a different server than the current one.');

            return;
        }

        $source = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->copy_source_program_id)
            ->first();
        if (! $source) {
            $this->flash_error = __('Source program not found.');

            return;
        }

        $target = Server::query()
            ->where('organization_id', $this->server->organization_id)
            ->whereKey($this->copy_target_server_id)
            ->first();
        if (! $target) {
            $this->flash_error = __('Target server not found.');

            return;
        }

        if (SupervisorProgram::query()->where('server_id', $target->id)->where('slug', $this->copy_new_slug)->exists()) {
            $this->flash_error = __('A program with that slug already exists on the target server.');

            return;
        }

        SupervisorProgram::query()->create([
            'server_id' => $target->id,
            'site_id' => null,
            'slug' => $this->copy_new_slug,
            'program_type' => $source->program_type,
            'command' => $source->command,
            'directory' => $source->directory,
            'user' => $source->user,
            'numprocs' => $source->numprocs,
            'is_active' => $source->is_active,
            'env_vars' => $source->env_vars,
            'stdout_logfile' => $source->stdout_logfile,
            'stderr_logfile' => $source->stderr_logfile,
            'priority' => $source->priority,
            'startsecs' => $source->startsecs,
            'stopwaitsecs' => $source->stopwaitsecs,
            'autorestart' => $source->autorestart,
            'redirect_stderr' => $source->redirect_stderr ?? true,
        ]);

        SupervisorDaemonAudit::log($this->server->fresh(), $source, 'program_copied_to_server', [
            'target_server_id' => $target->id,
            'new_slug' => $this->copy_new_slug,
        ]);

        $this->copy_source_program_id = '';
        $this->copy_target_server_id = '';
        $this->copy_new_slug = '';
        $this->flash_success = __('Program copied to the target server. Open that server’s Daemons page and sync Supervisor.');
        $this->flash_error = null;
    }

    public function loadProgramStatuses(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->flash_error = null;
        $this->program_status_map = [];
        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            return;
        }
        try {
            $out = $provisioner->fetchSupervisorctlStatus($this->server->fresh());
            $this->program_status_map = $provisioner->parseManagedProgramStatuses($this->server->fresh(), $out);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function runPreflightPathCheck(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->flash_error = null;
        try {
            $result = $provisioner->preflightPathCheck($this->server->fresh());
            $this->preflight_messages = $result['messages'];
            $this->flash_success = $result['ok']
                ? __('Working directories look OK on the server.')
                : __('Some paths failed checks — see messages below.');
        } catch (\Throwable $e) {
            $this->preflight_messages = [];
            $this->flash_error = $e->getMessage();
        }
    }

    public function stopOneProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $out = $provisioner->stopProgramGroup($this->server->fresh(), $id);
            $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
            SupervisorDaemonAudit::log($this->server->fresh(), $prog, 'stop_one', ['output' => Str::limit($out, 500)]);
            $this->flash_success = __('Stop: :out', ['out' => Str::limit($out, 500)]);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function startOneProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $out = $provisioner->startProgramGroup($this->server->fresh(), $id);
            $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
            SupervisorDaemonAudit::log($this->server->fresh(), $prog, 'start_one', ['output' => Str::limit($out, 500)]);
            $this->flash_success = __('Start: :out', ['out' => Str::limit($out, 500)]);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function deleteSupervisorProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
        if ($prog) {
            $provisioner->deleteConfigFile($this->server, $prog->id);
            $prog->delete();
        }
        if ($this->editing_program_id === $id) {
            $this->cancelEditProgram();
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
            SupervisorDaemonAudit::log($this->server->fresh(), null, 'supervisor_sync', ['output' => Str::limit($trimmed, 2000)]);
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
            'log_which' => 'required|in:stdout,stderr',
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
            $this->log_tail_body = $this->log_which === 'stderr'
                ? $provisioner->tailProgramStderrLog($this->server->fresh(), $prog, 200)
                : $provisioner->tailProgramStdoutLog($this->server->fresh(), $prog, 200);
        } catch (\Throwable $e) {
            $this->log_tail_body = '';
            $this->flash_error = $e->getMessage();
        }
    }

    public function refreshLogTailFollow(SupervisorProvisioner $provisioner): void
    {
        if (! $this->log_follow_enabled || $this->log_tail_program_id === null || $this->daemons_workspace_tab !== 'logs') {
            return;
        }
        $this->tailProgramLog($provisioner);
    }

    public function restartOneProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $out = $provisioner->restartProgramGroup($this->server->fresh(), $id);
            $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
            SupervisorDaemonAudit::log($this->server->fresh(), $prog, 'restart_one', ['output' => Str::limit($out, 500)]);
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
            SupervisorDaemonAudit::log($this->server->fresh(), null, 'restart_all', ['output' => Str::limit($out, 1200)]);
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
            $this->inspect_supervisor_body = $provisioner->fetchSupervisorctlStatus($this->server->fresh());
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['supervisorPrograms']);

        $orgId = $this->server->organization_id;
        $templates = $orgId
            ? OrganizationSupervisorProgramTemplate::query()->where('organization_id', $orgId)->orderBy('name')->get()
            : collect();

        $auditLogs = $orgId
            ? SupervisorProgramAuditLog::query()
                ->where('server_id', $this->server->id)
                ->with('user')
                ->latest('created_at')
                ->limit(40)
                ->get()
            : collect();

        $orgServersForCopy = $orgId
            ? Server::query()
                ->where('organization_id', $orgId)
                ->whereKeyNot($this->server->id)
                ->orderBy('name')
                ->get(['id', 'name'])
            : collect();

        $sitesForServer = $this->server->sites()->orderBy('name')->get(['id', 'name']);

        return view('livewire.servers.workspace-daemons', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'orgTemplates' => $templates,
            'auditLogs' => $auditLogs,
            'orgServersForCopy' => $orgServersForCopy,
            'sitesForServer' => $sitesForServer,
        ]);
    }
}
