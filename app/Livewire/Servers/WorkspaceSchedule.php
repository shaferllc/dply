<?php

namespace App\Livewire\Servers;

use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerSchedulerHeartbeat;
use App\Models\Site;
use App\Services\Servers\CronExpressionValidator;
use App\Services\Servers\PreflightSchedulerOnSite;
use App\Services\Servers\SchedulerCardsBuilder;
use App\Services\Servers\ServerCronSynchronizer;
use App\Services\Servers\ServerRemovalAdvisor;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use App\Livewire\Concerns\RequiresFeature;
use Livewire\Component;

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
class WorkspaceSchedule extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.schedule';
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    /** Form state for "Enable scheduler for site". */
    public string $enable_site_id = '';

    public string $enable_cron_expression = '* * * * *';

    /** @var 'laravel'|'rails' Framework hint that picks the right scheduler command. */
    public string $enable_framework = 'laravel';

    /** When set (?site=…), filters the lists to that site's cron entries / daemons. */
    public ?string $context_site_id = null;

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

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);

        $siteId = request()->query('site');
        if (is_string($siteId) && $siteId !== '') {
            $exists = Site::query()
                ->where('server_id', $server->id)
                ->whereKey($siteId)
                ->exists();
            if ($exists) {
                $this->context_site_id = $siteId;
                $this->enable_site_id = $siteId;
            }
        }
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

        // Q18: preflight all seven checks in one SSH round-trip. Block on
        // structural failures; warn-and-allow on advisory ones.
        $results = $preflight->run($this->server, $site);
        $this->preflight_results = $results;

        $failures = $preflight->structuralFailures($results);
        if ($results === [] || $failures !== []) {
            $this->toastError($results === []
                ? __('Preflight could not run over SSH — the structured results below tell you why.')
                : __('Preflight blocked Enable — fix the structural issues below before retrying.'));

            return;
        }

        // Wrap the framework scheduler command in dply-scheduler-tick so the
        // heartbeat + per-task pipe starts working immediately. The bare
        // command is preserved as the wrapper's `-- <cmd>` tail; an operator
        // who later removes the wrapper (e.g. via Disable Monitoring) ends up
        // with exactly the original bare line.
        $directory = rtrim($site->effectiveRepositoryPath(), '/').'/current';
        $bareCommand = match ($this->enable_framework) {
            'laravel' => 'cd '.$directory.' && php artisan schedule:run',
            'rails' => 'cd '.$directory.' && bundle exec whenever --update-crontab',
            default => null,
        };
        if ($bareCommand === null) {
            $this->toastError(__('Unknown framework.'));

            return;
        }

        $kind = $this->enable_framework === 'rails'
            ? ServerSchedulerHeartbeat::KIND_RAILS
            : ServerSchedulerHeartbeat::KIND_LARAVEL;
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
            'description' => ucfirst($this->enable_framework).' scheduler — '.$site->name.' (wrapper-managed)',
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

        $this->reset(['enable_site_id', 'enable_framework']);
        $this->enable_cron_expression = '* * * * *';
        $this->toastSuccess(__('Scheduler enabled for :site. Waiting for the first tick — the chip will go green within ~60-90 seconds.', ['site' => $site->name]));
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

        $this->toastSuccess($newEnabled
            ? __('Scheduler resumed — will tick again within the cadence window.')
            : __('Scheduler paused — no further ticks until you resume.'));
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

        $this->toastSuccess(__('Cadence updated to :expr.', ['expr' => $newExpression]));
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

        \App\Jobs\RunSchedulerNowJob::dispatch(
            $this->server->id,
            $heartbeat->id,
            (string) auth()->id(),
        );

        $this->toastSuccess(__('Run now queued. Watch the activity tab for streaming output (5-minute timeout).'));
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

        // Apply the ?site= filter at render time so the summary strip still
        // reflects the whole server even when the operator is drilled into
        // one site. Drives the "Filtered to site:" banner on the page.
        $cards = $built['cards'];
        if ($this->context_site_id !== null) {
            $cards = array_values(array_filter(
                $cards,
                fn (array $card): bool => $card['site']->id === $this->context_site_id,
            ));
        }

        $sites = Site::query()
            ->where('server_id', $this->server->id)
            ->orderBy('name')
            ->get();

        $contextSite = $this->context_site_id !== null
            ? $sites->firstWhere('id', $this->context_site_id)
            : null;

        return view('livewire.servers.workspace-schedule', [
            'opsReady' => $this->serverOpsReady(),
            'contextSite' => $contextSite,
            'cards' => $cards,
            'stats' => $built['stats'],
            'sites' => $sites,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
