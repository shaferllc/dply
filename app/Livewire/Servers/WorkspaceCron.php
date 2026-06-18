<?php

namespace App\Livewire\Servers;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\EmitsPanelEvent;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesCronBundles;
use App\Livewire\Servers\Concerns\ManagesCronCommandPresets;
use App\Livewire\Servers\Concerns\ManagesCronInspection;
use App\Livewire\Servers\Concerns\ManagesCronJobs;
use App\Livewire\Servers\Concerns\ManagesCronLogsModal;
use App\Livewire\Servers\Concerns\ManagesCronOrgTemplates;
use App\Livewire\Servers\Concerns\ManagesCronRuns;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerCronJobRun;
use App\Models\Site;
use App\Services\Servers\ServerCronSynchronizer;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\CronWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceCron extends Component
{
    use ConfirmsActionWithModal;
    use EmitsPanelEvent;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use ManagesCronBundles;
    use ManagesCronCommandPresets;
    use ManagesCronInspection;
    use ManagesCronJobs;
    use ManagesCronLogsModal;
    use ManagesCronOrgTemplates;
    use ManagesCronRuns;
    use RendersWorkspacePlaceholder;
    use WithPagination;

    public string $cron_job_search = '';

    /** When set (site route or ?site=), scope UI and defaults to this site. */
    public ?string $context_site_id = null;

    /** @var 'site'|'all' Only applies when {@see} is set. */
    public string $cron_list_scope = 'all';

    /** True when the bound site uses a non-VM runtime (no SSH cron on the guest). */
    public bool $siteContextUnavailable = false;

    /** @var array<string, string> */
    protected array $presetExpressions = [
        'every_minute' => '* * * * *',
        'hourly' => '0 * * * *',
        'nightly' => '0 2 * * *',
        'weekly' => '0 2 * * 0',
        'monthly' => '0 2 1 * *',
        'custom' => '* * * * *',
    ];

    /** @var 'jobs'|'history'|'inspect'|'templates'|'maintenance' */
    #[Url(as: 'tab', except: 'jobs', history: true)]
    public string $cron_workspace_tab = 'jobs';

    /** @var list<string> Tab values accepted by {@see setCronWorkspaceTab()}. */
    protected array $cronWorkspaceTabs = ['jobs', 'history', 'inspect', 'templates', 'maintenance'];

    public function setCronWorkspaceTab(string $tab): void
    {
        $this->cron_workspace_tab = in_array($tab, $this->cronWorkspaceTabs, true) ? $tab : 'jobs';
    }

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);

        // Cron is server-scoped; the dedicated site route was removed. The server
        // page can still focus a single site via ?site= — links that used to point
        // at the site Cron page now land here filtered to that site.
        $resolvedSite = null;
        $qSite = request()->query('site');
        if (is_string($qSite) && $qSite !== '') {
            $resolvedSite = Site::query()->where('server_id', $server->id)->whereKey($qSite)->first();
        }

        if ($resolvedSite !== null) {
            $this->context_site_id = $resolvedSite->id;
            $this->cron_list_scope = 'site';
            $this->new_site_id = $resolvedSite->id;
            $this->new_cron_user = $resolvedSite->effectiveSystemUser($server);
            $this->siteContextUnavailable = ! $this->siteSupportsVmManagedCron($resolvedSite);
        } else {
            $this->new_cron_user = trim((string) $server->ssh_user) ?: 'root';
        }

        $this->inspect_crontab_user = $this->new_cron_user;
        $this->new_schedule_timezone = config('app.timezone');
        $server->loadMissing('organization');
        $org = $server->organization;
        if ($org?->cron_maintenance_until) {
            $this->org_maintenance_until_local = $org->cron_maintenance_until->timezone(config('app.timezone'))->format('Y-m-d\TH:i');
        }
        $this->org_maintenance_note = (string) ($org?->cron_maintenance_note ?? '');
    }

    public function render(): View
    {
        $this->server->loadMissing(['organization', 'cronJobs']);

        $org = $this->server->organization;
        $canUpdateOrg = $org !== null && auth()->user()?->can('update', $org);

        if (! in_array($this->cron_workspace_tab, $this->cronWorkspaceTabs, true)
            || (! $canUpdateOrg && $this->cron_workspace_tab === 'maintenance')) {
            $this->cron_workspace_tab = 'jobs';
        }

        $tab = $this->cron_workspace_tab;
        $needsJobs = $tab === 'jobs';
        $needsHistory = $tab === 'history';
        $needsInspect = $tab === 'inspect';
        $needsTemplates = $tab === 'templates';
        $needsJobsModal = $needsJobs || $this->editing_job_id !== null;
        $opsReady = $this->server->isReady() && $this->server->ssh_private_key;

        if ($needsJobs) {
            $this->server->loadMissing(['cronJobs.site.domains', 'sites']);
        }

        if ($needsTemplates) {
            $this->server->loadMissing(['organization.cronJobTemplates']);
        }

        $contextSiteModel = $this->context_site_id !== null
            ? Site::query()->where('server_id', $this->server->id)->whereKey($this->context_site_id)->first()
            : null;

        $filteredCronJobs = collect();
        $invalidExpressionJobs = [];

        if ($needsJobs) {
            $jobsQuery = ServerCronJob::query()
                ->where('server_id', $this->server->id)
                ->with(['dependsOn', 'site.domains']);

            if (trim($this->cron_job_search) !== '') {
                $term = '%'.trim($this->cron_job_search).'%';
                $jobsQuery->where(function ($q) use ($term): void {
                    $q->where('command', 'like', $term)
                        ->orWhere('description', 'like', $term);
                });
            }

            if ($this->context_site_id !== null && $this->cron_list_scope === 'site') {
                $jobsQuery->where('site_id', $this->context_site_id);
            }

            $filteredCronJobs = $jobsQuery
                ->orderBy('description')
                ->orderBy('id')
                ->get();

            $invalidExpressionJobs = app(ServerCronSynchronizer::class)
                ->invalidExpressions($filteredCronJobs);
        }

        $recentCronRuns = $needsHistory
            ? ServerCronJobRun::query()
                ->whereHas('cronJob', fn ($q) => $q->where('server_id', $this->server->id))
                ->with(['cronJob'])
                ->orderByDesc('started_at')
                ->paginate(25)
            : collect();

        $runAsUserDatalistChoices = ($needsJobs || $needsInspect)
            ? $this->runAsUserDatalistChoices()
            : [];

        return view('livewire.servers.workspace-cron', array_merge(
            CronWorkspaceViewData::for(
                $this->server,
                $this,
                includeBannerContext: $opsReady,
                includeSummaryContext: $opsReady,
            ),
            [
                'contextSiteModel' => $contextSiteModel,
                'invalidExpressionJobs' => $invalidExpressionJobs,
                'deletionSummary' => $this->showRemoveServerModal
                    ? ServerRemovalAdvisor::summary($this->server)
                    : null,
                'viewingLogJob' => $this->viewing_logs_job_id
                    ? ServerCronJob::query()
                        ->where('server_id', $this->server->id)
                        ->find($this->viewing_logs_job_id)
                    : null,
                'commandInstallPresets' => $needsJobsModal ? $this->commandInstallPresets() : [],
                'artisanCommandPresets' => $needsJobsModal ? $this->artisanCommandPresets() : [],
                'bundledCronJobs' => $needsTemplates ? $this->bundledCronJobsForView() : [],
                'crontabInspectUserChoices' => $needsInspect ? $this->crontabInspectUserChoices() : [],
                'runAsUserDatalistChoices' => $runAsUserDatalistChoices,
                'cronRunEchoSubscribable' => $opsReady ? $this->cronRunEchoSubscribable() : false,
                'filteredCronJobs' => $filteredCronJobs,
                'recentCronRuns' => $recentCronRuns,
                'canUpdateOrg' => $canUpdateOrg,
                'orgCronTemplates' => $needsTemplates ? ($org?->cronJobTemplates ?? collect()) : collect(),
                'dependsJobChoices' => $needsJobsModal
                    ? ServerCronJob::query()
                        ->where('server_id', $this->server->id)
                        ->when($this->editing_job_id, fn ($q) => $q->whereKeyNot($this->editing_job_id))
                        ->orderBy('description')
                        ->get()
                    : collect(),
                'schedulerSiteIsLaravel' => $needsJobsModal
                    ? ($this->schedulerHelperTargetSite()?->isLaravelFrameworkDetected() ?? false)
                    : false,
            ],
        ));
    }
}
