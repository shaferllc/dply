<?php

namespace App\Livewire\Servers;

use App\Jobs\RunSupervisorOperationJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\EmitsPanelEvent;
use App\Livewire\Servers\Concerns\ChecksSupervisorInstallStatus;
use App\Livewire\Servers\Concerns\GuardsDisruptiveActions;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesDaemonImport;
use App\Livewire\Servers\Concerns\ManagesDaemonLogs;
use App\Livewire\Servers\Concerns\ManagesDaemonOperations;
use App\Livewire\Servers\Concerns\ManagesDaemonProgramControls;
use App\Livewire\Servers\Concerns\ManagesDaemonTemplates;
use App\Livewire\Servers\Concerns\ManagesSupervisorPrograms;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\RunsServerSupervisorHealthScan;
use App\Models\OrganizationSupervisorProgramTemplate;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\SupervisorProgram;
use App\Models\SupervisorProgramAuditLog;
use App\Services\Servers\ServerDaemonSloPanel;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\SupervisorDaemonAudit;
use App\Services\Servers\SupervisorProvisioner;
use App\Support\Servers\DaemonWorkspaceViewData;
use App\Support\SupervisorEnvFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceDaemons extends Component
{
    use ChecksSupervisorInstallStatus;
    use ConfirmsActionWithModal;
    use EmitsPanelEvent;
    use GuardsDisruptiveActions;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesDaemonImport;
    use ManagesDaemonLogs;
    use ManagesDaemonOperations;
    use ManagesDaemonProgramControls;
    use ManagesDaemonTemplates;
    use ManagesSupervisorPrograms;
    use RendersWorkspacePlaceholder;
    use RunsServerSupervisorHealthScan;
    use WithPagination;

    /** @var 'site'|'all' Only when {@see} is set. */
    public string $programs_list_scope = 'all';

    public bool $siteContextUnavailable = false;

    /** @var 'programs'|'service'|'sync'|'logs'|'inspect'|'activity' */
    #[Url(as: 'tab', keep: false)]
    public string $daemons_workspace_tab = 'programs';

    /** @var 'preview'|'drift'|'output' */
    #[Url(as: 'subtab', keep: false)]
    public string $daemons_sync_subtab = 'preview';

    public string $supervisor_service_output = '';

    /** Run ID of the active background supervisor operation (sync/install/restart). */
    public ?string $daemon_op_run_id = null;

    public bool $daemon_op_busy = false;

    /** null = unknown, 'active' = running, 'inactive' = stopped */
    public ?string $supervisor_service_state = null;

    /** null = unknown, 'enabled' = starts on boot, 'disabled' = does not */
    public ?string $supervisor_boot_state = null;

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
                $this->new_sv_directory = $siteModel->effectiveEnvDirectory();
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

        // #[Url] may not have initialised before mount() — honour the raw query param too.
        $tab = request()->query('tab');
        if (is_string($tab) && $tab !== '') {
            $this->setDaemonsWorkspaceTab($tab);
        }

        if ($this->daemons_workspace_tab === 'service' && $this->supervisor_installed === true) {
            try {
                $provisioner = app(SupervisorProvisioner::class);
                $activeOut = $provisioner->manageSupervisorService($server, 'is-active');
                $this->supervisor_service_state = (str_contains(strtolower(trim($activeOut)), 'active') && ! str_contains(strtolower(trim($activeOut)), 'inactive'))
                    ? 'active'
                    : 'inactive';
                $enabledOut = $provisioner->manageSupervisorService($server, 'is-enabled');
                $this->supervisor_boot_state = str_contains(strtolower(trim($enabledOut)), 'enabled') ? 'enabled' : 'disabled';
            } catch (\Throwable) {
                // Non-fatal — leave state as null
            }
        }
    }

    /**
     * This workspace is only reachable at the site-scoped route
     * (servers/{server}/sites/{site}/daemons), so a context site is always set.
     * Lock new programs to it: the "Add program" form drops its site picker and
     * saves are pinned to this site (see ManagesSupervisorPrograms).
     */
    protected function supervisorProgramsLockSiteId(): bool
    {
        return $this->context_site_id !== null;
    }


    public string $log_tail_slug = '';


    public function render(): View
    {
        $this->server->refresh();
        // Eager-load each program's site so effectiveDirectory() (which resolves
        // the working dir from the site for site-scoped programs) doesn't N+1.
        $this->server->load(['supervisorPrograms.site']);

        $orgId = $this->server->organization_id;

        $auditLogs = $orgId
            ? SupervisorProgramAuditLog::query()
                ->where('server_id', $this->server->id)
                ->with('user')
                ->latest('created_at')
                ->paginate(20)
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
            ->when($this->context_site_id !== null, fn ($q) => $q->whereKey($this->context_site_id))
            ->with(['processes' => fn ($q) => $q->where('is_active', true)->where('type', '!=', SiteProcess::TYPE_WEB)])
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
                // Hide the "Related site" picker when the program is (or will be)
                // this site's: adding always pins to this site, and editing one of
                // this site's own programs stays locked. Only editing another
                // site's program (reachable via the "all programs" peek) shows the
                // picker, so its real site stays visible.
                'lockSiteId' => $this->context_site_id !== null
                    && ($this->editing_program_id === null || $this->new_sv_site_id === $this->context_site_id),
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
