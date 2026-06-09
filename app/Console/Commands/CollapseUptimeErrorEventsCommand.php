<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ErrorEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off backfill: collapse the pre-fold uptime backlog. Before the syncer
 * learned to fold uptime checks (see {@see \App\Support\Errors\ErrorEventSyncer}),
 * every failed probe minted its own ErrorEvent, so a single stuck site could
 * accumulate hundreds. This keeps the newest un-dismissed uptime event per site
 * and dismisses the rest, leaving the same one-open-per-site shape the syncer
 * now maintains going forward. Idempotent — safe to re-run.
 */
class CollapseUptimeErrorEventsCommand extends Command
{
    protected $signature = 'dply:errors:collapse-uptime
        {--dry-run : Report what would be dismissed without writing}';

    protected $description = 'Collapse the legacy per-probe uptime error backlog to one open event per site.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Keep the newest un-dismissed uptime event per site. ULIDs are
        // time-ordered, so MAX(id) is the most recently captured row — no
        // fragile occurred_at equality needed. Orphan events with no site_id
        // (the backing site was deleted) are pure noise and fall through to be
        // dismissed entirely.
        $keepIds = ErrorEvent::query()
            ->where('category', 'uptime_check')
            ->whereNull('dismissed_at')
            ->whereNotNull('site_id')
            ->groupBy('site_id')
            ->pluck(DB::raw('MAX(id)'))
            ->all();

        $stale = ErrorEvent::query()
            ->where('category', 'uptime_check')
            ->whereNull('dismissed_at')
            ->when($keepIds !== [], fn ($q) => $q->whereNotIn('id', $keepIds));

        $count = (clone $stale)->count();

        if ($dryRun) {
            $this->info("Would dismiss {$count} legacy uptime error event(s); keeping ".count($keepIds)." open.");

            return self::SUCCESS;
        }

        $stale->update(['dismissed_at' => now(), 'dismissed_by' => null]);

        $this->info("Dismissed {$count} legacy uptime error event(s); kept ".count($keepIds)." open (one per site).");

        return self::SUCCESS;
    }
}
