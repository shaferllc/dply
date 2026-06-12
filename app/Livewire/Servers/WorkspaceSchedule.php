<?php

namespace App\Livewire\Servers;

use App\Jobs\RunSchedulerNowJob;
use App\Jobs\SetSchedulerOutputCaptureJob;
use App\Livewire\Concerns\EmitsPanelEvent;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
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
    use InteractsWithServerWorkspace;

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

    public function openEnableSchedulerModal(): void
    {
        $this->resetValidation();
        $this->dispatch('open-modal', self::ENABLE_MODAL);
    }

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

    public function updatedEnableSiteId(): void
    {
        $this->syncEnableFormToSiteFramework();
    }

    protected function syncEnableFormToSiteFramework(): void
    {
        $site = $this->resolveEnableTargetSite();
        if ($site === null) {
            return;
        }

        if ($site->isLaravelFrameworkDetected()) {
            $this->enable_framework = 'laravel';
            $this->enable_custom_command = '';

            return;
        }

        if ($site->isRailsFrameworkDetected()) {
            $this->enable_framework = 'rails';
            $this->enable_custom_command = '';

            return;
        }

        $this->enable_framework = '';
        if (trim($this->enable_custom_command) === '') {
            $directory = rtrim($site->effectiveRepositoryPath(), '/').'/current';
            $this->enable_custom_command = 'cd '.$directory.' && ';
        }
    }

    protected function resolveEnableTargetSite(): ?Site
    {
        $siteId = $this->context_site_id ?: ($this->enable_site_id !== '' ? $this->enable_site_id : null);
        if ($siteId === null) {
            return null;
        }

        return Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($siteId)
            ->first();
    }

    protected function resolveEnableSchedulerKind(Site $site): string
    {
        if ($site->isLaravelFrameworkDetected()) {
            return ServerSchedulerHeartbeat::KIND_LARAVEL;
        }

        if ($site->isRailsFrameworkDetected()) {
            return ServerSchedulerHeartbeat::KIND_RAILS;
        }

        return ServerSchedulerHeartbeat::KIND_GENERIC;
    }

    protected function resolveBareSchedulerCommand(Site $site, string $kind): ?string
    {
        $directory = rtrim($site->effectiveRepositoryPath(), '/').'/current';

        return match ($kind) {
            ServerSchedulerHeartbeat::KIND_LARAVEL => 'cd '.$directory.' && php artisan schedule:run',
            ServerSchedulerHeartbeat::KIND_RAILS => 'cd '.$directory.' && bundle exec whenever --update-crontab',
            ServerSchedulerHeartbeat::KIND_GENERIC => ($command = trim($this->enable_custom_command)) !== '' ? $command : null,
            default => null,
        };
    }

    protected function schedulerKindLabel(string $kind): string
    {
        return match ($kind) {
            ServerSchedulerHeartbeat::KIND_LARAVEL => 'Laravel',
            ServerSchedulerHeartbeat::KIND_RAILS => 'Rails',
            default => 'Custom',
        };
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

    public function enableSchedulerForSite(PreflightSchedulerOnSite $preflight, CronExpressionValidator $cronValidator): void
    {
        $this->authorize('update', $this->server);
        $this->preflight_results = [];

        // In site context the target is always the focused site — the modal shows
        // it as a fixed label, so pin it here too rather than trusting form input.
        if ($this->context_site_id !== null) {
            $this->enable_site_id = $this->context_site_id;
        }

        $site = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->enable_site_id)
            ->first();
        if ($site === null) {
            $this->toastError(__('Pick a site.'));

            return;
        }

        $cron = trim($this->enable_cron_expression);
        if (! $cronValidator->isValid($cron) || strlen($cron) > 64) {
            $this->toastError(__('Invalid cron expression.'));

            return;
        }

        // Q18: preflight in one SSH round-trip. Block on structural failures;
        // warn-and-allow on advisory ones. Checks vary by scheduler kind.
        $kind = $this->resolveEnableSchedulerKind($site);
        $results = $preflight->run($this->server, $site, $kind);
        $this->preflight_results = $results;

        $failures = $preflight->structuralFailures($results, $kind);
        if ($results === [] || $failures !== []) {
            // Keep the modal open; $preflight_results renders inside it.
            $this->toastError($results === []
                ? __('Preflight could not run over SSH — the structured results below tell you why.')
                : __('Preflight blocked Enable — fix the structural issues below before retrying.'));

            return;
        }

        $bareCommand = $this->resolveBareSchedulerCommand($site, $kind);
        if ($bareCommand === null) {
            $this->toastError($kind === ServerSchedulerHeartbeat::KIND_GENERIC
                ? __('Enter a scheduler command.')
                : __('Could not build scheduler command for this site.'));

            return;
        }

        $wrappedCommand = sprintf(
            '/usr/local/bin/dply-scheduler-tick %s %s -- %s',
            escapeshellarg($site->id),
            escapeshellarg($kind),
            $bareCommand,
        );

        $cronJob = ServerCronJob::create([
            'server_id' => $this->server->id,
            'site_id' => $site->id,
            'cron_expression' => $cron,
            'command' => $wrappedCommand,
            'user' => $site->effectiveSystemUser($this->server),
            'enabled' => true,
            'description' => $this->schedulerKindLabel($kind).' scheduler — '.$site->name.' (wrapper-managed)',
        ]);

        // Pre-create the heartbeat row in waiting-for-first-tick state (Q4 (e))
        // so the page flips immediately to the "Waiting…" chip rather than
        // showing nothing until the first agent push lands. The agent's push
        // will upsert this row on the very next minute.
        ServerSchedulerHeartbeat::query()->create([
            'server_id' => $this->server->id,
            'site_id' => $site->id,
            'scheduler_kind' => $kind,
            'cron_expression' => $cron,
            'last_tick_at' => null,
            'consecutive_misses' => 0,
            'first_seen_at' => now(),
            'circuit_open' => false,
            'output_capture_enabled' => true,
        ]);

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.scheduler.enabled',
            $this->server,
            null,
            [
                'cron_job_id' => (string) $cronJob->id,
                'site_id' => $site->id,
                'scheduler_kind' => $kind,
                'cron_expression' => $cron,
                'advisory_warnings' => count($preflight->advisoryWarnings($results)),
            ],
        );

        $this->reset(['enable_site_id', 'enable_framework', 'enable_custom_command']);
        $this->enable_cron_expression = '* * * * *';
        $this->schedule_workspace_tab = 'schedulers';
        $this->dispatch('close-modal', self::ENABLE_MODAL);
        $this->emitPanelEvent(
            __('Scheduler enabled for :site.', ['site' => $site->name]),
            [__('Waiting for the first tick — the chip will turn green within ~60-90 seconds.')],
            'completed',
        );
    }

    /**
     * Pause or resume a wrapper-managed scheduler. Mirrors WorkspaceCron's
     * pause/resume — flip `enabled` and push the regenerated crontab so the
     * scheduler actually stops or starts firing on the host. Resume also
     * re-arms the "waiting for first tick" grace window on the heartbeat row
     * (Q20 (b)).
     */
    public function togglePause(string $heartbeatId, ServerCronSynchronizer $synchronizer): void
    {
        $this->authorize('update', $this->server);

        [$heartbeat, $cron] = $this->resolveHeartbeatAndCron($heartbeatId);
        if ($heartbeat === null || $cron === null) {
            $this->toastError(__('Scheduler not found.'));

            return;
        }

        $newEnabled = ! $cron->enabled;
        $cron->update(['enabled' => $newEnabled, 'is_synced' => false]);

        // On resume, re-arm the waiting-for-first-tick grace so the operator
        // doesn't get an immediate AMBER chip from accumulated misses while
        // the next tick is in flight.
        if ($newEnabled) {
            $heartbeat->forceFill([
                'first_seen_at' => now(),
                'consecutive_misses' => 0,
            ])->save();
        }

        audit_log(
            $this->server->organization,
            auth()->user(),
            $newEnabled ? 'server.scheduler.resumed' : 'server.scheduler.paused',
            $this->server,
            ['enabled' => ! $newEnabled],
            [
                'heartbeat_id' => $heartbeat->id,
                'cron_job_id' => (string) $cron->id,
                'enabled' => $newEnabled,
            ],
        );

        try {
            $synchronizer->sync($this->server);
        } catch (\Throwable $e) {
            $this->toastError(__('Scheduler state updated but pushing to crontab failed: :err', ['err' => $e->getMessage()]));

            return;
        }

        $this->emitPanelEvent(
            $newEnabled
                ? __('Scheduler resumed — will tick again within the cadence window.')
                : __('Scheduler paused — no further ticks until you resume.'),
            [],
            'completed',
        );
    }

    public function startEditCadence(string $heartbeatId): void
    {
        $this->authorize('update', $this->server);
        [$heartbeat] = $this->resolveHeartbeatAndCron($heartbeatId);
        if ($heartbeat === null) {
            return;
        }
        $this->editing_cadence[$heartbeatId] = (string) $heartbeat->cron_expression;
    }

    public function cancelEditCadence(string $heartbeatId): void
    {
        unset($this->editing_cadence[$heartbeatId]);
    }

    public function saveCadence(string $heartbeatId, ServerCronSynchronizer $synchronizer, CronExpressionValidator $validator): void
    {
        $this->authorize('update', $this->server);

        [$heartbeat, $cron] = $this->resolveHeartbeatAndCron($heartbeatId);
        if ($heartbeat === null || $cron === null) {
            $this->toastError(__('Scheduler not found.'));

            return;
        }

        $newExpression = trim((string) ($this->editing_cadence[$heartbeatId] ?? ''));
        if (! $validator->isValid($newExpression)) {
            $this->toastError(__('Invalid cron expression.'));

            return;
        }

        $cron->update(['cron_expression' => $newExpression, 'is_synced' => false]);
        // Mirror onto the heartbeat row so the staleness math uses the new
        // cadence on the very next render — without waiting for the agent's
        // next push to refresh it.
        $heartbeat->forceFill(['cron_expression' => $newExpression])->save();

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.scheduler.cadence_changed',
            $this->server,
            ['cron_expression' => $cron->getOriginal('cron_expression')],
            [
                'heartbeat_id' => $heartbeat->id,
                'cron_job_id' => (string) $cron->id,
                'cron_expression' => $newExpression,
            ],
        );

        unset($this->editing_cadence[$heartbeatId]);

        try {
            $synchronizer->sync($this->server);
        } catch (\Throwable $e) {
            $this->toastError(__('Cadence updated but pushing to crontab failed: :err', ['err' => $e->getMessage()]));

            return;
        }

        $this->emitPanelEvent(__('Cadence updated to :expr.', ['expr' => $newExpression]), [], 'completed');
    }

    /**
     * Disable Monitoring — Q7 (d). Different from Pause:
     *  - Pause: scheduler stops firing, we keep tracking.
     *  - Disable Monitoring: scheduler keeps firing, we stop tracking.
     *
     * v2B implementation: drops the heartbeat row. v2C will additionally
     * rewrite the cron line to remove the wrapper invocation (this prerequisite
     * doesn't exist yet — wrapper-invoking cron lines are only created by 2C).
     * Per Q20 (c), this is symmetric with Enable creating one.
     */
    public function openDisableMonitoringModal(string $heartbeatId): void
    {
        $this->authorize('update', $this->server);
        $this->disableMonitoringHeartbeatId = $heartbeatId;
        $this->showDisableMonitoringModal = true;
    }

    public function closeDisableMonitoringModal(): void
    {
        $this->showDisableMonitoringModal = false;
        $this->disableMonitoringHeartbeatId = null;
    }

    public function confirmDisableMonitoring(): void
    {
        if ($this->disableMonitoringHeartbeatId === null) {
            return;
        }

        $heartbeatId = $this->disableMonitoringHeartbeatId;
        $this->closeDisableMonitoringModal();
        $this->disableMonitoring($heartbeatId);
    }

    public function disableMonitoring(string $heartbeatId): void
    {
        $this->authorize('update', $this->server);

        [$heartbeat, $cron] = $this->resolveHeartbeatAndCron($heartbeatId);
        if ($heartbeat === null) {
            $this->toastError(__('Scheduler not found.'));

            return;
        }

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.scheduler.monitoring_disabled',
            $this->server,
            null,
            [
                'heartbeat_id' => $heartbeat->id,
                'cron_job_id' => $cron?->id,
                'scheduler_kind' => $heartbeat->scheduler_kind,
            ],
        );

        $heartbeat->delete();

        $this->toastSuccess(__('Monitoring stopped. The scheduler keeps running; we won\'t track or alert on it anymore. Re-enable from the same site to start over.'));
    }

    /**
     * Top-level Run-now — fires `schedule:run` once via SSH. Per Q15:
     *  - Refuses a second click while one is in flight (Q15 (e))
     *  - Always audits (Q15 (f))
     *  - Streams output through ConsoleAction (Q15 (c1))
     *  - 5-minute hard timeout (Q15 (d))
     *  - Coordinates with the wrapper's advisory file lock (Q15 (b1))
     *
     * The job itself (the SSH + lock + stream piece) lands here as a dispatch
     * stub for 2B; full streaming integration lives in the job class and can
     * iterate independently. This method only kicks the job and records intent.
     */
    public function runNow(string $heartbeatId): void
    {
        $this->authorize('update', $this->server);

        [$heartbeat, $cron] = $this->resolveHeartbeatAndCron($heartbeatId);
        if ($heartbeat === null || $cron === null) {
            $this->toastError(__('Scheduler not found.'));

            return;
        }

        if (in_array($heartbeatId, $this->run_now_in_flight, true)) {
            $this->toastError(__('A Run now is already in flight for this scheduler. Watch the activity banner.'));

            return;
        }

        $this->run_now_in_flight[] = $heartbeatId;

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.scheduler.run_now',
            $this->server,
            null,
            [
                'heartbeat_id' => $heartbeat->id,
                'cron_job_id' => (string) $cron->id,
                'scheduler_kind' => $heartbeat->scheduler_kind,
            ],
        );

        $runId = (string) Str::ulid();
        $this->scheduler_run_id = $runId;
        $this->scheduler_run_busy = true;
        $this->scheduler_run_cache_key = RunSchedulerNowJob::cacheKey($runId);

        RunSchedulerNowJob::dispatch(
            $this->server->id,
            $heartbeat->id,
            (string) auth()->id(),
            $runId,
        );

        $this->emitPanelEvent(
            __('Run now queued for :site scheduler.', ['site' => $heartbeat->site?->name ?? $heartbeatId]),
            [__('Running schedule:run on the server… (5-minute timeout)')],
            'running',
        );
    }

    /**
     * Poll the cached output of the in-flight Run-now job (mirrors
     * WorkspaceDaemons::pollDaemonOperation). Wired to wire:poll while busy.
     */
    public function pollSchedulerRun(): void
    {
        if ($this->scheduler_run_cache_key === null || ! $this->scheduler_run_busy) {
            return;
        }

        $payload = Cache::get($this->scheduler_run_cache_key);
        if (! is_array($payload)) {
            return;
        }

        $status = (string) ($payload['status'] ?? 'running');
        if (! in_array($status, ['done', 'failed'], true)) {
            return;
        }

        $output = (string) ($payload['output'] ?? '');
        $lines = array_values(array_filter(explode("\n", $output)));
        $this->scheduler_run_busy = false;
        $this->scheduler_run_id = null;
        $this->scheduler_run_cache_key = null;
        $this->run_now_in_flight = [];

        $this->emitPanelEvent(
            $status === 'done' ? __('Done.') : __('Operation failed.'),
            $lines,
            $status === 'done' ? 'completed' : 'failed',
        );
    }

    /**
     * Per-scheduler output capture toggle (PR2). Flips the DB column optimistically
     * and queues an SSH job to touch/remove the on-box control file (and lazily
     * re-push a capture-capable wrapper when enabling).
     */
    public function toggleOutputCapture(string $heartbeatId): void
    {
        $this->authorize('update', $this->server);

        $heartbeat = ServerSchedulerHeartbeat::query()
            ->where('server_id', $this->server->id)
            ->whereKey($heartbeatId)
            ->first();
        if ($heartbeat === null) {
            $this->toastError(__('Scheduler not found.'));

            return;
        }

        $enabled = ! $heartbeat->output_capture_enabled;
        $heartbeat->forceFill(['output_capture_enabled' => $enabled])->save();

        $runId = (string) Str::ulid();
        $this->scheduler_run_id = $runId;
        $this->scheduler_run_busy = true;
        $this->scheduler_run_cache_key = SetSchedulerOutputCaptureJob::cacheKey($runId);

        SetSchedulerOutputCaptureJob::dispatch($this->server->id, $heartbeat->id, $enabled, $runId);

        audit_log(
            $this->server->organization,
            auth()->user(),
            'server.scheduler.capture_'.($enabled ? 'enabled' : 'disabled'),
            $this->server,
            null,
            ['heartbeat_id' => $heartbeat->id, 'site_id' => $heartbeat->site_id],
        );

        $this->emitPanelEvent(
            $enabled
                ? __('Enabling output capture — pushing control file to the server…')
                : __('Disabling output capture — removing control file…'),
            [],
            'running',
        );
    }

    /**
     * Logs tab — tail the system cron daemon log. Analog to the daemons
     * "supervisord daemon log" card; proves cron itself is firing entries.
     * Distros differ, so try journalctl (cron, then crond) then syslog.
     */
    public function loadCronDaemonLog(ExecuteRemoteTaskOnServer $exec): void
    {
        $this->authorize('view', $this->server);
        $this->cron_daemon_log_body = null;

        if (! $this->serverOpsReady()) {
            $this->cron_daemon_log_body = __('Server is not ready for SSH.');

            return;
        }

        $cmd = 'journalctl -u cron --no-pager -n 200 2>/dev/null'
            .' || journalctl -u crond --no-pager -n 200 2>/dev/null'
            .' || grep -iE "CRON|cron" /var/log/syslog 2>/dev/null | tail -n 200'
            .' || echo "No cron daemon log available."';

        try {
            $out = $exec->runInlineBash($this->server->fresh(), 'scheduler-cron-log', $cmd, timeoutSeconds: 20, asRoot: false);
            $body = trim((string) $out->buffer);
            $this->cron_daemon_log_body = $body !== '' ? $body : __('(cron daemon log is empty)');
        } catch (\Throwable $e) {
            $this->cron_daemon_log_body = $e->getMessage();
        }
    }

    /**
     * Resolve a heartbeat by id + its companion cron row (joined on
     * server_id + site_id + scheduler_kind via the same convention the cards
     * builder uses). Both must belong to the current server — defensive
     * against URL tampering.
     *
     * @return array{0: ?ServerSchedulerHeartbeat, 1: ?ServerCronJob}
     */
    private function resolveHeartbeatAndCron(string $heartbeatId): array
    {
        $hb = ServerSchedulerHeartbeat::query()
            ->where('server_id', $this->server->id)
            ->whereKey($heartbeatId)
            ->first();
        if ($hb === null) {
            return [null, null];
        }

        // Pick the scheduler-shaped cron row associated with this heartbeat.
        // Same string-match the cards builder uses so the page + actions
        // operate on the same row.
        $cron = ServerCronJob::query()
            ->where('server_id', $this->server->id)
            ->where('site_id', $hb->site_id)
            ->get()
            ->first(function (ServerCronJob $job) use ($hb): bool {
                $cmd = strtolower((string) $job->command);
                $needles = match ($hb->scheduler_kind) {
                    ServerSchedulerHeartbeat::KIND_LARAVEL => ['schedule:run', 'schedule:work'],
                    ServerSchedulerHeartbeat::KIND_RAILS => ['whenever', 'rake schedule', 'bin/rails runner'],
                    ServerSchedulerHeartbeat::KIND_GENERIC => ['celery beat', 'celerybeat'],
                    default => [],
                };
                foreach ($needles as $n) {
                    if (str_contains($cmd, $n)) {
                        return true;
                    }
                }

                return false;
            });

        return [$hb, $cron];
    }

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

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return array{total: int, healthy: int, attention: int, paused: int}
     */
    private function scheduleStatsFromCards(array $cards): array
    {
        $healthy = 0;
        $attention = 0;
        $paused = 0;
        $total = 0;

        foreach ($cards as $card) {
            $state = (string) ($card['state'] ?? '');
            if ($state === 'no_scheduler') {
                $attention++;

                continue;
            }

            $total++;

            if ($state === 'paused') {
                $paused++;

                continue;
            }

            $health = $card['health'] ?? null;
            if ($health === SchedulerHealthEvaluator::STATE_HEALTHY) {
                $healthy++;
            } elseif (in_array($health, [
                SchedulerHealthEvaluator::STATE_WAITING,
                SchedulerHealthEvaluator::STATE_AMBER,
                SchedulerHealthEvaluator::STATE_RED,
            ], true) || $state === 'detected_unmonitored') {
                $attention++;
            }
        }

        return compact('total', 'healthy', 'attention', 'paused');
    }

    /**
     * @param  array{healthy: int, waiting: int, amber: int, red: int, paused: int, unmonitored: int, tracked_total: int, no_scheduler_sites: int}  $stats
     * @return array{total: int, healthy: int, attention: int, paused: int}
     */
    private function scheduleStatsFromSummary(array $stats): array
    {
        return [
            'total' => $stats['tracked_total'] + $stats['paused'] + $stats['unmonitored'],
            'healthy' => $stats['healthy'],
            'attention' => $stats['waiting'] + $stats['amber'] + $stats['red'] + $stats['unmonitored'] + $stats['no_scheduler_sites'],
            'paused' => $stats['paused'],
        ];
    }
}
