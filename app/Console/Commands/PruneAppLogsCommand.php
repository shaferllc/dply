<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppLogRecord;
use Illuminate\Console\Command;

/**
 * Keeps the app_logs table (dply Logs app-log drain records) bounded. App logs
 * arrive over UDP from deployed sites and live in the main DB, so without a cap a
 * chatty app would grow it unbounded. Scheduled daily; retention is configurable
 * via log_drains.retention_days.
 */
class PruneAppLogsCommand extends Command
{
    protected $signature = 'app-logs:prune';

    protected $description = 'Prune old dply Logs app-log records so the app_logs table stays bounded.';

    public function handle(): int
    {
        $days = max(1, (int) config('log_drains.retention_days', 30));

        $deleted = AppLogRecord::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info('Pruned '.$deleted.' app-log record(s) older than '.$days.' day(s).');

        return self::SUCCESS;
    }
}
