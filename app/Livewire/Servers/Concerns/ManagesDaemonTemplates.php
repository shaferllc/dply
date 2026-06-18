<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\OrganizationSupervisorProgramTemplate;
use App\Services\Servers\SupervisorDaemonAudit;
use App\Support\SupervisorEnvFormatter;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesDaemonTemplates
{


    public function saveOrgTemplate(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->syncQuickQueueBuilderIntoForm()) {
            return;
        }

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
        $this->toastSuccess(__('Organization template saved. Use “Apply” on a template to load it into the form.'));
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
        $this->toastSuccess(__('Template loaded — review slug and site, then add the program.'));
    }

    public function deleteOrgTemplate(string $templateId): void
    {
        $this->authorize('update', $this->server);
        $tpl = OrganizationSupervisorProgramTemplate::query()
            ->where('organization_id', $this->server->organization_id)
            ->whereKey($templateId)
            ->first();
        if ($tpl !== null) {
            $tpl->delete();
            SupervisorDaemonAudit::log($this->server->fresh(), null, 'template_deleted', [
                'template_slug' => $tpl->slug,
                'template_name' => $tpl->name,
            ]);
        }
        $this->toastSuccess(__('Template removed.'));
    }
}
