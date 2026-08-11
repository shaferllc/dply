<?php

declare(strict_types=1);

namespace App\Modules\Backups\Console;

use App\Console\Scheduling\DplySchedule;
use App\Models\BackupSchedule;
use App\Models\ServerCronJob;
use App\Services\Servers\ServerCronSynchronizer;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The engine behind every scheduled capture. Ticks once a minute from
 * {@see DplySchedule}, finds the schedules whose cron
 * expression came due since they last ran, and hands each to its per-schedule
 * runner.
 *
 * Why this exists: schedules used to materialise a `system_managed`
 * {@see ServerCronJob} row whose command pointed at the control
 * plane's artisan binary — but {@see ServerCronSynchronizer}
 * deliberately excludes `system_managed` rows from a server's crontab, and
 * nothing on the control plane iterated the schedules either. The line was
 * written to no machine and read by no scheduler, so a schedule could sit
 * "Active · Every 15 minutes · Last run: Never" indefinitely. See
 * `docs/adr/backups-as-a-product.md`, decision 14.
 *
 * Running here rather than in a crontab on the customer's box is also what
 * makes a capture possible when that box is down or wedged — which is when it
 * matters most.
 *
 * Due-ness is derived, not stamped: a schedule fires when its most recent cron
 * occurrence is newer than its `last_run_at`. Missed occurrences older than
 * `--lookback` are abandoned rather than replayed, so a scheduler outage costs
 * one run per schedule, not a stampede.
 */
final class DispatchDueBackupSchedulesCommand extends Command
{
    protected $signature = 'dply:dispatch-due-backups
        {--lookback=5 : Minutes of missed ticks still worth firing (older occurrences are abandoned, not replayed)}
        {--dry-run : List what would fire without dispatching anything}';

    protected $description = 'Dispatch every backup schedule that has come due.';

    public function handle(): int
    {
        $now = now();
        $lookback = max(1, (int) $this->option('lookback'));
        $dryRun = (bool) $this->option('dry-run');

        $fired = 0;

        foreach (BackupSchedule::query()->where('is_active', true)->cursor() as $schedule) {
            if (! $this->isDue($schedule->cron_expression, $schedule->last_run_at, $now, $lookback)) {
                continue;
            }

            $fired++;
            $this->line(($dryRun ? 'Would run' : 'Running').' schedule '.$schedule->id.' ('.$schedule->target_type.')');

            if (! $dryRun) {
                $this->callSilently(RunBackupScheduleCommand::class, ['schedule' => $schedule->id]);
            }
        }

        $this->info(($dryRun ? 'Would dispatch ' : 'Dispatched ').$fired.' due schedule(s).');

        return self::SUCCESS;
    }

    /**
     * A schedule is due when its most recent cron occurrence falls inside the
     * lookback window and is newer than the last run we recorded.
     *
     * A schedule that has never run is treated as "due from now", not "due for
     * every occurrence since it was created" — the lookback floor applies
     * either way, so turning this engine on cannot replay months of history.
     */
    private function isDue(?string $expression, ?Carbon $lastRunAt, Carbon $now, int $lookback): bool
    {
        $expression = trim((string) $expression);
        if ($expression === '' || ! CronExpression::isValidExpression($expression)) {
            return false;
        }

        $previous = Carbon::instance(
            (new CronExpression($expression))->getPreviousRunDate($now, 0, allowCurrentDate: true),
        );

        if ($previous->lt($now->copy()->subMinutes($lookback))) {
            return false;
        }

        return $lastRunAt === null || $previous->gt($lastRunAt);
    }
}
