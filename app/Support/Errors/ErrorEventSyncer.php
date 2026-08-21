<?php

declare(strict_types=1);

namespace App\Support\Errors;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\ConsoleAction;
use App\Models\ErrorEvent;
use App\Models\SiteDeployment;
use App\Modules\Notifications\Services\ServerErrorsNotificationDispatcher;
use App\Modules\Serverless\Models\FunctionInvocation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Captures failed sources into {@see ErrorEvent} rows by scanning the source
 * tables. This is a sweeper, not an Eloquent observer, on purpose: most
 * failures are persisted via the query builder (e.g. {@see WritesConsoleAction::failConsoleAction()}
 * does `DB::table('console_actions')->update(...)`), which bypasses model
 * events — an observer would silently miss them. Polling the source tables
 * captures every failure regardless of how it was written.
 *
 * Idempotent: the recorder upserts on (source_type, source_id), and we skip
 * sources that already have an event, so re-running (scheduled sweep or
 * backfill over the same window) never duplicates.
 */
class ErrorEventSyncer
{
    public function __construct(
        private readonly ErrorEventRecorder $recorder,
        private readonly ServerErrorsNotificationDispatcher $notifier,
    ) {}

    /**
     * Record every failed ConsoleAction / SiteDeployment / FunctionInvocation
     * finalized at or after $since that isn't already captured. Returns the
     * number of new events.
     */
    /**
     * @param  bool  $refresh  Re-record sources that already have an event too
     *                         (updateOrCreate refreshes link/title/detail after
     *                         a recorder change). Default skips captured sources.
     * @param  bool  $notify  Fire an error-stream notification for each newly
     *                        captured event. The per-minute live sweep wants
     *                        this; the historical backfill passes false so it
     *                        doesn't blast alerts for old failures.
     */
    public function sync(CarbonInterface $since, bool $refresh = false, bool $notify = true): int
    {
        return $this->syncConsoleActions($since, $refresh, $notify)
            + $this->syncDeployments($since, $refresh, $notify)
            + $this->syncFunctionInvocations($since, $refresh, $notify);
    }

    private function syncConsoleActions(CarbonInterface $since, bool $refresh = false, bool $notify = true): int
    {
        $captured = ConsoleAction::query()->getModel()->getMorphClass();
        $count = 0;

        ConsoleAction::query()
            ->where('status', ConsoleAction::STATUS_FAILED)
            ->where('updated_at', '>=', $since)
            ->when(! $refresh, fn ($q) => $q
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('error_events')
                    ->whereColumn('error_events.source_id', 'console_actions.id')
                    ->where('error_events.source_type', $captured))
                // Uptime checks re-run every few minutes and write a fresh
                // console_actions row each time, so the per-source guard above
                // never collapses them — a site stuck at e.g. HTTP 403 would mint
                // a new ErrorEvent on every probe (hundreds a day). Fold them: skip
                // a failed uptime check while the site already has an un-dismissed
                // uptime ErrorEvent. The first failure of a streak still records
                // (and notifies); repeats are absorbed until it recovers or the
                // user dismisses. The job re-opens the stream by clearing these on
                // recovery (see RunSiteUptimeMonitorCheckJob::resolveOpenUptimeErrors).
                // Correlated on the outer console_actions.kind so non-uptime rows
                // are unaffected.
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('error_events')
                    ->where('console_actions.kind', 'uptime_check')
                    ->where('error_events.category', 'uptime_check')
                    ->whereNull('error_events.dismissed_at')
                    ->whereColumn('error_events.site_id', 'console_actions.subject_id')))
            ->with('subject')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$count, $notify): void {
                foreach ($rows as $row) {
                    $event = $this->recorder->recordConsoleAction($row);
                    if ($event) {
                        $count++;
                        $this->maybeNotify($event, $notify);
                    }
                }
            });

        return $count;
    }

    private function syncDeployments(CarbonInterface $since, bool $refresh = false, bool $notify = true): int
    {
        $captured = SiteDeployment::query()->getModel()->getMorphClass();
        $count = 0;

        SiteDeployment::query()
            ->where('status', SiteDeployment::STATUS_FAILED)
            ->where('updated_at', '>=', $since)
            ->when(! $refresh, fn ($q) => $q->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('error_events')
                ->whereColumn('error_events.source_id', 'site_deployments.id')
                ->where('error_events.source_type', $captured)))
            ->with('site.server')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$count, $notify): void {
                foreach ($rows as $row) {
                    $event = $this->recorder->recordDeployment($row);
                    if ($event) {
                        $count++;
                        $this->maybeNotify($event, $notify);
                    }
                }
            });

        return $count;
    }

    /**
     * Fold failed serverless invocations into the stream. A function has no
     * ConsoleAction and no SiteDeployment behind a runtime failure, so without
     * this arm a function that 500s on every request shows nothing on the
     * Errors tab it already mounts, nothing in `dply errors`, and fires no
     * site-error notification — the only record is `dply serverless errors`.
     *
     * Folded exactly like uptime checks: while a site has an un-dismissed
     * function_invocation event, further failures are absorbed. The first
     * failure of a streak records and notifies; the streak closes when the
     * function recovers (below) or the user dismisses it. Without the fold a
     * busy broken function would mint an event per request.
     *
     * Windowed on created_at because function_invocations has no updated_at
     * (it is written once). An async row that settles more than the window
     * after it was created is missed — acceptable, since the fold means we
     * only need to catch *one* failure of a streak, and the next one lands.
     */
    private function syncFunctionInvocations(CarbonInterface $since, bool $refresh = false, bool $notify = true): int
    {
        $captured = FunctionInvocation::query()->getModel()->getMorphClass();
        $count = 0;

        FunctionInvocation::query()
            ->settled()
            ->where('success', false)
            ->where('created_at', '>=', $since)
            ->when(! $refresh, fn ($q) => $q
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('error_events')
                    ->whereColumn('error_events.source_id', 'function_invocations.id')
                    ->where('error_events.source_type', $captured))
                ->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('error_events')
                    ->where('error_events.category', 'function_invocation')
                    ->whereNull('error_events.dismissed_at')
                    ->whereColumn('error_events.site_id', 'function_invocations.site_id')))
            ->with('site')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$count, $notify): void {
                foreach ($rows as $row) {
                    $event = $this->recorder->recordFunctionInvocation($row);
                    if ($event) {
                        $count++;
                        $this->maybeNotify($event, $notify);
                    }
                }
            });

        $this->resolveRecoveredFunctions();

        return $count;
    }

    /**
     * Close a site's folded function_invocation event once the function is
     * healthy again, so the stream reflects current reality and the next
     * outage opens a fresh event.
     *
     * Scoped to sites that actually have an open event (normally none), and
     * recovery is judged on the newest *settled* invocation — a pending async
     * row is not yet evidence either way.
     */
    private function resolveRecoveredFunctions(): void
    {
        $open = ErrorEvent::query()
            ->where('category', 'function_invocation')
            ->whereNull('dismissed_at')
            ->whereNotNull('site_id')
            ->get(['id', 'site_id']);

        foreach ($open->groupBy('site_id') as $siteId => $events) {
            $latest = FunctionInvocation::query()
                ->where('site_id', $siteId)
                ->settled()
                ->orderByDesc('id')
                ->value('success');

            if ($latest !== true && $latest !== 1) {
                continue;
            }

            ErrorEvent::query()
                ->whereIn('id', $events->pluck('id'))
                ->update(['dismissed_at' => now(), 'dismissed_by' => null]);
        }
    }

    /**
     * Notify only for genuinely new events (the recorder upserts on
     * source identity, so a re-sweep of the same failure is not "recently
     * created"). Notification failures must never break the sweep, so they're
     * swallowed — the capture itself is the source of truth.
     */
    private function maybeNotify(ErrorEvent $event, bool $notify): void
    {
        if (! $notify || ! $event->wasRecentlyCreated) {
            return;
        }

        try {
            $this->notifier->notify($event);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
