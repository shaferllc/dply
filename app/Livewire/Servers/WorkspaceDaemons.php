<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Servers\Concerns\ChecksSupervisorInstallStatus;
use App\Livewire\Servers\Concerns\GuardsDisruptiveActions;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesSupervisorPrograms;
use App\Livewire\Servers\Concerns\RunsServerSupervisorHealthScan;
use App\Models\OrganizationSupervisorProgramTemplate;
use App\Models\Server;
use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Models\SupervisorProgramAuditLog;
use App\Services\Servers\ServerDaemonSloPanel;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\SupervisorDaemonAudit;
use App\Services\Servers\SupervisorProvisioner;
use App\Support\Servers\DaemonWorkspaceViewData;
use App\Support\SupervisorEnvFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceDaemons extends Component
{
    use ChecksSupervisorInstallStatus;
    use ConfirmsActionWithModal;
    use GuardsDisruptiveActions;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesSupervisorPrograms;
    use RunsServerSupervisorHealthScan;

    /** @var 'site'|'all' Only when {@see} is set. */
    public string $programs_list_scope = 'all';

    public bool $siteContextUnavailable = false;

    /** @var 'programs'|'service'|'sync'|'logs'|'inspect'|'activity' */
    public string $daemons_workspace_tab = 'programs';

    /** @var 'preview'|'drift'|'output' */
    public string $daemons_sync_subtab = 'preview';

    public string $supervisor_service_output = '';

    public ?string $inspect_supervisor_body = null;

    public string $preview_sync_output = '';

    public string $drift_output = '';

    public string $log_tail_body = '';

    public ?string $log_tail_program_id = null;

    public string $log_which = 'stdout';

    public bool $log_follow_enabled = false;

    /** Generic supervisord daemon log (separate from per-program logs). null = not loaded yet. */
    public ?string $supervisord_log_body = null;

    /** @var array<string, array{state: string, lines: array<int, string>}> */
    public array $program_status_map = [];

    /** @var array<int, string> */
    public array $preflight_messages = [];

    public string $template_save_name = '';

    public string $copy_source_program_id = '';

    public string $copy_target_server_id = '';

    public string $copy_new_slug = '';

    public string $import_from_site_id = '';

    public string $import_to_site_id = '';

    public function mount(Server $server, ?Site $site = null): void
    {
        if ($site !== null) {
            abort_unless($site->server_id === $server->id, 404);
            abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);
            Gate::authorize('view', $site);
        }

        $this->bootWorkspace($server);
        $this->initSupervisorProgramFormDefaults();
        $this->initSupervisorInstallStatus($server);

        $resolvedSiteId = $site?->id;
        if ($resolvedSiteId === null) {
            $siteId = request()->query('site');
            if (is_string($siteId) && $siteId !== '') {
                $exists = Site::query()->where('server_id', $server->id)->whereKey($siteId)->exists();
                if ($exists) {
                    $resolvedSiteId = $siteId;
                }
            }
        }

        if ($resolvedSiteId !== null) {
            $this->context_site_id = $resolvedSiteId;
            $this->programs_list_scope = 'site';
            $this->new_sv_site_id = $resolvedSiteId;
            $siteModel = Site::query()->where('server_id', $server->id)->whereKey($resolvedSiteId)->first();
            if ($siteModel !== null) {
                $this->new_sv_directory = rtrim($siteModel->effectiveRepositoryPath(), '/').'/current';
                $this->new_sv_user = $siteModel->effectiveSystemUser($server);
                $this->siteContextUnavailable = ! $this->siteSupportsVmManagedDaemons($siteModel);
            }
        }

        $preset = request()->query('preset');
        if (Gate::allows('update', $server)
            && is_string($preset)
            && in_array($preset, [
                'laravel-queue',
                'laravel-horizon',
                'reverb',
                'laravel-schedule',
                'laravel-octane',
                'nodejs',
                'sidekiq',
                'solid-queue',
                'action-cable',
            ], true)) {
            $this->applySupervisorPreset($preset);

            // Deep-linked "Add worker" entry points (e.g. the pipeline review
            // "queue restart without program" fix) pass ?open=worker so the
            // pre-filled create-worker modal pops on arrival.
            if (request()->query('open') === 'worker') {
                $this->dispatch('open-modal', $this->supervisorProgramModalName());
            }
        }

        $tab = request()->query('tab');
        if (is_string($tab) && $tab !== '') {
            $this->setDaemonsWorkspaceTab($tab);
        }
    }

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
    }

    public function supervisorServiceAction(SupervisorProvisioner $provisioner, string $action): void
    {
        $readOnly = in_array($action, ['status', 'is-active', 'is-enabled'], true);
        $this->authorize($readOnly ? 'view' : 'update', $this->server);
        try {
            $out = $provisioner->manageSupervisorService($this->server->fresh(), $action);
            $this->supervisor_service_output = $out;
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
            $this->toastError(__('Choose a different server than the current one.'));

            return;
        }

        $source = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->copy_source_program_id)
            ->first();
        if (! $source) {
            $this->toastError(__('Source program not found.'));

            return;
        }

        $target = Server::query()
            ->where('organization_id', $this->server->organization_id)
            ->whereKey($this->copy_target_server_id)
            ->first();
        if (! $target) {
            $this->toastError(__('Target server not found.'));

            return;
        }

        if (SupervisorProgram::query()->where('server_id', $target->id)->where('slug', $this->copy_new_slug)->exists()) {
            $this->toastError(__('A program with that slug already exists on the target server.'));

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
        $this->toastSuccess(__('Program copied to the target server. Open that server’s Daemons page and sync Supervisor.'));
    }

    public function importProgramFromSite(string $programId): void
    {
        $this->authorize('update', $this->server);

        $targetSite = $this->resolveImportTargetSite();
        if ($targetSite === null) {
            $this->toastError(__('Choose a destination site for the import.'));

            return;
        }

        $this->validate([
            'import_from_site_id' => [
                'required',
                'ulid',
                Rule::exists('sites', 'id')->where(fn ($q) => $q->where('server_id', $this->server->id)),
            ],
        ]);

        if ($this->import_from_site_id === $targetSite->id) {
            $this->toastError(__('Choose a different site than the import destination.'));

            return;
        }

        $sourceSite = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->import_from_site_id)
            ->first();

        if ($sourceSite === null) {
            $this->toastError(__('Source site not found.'));

            return;
        }

        $source = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->where('site_id', $sourceSite->id)
            ->whereKey($programId)
            ->first();

        if ($source === null) {
            $this->toastError(__('Source program not found.'));

            return;
        }

        $created = $this->duplicateProgramForSite($source, $sourceSite, $targetSite);

        SupervisorDaemonAudit::log($this->server->fresh(), $created, 'program_imported_from_site', [
            'source_site_id' => $sourceSite->id,
            'target_site_id' => $targetSite->id,
            'source_program_id' => $source->id,
        ]);

        $this->toastSuccess(__('Imported :slug for :site. Sync Supervisor to apply on the server.', [
            'slug' => $created->slug,
            'site' => $targetSite->name,
        ]));
    }

    public function importAllProgramsFromSite(): void
    {
        $this->authorize('update', $this->server);

        $targetSite = $this->resolveImportTargetSite();
        if ($targetSite === null) {
            $this->toastError(__('Choose a destination site for the import.'));

            return;
        }

        $this->validate([
            'import_from_site_id' => [
                'required',
                'ulid',
                Rule::exists('sites', 'id')->where(fn ($q) => $q->where('server_id', $this->server->id)),
            ],
        ]);

        if ($this->import_from_site_id === $targetSite->id) {
            $this->toastError(__('Choose a different site than the import destination.'));

            return;
        }

        $sourceSite = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->import_from_site_id)
            ->first();

        if ($sourceSite === null) {
            $this->toastError(__('Source site not found.'));

            return;
        }

        $sources = SupervisorProgram::query()
            ->where('server_id', $this->server->id)
            ->where('site_id', $sourceSite->id)
            ->orderBy('slug')
            ->get();

        if ($sources->isEmpty()) {
            $this->toastError(__('No programs are linked to the source site.'));

            return;
        }

        $imported = 0;
        foreach ($sources as $source) {
            $created = $this->duplicateProgramForSite($source, $sourceSite, $targetSite);
            SupervisorDaemonAudit::log($this->server->fresh(), $created, 'program_imported_from_site', [
                'source_site_id' => $sourceSite->id,
                'target_site_id' => $targetSite->id,
                'source_program_id' => $source->id,
            ]);
            $imported++;
        }

        $this->toastSuccess(trans_choice(
            'Imported :count program into :site. Sync Supervisor to apply on the server.|Imported :count programs into :site. Sync Supervisor to apply on the server.',
            $imported,
            ['count' => $imported, 'site' => $targetSite->name],
        ));
    }

    protected function resolveImportTargetSite(): ?Site
    {
        $targetSiteId = $this->context_site_id ?: ($this->import_to_site_id !== '' ? $this->import_to_site_id : null);
        if ($targetSiteId === null) {
            return null;
        }

        return Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($targetSiteId)
            ->first();
    }

    protected function duplicateProgramForSite(
        SupervisorProgram $source,
        Site $sourceSite,
        Site $targetSite,
    ): SupervisorProgram {
        $slug = $this->resolveUniqueProgramSlug($source->slug, $targetSite);

        return SupervisorProgram::query()->create([
            'server_id' => $this->server->id,
            'site_id' => $targetSite->id,
            'slug' => $slug,
            'program_type' => $source->program_type,
            'command' => $this->remapSiteScopedValue($source->command, $sourceSite, $targetSite),
            'directory' => $this->remapSiteScopedValue($source->directory, $sourceSite, $targetSite),
            'user' => $targetSite->effectiveSystemUser($this->server),
            'numprocs' => $source->numprocs,
            'is_active' => $source->is_active,
            'env_vars' => $source->env_vars,
            'stdout_logfile' => $source->stdout_logfile !== null && $source->stdout_logfile !== ''
                ? $this->remapSiteScopedValue($source->stdout_logfile, $sourceSite, $targetSite)
                : null,
            'stderr_logfile' => $source->stderr_logfile !== null && $source->stderr_logfile !== ''
                ? $this->remapSiteScopedValue($source->stderr_logfile, $sourceSite, $targetSite)
                : null,
            'priority' => $source->priority,
            'startsecs' => $source->startsecs,
            'stopwaitsecs' => $source->stopwaitsecs,
            'autorestart' => $source->autorestart,
            'redirect_stderr' => $source->redirect_stderr ?? true,
        ]);
    }

    protected function resolveUniqueProgramSlug(string $baseSlug, Site $targetSite): string
    {
        $slug = Str::limit($baseSlug, 64, '');
        if (! SupervisorProgram::query()->where('server_id', $this->server->id)->where('slug', $slug)->exists()) {
            return $slug;
        }

        $suffix = Str::slug($targetSite->slug ?: $targetSite->name) ?: 'site';
        $candidate = Str::limit(rtrim($baseSlug, '-').'-'.$suffix, 64, '');
        if (! SupervisorProgram::query()->where('server_id', $this->server->id)->where('slug', $candidate)->exists()) {
            return $candidate;
        }

        $i = 2;
        do {
            $candidate = Str::limit(rtrim($baseSlug, '-').'-'.$suffix.'-'.$i, 64, '');
            $i++;
        } while (SupervisorProgram::query()->where('server_id', $this->server->id)->where('slug', $candidate)->exists());

        return $candidate;
    }

    protected function remapSiteScopedValue(string $value, Site $fromSite, Site $toSite): string
    {
        $fromBase = rtrim($fromSite->effectiveRepositoryPath(), '/');
        $toBase = rtrim($toSite->effectiveRepositoryPath(), '/');

        if ($fromBase === '' || $fromBase === $toBase) {
            return $value;
        }

        if (str_contains($value, $fromBase)) {
            return str_replace($fromBase, $toBase, $value);
        }

        if ($value === $fromBase.'/current' || $value === $fromBase) {
            return $toBase.'/current';
        }

        return $value;
    }

    public function loadProgramStatuses(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('view', $this->server);
        $this->program_status_map = [];
        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            return;
        }
        try {
            $out = $provisioner->fetchSupervisorctlStatus($this->server->fresh());
            $this->program_status_map = $provisioner->parseManagedProgramStatuses($this->server->fresh(), $out);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
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
            $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
            SupervisorDaemonAudit::log($this->server->fresh(), $prog, 'stop_one', ['output' => Str::limit($out, 500)]);
            $this->toastSuccess(__('Stop: :out', ['out' => Str::limit($out, 500)]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function startOneProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        try {
            $out = $provisioner->startProgramGroup($this->server->fresh(), $id);
            $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
            SupervisorDaemonAudit::log($this->server->fresh(), $prog, 'start_one', ['output' => Str::limit($out, 500)]);
            $this->toastSuccess(__('Start: :out', ['out' => Str::limit($out, 500)]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
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

    public string $log_tail_slug = '';

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

    public function restartOneProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        try {
            $out = $provisioner->restartProgramGroup($this->server->fresh(), $id);
            $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
            SupervisorDaemonAudit::log($this->server->fresh(), $prog, 'restart_one', ['output' => Str::limit($out, 500)]);
            $this->toastSuccess(__('Restart: :out', ['out' => Str::limit($out, 500)]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function restartAllPrograms(SupervisorProvisioner $provisioner, bool $override = false): void
    {
        $this->authorize('update', $this->server);
        if (! $this->disruptiveActionAllowed(__('Restart all programs'), $override)) {
            return;
        }
        try {
            $out = $provisioner->restartAllManagedPrograms($this->server->fresh());
            SupervisorDaemonAudit::log($this->server->fresh(), null, 'restart_all', ['output' => Str::limit($out, 1200)]);
            $this->toastSuccess(__('Restart all: :out', ['out' => Str::limit($out, 1200)]));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
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

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['supervisorPrograms']);

        $orgId = $this->server->organization_id;

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

        $sitesForServer = $this->server->sites()->orderBy('name')->get(['id', 'name', 'slug']);

        $importTargetSiteId = $this->context_site_id ?: ($this->import_to_site_id !== '' ? $this->import_to_site_id : null);
        $sitesForImport = $importTargetSiteId !== null
            ? $sitesForServer->where('id', '!=', $importTargetSiteId)->values()
            : $sitesForServer;

        $importSourcePrograms = ($this->import_from_site_id !== '' && $importTargetSiteId !== null && $this->import_from_site_id !== $importTargetSiteId)
            ? $this->server->supervisorPrograms
                ->where('site_id', $this->import_from_site_id)
                ->sortBy('slug')
                ->values()
            : collect();

        $allPrograms = $this->server->supervisorPrograms;
        $filteredSupervisorPrograms = ($this->context_site_id !== null && $this->programs_list_scope === 'site')
            ? $allPrograms->where('site_id', $this->context_site_id)->values()
            : $allPrograms;

        $contextSiteModel = $this->context_site_id !== null
            ? Site::query()->where('server_id', $this->server->id)->whereKey($this->context_site_id)->first()
            : null;

        $daemonSloReport = ($contextSiteModel === null && Feature::active('workspace.daemon_slo'))
            ? app(ServerDaemonSloPanel::class)->forServer($this->server)
            : null;

        // Header at-a-glance counts. Computed against the visible (filtered) set so the
        // numbers match what the operator sees in the program list below — switching the
        // programs_list_scope (site vs all) flips the stats too.
        $stats = [
            'total' => $filteredSupervisorPrograms->count(),
            'active' => $filteredSupervisorPrograms->where('is_active', true)->count(),
            'inactive' => $filteredSupervisorPrograms->where('is_active', false)->count(),
            'total_processes' => (int) $filteredSupervisorPrograms->where('is_active', true)->sum('numprocs'),
        ];

        // Workers managed by systemd (SiteProcess → dply-site-*.service), NOT by
        // Supervisor — these never show in the program list above, which is the
        // #1 "where's my Horizon worker?" confusion. Surface them read-only so
        // operators know they exist and where to manage them.
        $systemdWorkers = Site::query()
            ->where('server_id', $this->server->id)
            ->with(['processes' => fn ($q) => $q->where('is_active', true)->where('type', '!=', \App\Models\SiteProcess::TYPE_WEB)])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'server_id'])
            ->flatMap(fn (Site $site) => $site->processes->map(fn ($p) => [
                'site_id' => (string) $site->id,
                'site_name' => $site->name,
                'name' => $p->name,
                'type' => $p->type,
                'command' => (string) $p->command,
            ]))
            ->values();

        return view('livewire.servers.workspace-daemons', array_merge(
            DaemonWorkspaceViewData::for($this->server, $this),
            [
                'systemdWorkers' => $systemdWorkers,
                'serverIsWorkerHost' => $this->server->isWorkerHost(),
                'deletionSummary' => $this->showRemoveServerModal
                    ? ServerRemovalAdvisor::summary($this->server)
                    : null,
                'auditLogs' => $auditLogs,
                'orgServersForCopy' => $orgServersForCopy,
                'sitesForServer' => $sitesForServer,
                'sitesForImport' => $sitesForImport,
                'importSourcePrograms' => $importSourcePrograms,
                'filteredSupervisorPrograms' => $filteredSupervisorPrograms,
                'contextSiteModel' => $contextSiteModel,
                'restartAllConfirmMessage' => $this->disruptiveConfirmMessage(__('Restart all programs')),
                'daemonsStats' => $stats,
                'daemonSloReport' => $daemonSloReport,
            ],
        ));
    }
}
