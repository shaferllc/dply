<?php

declare(strict_types=1);

namespace App\Support\Errors;

use App\Models\ConsoleAction;
use App\Models\ErrorEvent;
use App\Models\SiteDeployment;
use App\Services\Notifications\ServerErrorsNotificationDispatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Captures failed sources into {@see ErrorEvent} rows by scanning the source
 * tables. This is a sweeper, not an Eloquent observer, on purpose: most
 * failures are persisted via the query builder (e.g. {@see \App\Jobs\Concerns\WritesConsoleAction::failConsoleAction()}
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
     * Record every failed ConsoleAction / SiteDeployment finalized at or after
     * $since that isn't already captured. Returns the number of new events.
     */
    /**
     * @param  bool  $refresh  Re-record sources that already have an event too
     *                         (updateOrCreate refreshes link/title/detail after
     *                         a recorder change). Default skips captured sources.
     * @param  bool  $notify   Fire an error-stream notification for each newly
     *                         captured event. The per-minute live sweep wants
     *                         this; the historical backfill passes false so it
     *                         doesn't blast alerts for old failures.
     */
    public function sync(CarbonInterface $since, bool $refresh = false, bool $notify = true): int
    {
        return $this->syncConsoleActions($since, $refresh, $notify)
            + $this->syncDeployments($since, $refresh, $notify);
    }

    private function syncConsoleActions(CarbonInterface $since, bool $refresh = false, bool $notify = true): int
    {
        $captured = ConsoleAction::query()->getModel()->getMorphClass();
        $count = 0;

        ConsoleAction::query()
            ->where('status', ConsoleAction::STATUS_FAILED)
            ->where('updated_at', '>=', $since)
            ->when(! $refresh, fn ($q) => $q->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('error_events')
                ->whereColumn('error_events.source_id', 'console_actions.id')
                ->where('error_events.source_type', $captured)))
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
            ->chunkById(200, function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    if ($this->recorder->recordDeployment($row)) {
                        $count++;
                    }
                }
            });

        return $count;
    }
}
