<?php

namespace App\Livewire\Servers;

use App\Jobs\RunSchedulerNowJob;
use App\Jobs\SetSchedulerOutputCaptureJob;
use App\Livewire\Concerns\EmitsPanelEvent;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\BuildsScheduleStats;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\ManagesScheduleCadence;
use App\Livewire\Servers\Concerns\ManagesScheduleRuns;
use App\Livewire\Servers\Concerns\ManagesSchedulerEnable;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\AuditLog;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Services\Servers\CronExpressionValidator;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Servers\PreflightSchedulerOnSite;
use App\Services\Servers\SchedulerCardsBuilder;
use App\Services\Servers\SchedulerHealthEvaluator;
use App\Services\Servers\ServerCronSynchronizer;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * First-class scheduler control plane for a single server (per the
 * schedule-page-v1 plan, milestone 2A).
 *
 * Page lifecycle:
 *  - On render, {@see SchedulerCardsBuilder} pivots heartbeats +
 *    scheduler-shaped cron rows into per-site cards. Stats roll up into the
 *    Q11 summary strip.
 *  - The Enable form remains as it was today (creates a bare cron entry);
 *    preflight + wrapper-invocation generation land in milestone 2C.
 *  - Per-card actions (Pause, Edit cadence, Disable Monitoring, Run-now)
 *    land in milestone 2B.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceSchedule extends Component
{
    use EmitsPanelEvent;
    use RendersWorkspacePlaceholder;
    use RequiresFeature;
    use WithPagination;

    protected string $requiredFeature = 'workspace.schedule';

    use HandlesServerRemovalFlow;
    use BuildsScheduleStats;
    use InteractsWithServerWorkspace;
    use ManagesScheduleCadence;
    use ManagesScheduleRuns;
    use ManagesSchedulerEnable;

    /** @var list<string> */
    public const SCHEDULE_TABS = ['schedulers', 'overview', 'logs', 'activity'];

    /** @var 'schedulers'|'overview'|'enable' */
    #[Url(as: 'tab', except: 'schedulers', history: true)]
    public string $schedule_workspace_tab = 'schedulers';

    /** Form state for "Enable scheduler for site". */
    public string $enable_site_id = '';

    public string $enable_cron_expression = '* * * * *';

    /** @var 'laravel'|'rails'|'' Framework hint when detection picks a preset command. */
    public string $enable_framework = '';

    public string $enable_custom_command = '';

    /** When set (?site=… or the nested site route), filters lists to that site. */
    public ?string $context_site_id = null;

    /** True when mounted at the nested {@see sites.schedule} route (native site workspace, not a server ?site= filter). */
    public bool $siteDedicatedContext = false;

    /** @var 'site'|'all' Only when {@see} is set. */
    public string $schedulers_list_scope = 'all';

    /**
     * Edit-cadence inline state — keyed by `heartbeat_id` so multiple cards
     * with active editors don't fight each other. Empty when no editor is
     * open. The Livewire model writes the operator's new cron expression here
     * before we persist via {@see saveCadence()}.
     *
     * @var array<string, string>
     */
    public array $editing_cadence = [];

    /**
     * Run-now button state. Tracks heartbeat ids currently in-flight so a
     * second click on the same card while the first job is still queued
     * is refused (Q15 (e)).
     *
     * @var list<string>
     */
    public array $run_now_in_flight = [];

    public bool $showDisableMonitoringModal = false;

    public ?string $disableMonitoringHeartbeatId = null;

    /** Enable-scheduler modal name (event-driven open/close, like daemons' Add program modal). */
    public const ENABLE_MODAL = 'schedule-enable';

    /** Live streaming for queued SSH ops (run-now, capture toggle) — cache-backed poll. */
    public ?string $scheduler_run_id = null;

    public bool $scheduler_run_busy = false;

    /** Cache key of the in-flight op so the poll can read whichever job is running. */
    public ?string $scheduler_run_cache_key = null;

    /** Cron daemon log tail (Logs tab). null = not loaded. */
    public ?string $cron_daemon_log_body = null;

    /** Logs tab — selected scheduler (heartbeat id) whose output history is shown. */
    public ?string $log_scheduler_id = null;


    public function mount(Server $server, ?Site $site = null): void
    {
        $this->bootWorkspace($server);

        if ($site !== null) {
            // Native site workspace (nested route, dispatched by SiteScheduleController).
            abort_unless($site->server_id === $server->id, 404);
            $this->authorize('view', $site);

            $this->siteDedicatedContext = true;
            $this->context_site_id = $site->id;
            $this->enable_site_id = $site->id;
            $this->schedulers_list_scope = 'site';
        } else {
            // Server workspace deep-linked with ?site= to pre-filter without leaving server nav.
            $siteId = request()->query('site');
            if (is_string($siteId) && $siteId !== '') {
                $exists = Site::query()
                    ->where('server_id', $server->id)
                    ->whereKey($siteId)
                    ->exists();
                if ($exists) {
                    $this->context_site_id = $siteId;
                    $this->enable_site_id = $siteId;
                    $this->schedulers_list_scope = 'site';
                }
            }
        }

        // Old ?tab=enable bookmarks (the Enable tab is now a modal) and any
        // unknown value fall back to the Schedulers list home.
        if (! in_array($this->schedule_workspace_tab, self::SCHEDULE_TABS, true)) {
            $this->schedule_workspace_tab = 'schedulers';
        }

        $this->syncEnableFormToSiteFramework();
    }


    public function setScheduleWorkspaceTab(string $tab): void
    {
        $this->schedule_workspace_tab = in_array($tab, self::SCHEDULE_TABS, true) ? $tab : 'schedulers';
    }

    /**
     * Most-recent preflight result rendered after a refused Enable attempt.
     * Operators see structured per-check pass/warn/fail messages so they know
     * what to fix. Cleared on next Enable attempt.
     *
     * @var list<array{key: string, status: string, message: string}>
     */
    public array $preflight_results = [];


    public function render(SchedulerCardsBuilder $cardsBuilder): View
    {
        $this->server->refresh();

        // Pivot heartbeats + cron rows into per-site cards. Cheap query (a
        // handful of rows on a single server); runs on every render.
        $built = $cardsBuilder->build($this->server);

        $allCards = $built['cards'];
        $cards = $allCards;
        if ($this->context_site_id !== null && $this->schedulers_list_scope === 'site') {
            $cards = array_values(array_filter(
                $cards,
                fn (array $card): bool => $card['site']->id === $this->context_site_id,
            ));
        }

        $scheduleStats = ($this->context_site_id !== null && $this->schedulers_list_scope === 'site')
            ? $this->scheduleStatsFromCards($cards)
            : $this->scheduleStatsFromSummary($built['stats']);

        $sites = Site::query()
            ->where('server_id', $this->server->id)
            ->orderBy('name')
            ->get();

        $contextSite = $this->context_site_id !== null
            ? $sites->firstWhere('id', $this->context_site_id)
            : null;

        $enableTargetSite = $this->resolveEnableTargetSite() ?? $contextSite;

        // Activity tab — scheduler audit events for this server. Backed by the
        // generic audit log filtered to server.scheduler.* (UX-identical to the
        // daemons Activity list, no dedicated table).
        $auditLogs = $this->schedule_workspace_tab === 'activity'
            ? AuditLog::query()
                ->where('subject_type', Server::class)
                ->where('subject_id', $this->server->id)
                ->where('action', 'like', 'server.scheduler.%')
                ->with('user')
                ->latest('created_at')
                ->paginate(20)
            : collect();

        // Logs tab — schedulers with a heartbeat row (for the selector) and the
        // selected scheduler's recent captured output history.
        $logSchedulers = collect();
        $logSelectedHeartbeat = null;
        $logTickOutputs = collect();
        if ($this->schedule_workspace_tab === 'logs') {
            $logSchedulers = ServerSchedulerHeartbeat::query()
                ->where('server_id', $this->server->id)
                ->when($this->context_site_id !== null && $this->schedulers_list_scope === 'site',
                    fn ($q) => $q->where('site_id', $this->context_site_id))
                ->with('site:id,name')
                ->get();

            if ($this->log_scheduler_id !== null) {
                $logSelectedHeartbeat = $logSchedulers->firstWhere('id', $this->log_scheduler_id);
            }
            $logSelectedHeartbeat ??= $logSchedulers->first();

            if ($logSelectedHeartbeat !== null) {
                $logTickOutputs = $logSelectedHeartbeat->tickOutputs()->limit(50)->get();
            }
        }

        return view('livewire.servers.workspace-schedule', [
            'auditLogs' => $auditLogs,
            'logSchedulers' => $logSchedulers,
            'logSelectedHeartbeat' => $logSelectedHeartbeat,
            'logTickOutputs' => $logTickOutputs,
            'opsReady' => $this->serverOpsReady(),
            'contextSite' => $contextSite,
            'contextSiteModel' => $contextSite,
            // True only on the nested site route — hides the "all sites on server"
            // scope toggle (the server route is where the whole-server view lives).
            'scheduleSiteRouteLocked' => $this->siteDedicatedContext,
            'enableTargetSite' => $enableTargetSite,
            'showLaravelSchedulerEnable' => $enableTargetSite?->isLaravelFrameworkDetected() ?? false,
            'showRailsSchedulerEnable' => $enableTargetSite?->isRailsFrameworkDetected() ?? false,
            'showCustomSchedulerEnable' => $enableTargetSite !== null
                && ! ($enableTargetSite->isLaravelFrameworkDetected() || $enableTargetSite->isRailsFrameworkDetected()),
            'cards' => $cards,
            'allCards' => $allCards,
            'stats' => $built['stats'],
            'scheduleStats' => $scheduleStats,
            'sites' => $sites,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }


}
