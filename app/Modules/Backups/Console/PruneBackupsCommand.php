<?php

declare(strict_types=1);

namespace App\Modules\Backups\Console;

use App\Models\ServerDatabaseBackup;
use App\Modules\Backups\Models\SiteFileBackup;
use App\Modules\Backups\Services\DatabaseBackupExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Prune backup runs older than the configured retention period. Removes both the
 * database row AND the artifact (remote file, S3 object, or legacy control-plane file).
 *
 * Failed and pending rows older than the cutoff are also pruned — they typically
 * have no artifact, so delete steps are no-ops for them.
 */
class PruneBackupsCommand extends Command
{
    protected $signature = 'dply:prune-backups {--dry-run : Report what would be deleted without changing anything}';

    protected $description = 'Delete backup runs older than the configured retention period (server_database.run_retention_days, site_file_backup.run_retention_days).';

    public function handle(DatabaseBackupExporter $exporter): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $dbDays = max(7, (int) config('server_database.run_retention_days', 90));
        $fileDays = max(7, (int) config('site_file_backup.run_retention_days', 90));

        $dbCount = $this->pruneDatabaseBackups(
            ServerDatabaseBackup::query()->where('created_at', '<', now()->subDays($dbDays))->get(),
            $exporter,
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
     * @param  iterable<ServerDatabaseBackup>  $rows
     */
    private function pruneDatabaseBackups(iterable $rows, DatabaseBackupExporter $exporter, bool $dryRun): int
    {
        $count = 0;
        foreach ($rows as $row) {
            if (! $dryRun) {
                $exporter->deleteArtifact($row);
                $row->delete();
            }
            $count++;
        }

        return $count;
    }

    /**
     * @param  iterable<SiteFileBackup>  $rows
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
