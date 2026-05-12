<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServerDatabaseBackup;
use App\Models\SiteFileBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Prune backup runs older than the configured retention period. Removes both the
 * database row AND the file on disk so storage doesn't accumulate forever.
 *
 * Failed and pending rows older than the cutoff are also pruned — they typically
 * have no disk file, so the disk-delete step is a noop for them.
 */
class PruneBackupsCommand extends Command
{
    protected $signature = 'dply:prune-backups {--dry-run : Report what would be deleted without changing anything}';

    protected $description = 'Delete backup runs older than the configured retention period (server_database.run_retention_days, site_file_backup.run_retention_days).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $dbDays = max(7, (int) config('server_database.run_retention_days', 90));
        $fileDays = max(7, (int) config('site_file_backup.run_retention_days', 90));

        $dbCount = $this->prune(
            ServerDatabaseBackup::query()->where('created_at', '<', now()->subDays($dbDays))->get(),
            config('server_database.backup_disk', 'local'),
            $dryRun,
        );

        $fileCount = $this->prune(
            SiteFileBackup::query()->where('created_at', '<', now()->subDays($fileDays))->get(),
            'local',
            $dryRun,
        );

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info($verb.' '.$dbCount.' database backup row(s) older than '.$dbDays.' days.');
        $this->info($verb.' '.$fileCount.' site file backup row(s) older than '.$fileDays.' days.');

        return self::SUCCESS;
    }

    /**
     * @param  iterable<ServerDatabaseBackup|SiteFileBackup>  $rows
     */
    private function prune(iterable $rows, string $diskName, bool $dryRun): int
    {
        $disk = Storage::disk($diskName);
        $count = 0;
        foreach ($rows as $row) {
            if (! empty($row->disk_path) && $disk->exists($row->disk_path) && ! $dryRun) {
                $disk->delete($row->disk_path);
            }
            if (! $dryRun) {
                $row->delete();
            }
            $count++;
        }

        return $count;
    }
}
