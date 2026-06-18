<?php

declare(strict_types=1);

namespace App\Modules\Backups\Console;

use App\Models\BackupDownloadStaging;
use App\Modules\Backups\Services\BackupDownloadStager;
use Illuminate\Console\Command;

/**
 * Deletes expired backup download stagings (4h TTL) and their staged Hetzner
 * objects. A scheduled command rather than an S3 lifecycle rule because lifecycle
 * granularity is 1 day, far coarser than the 4h download window.
 */
class PruneBackupDownloadStagingsCommand extends Command
{
    protected $signature = 'dply:prune-backup-download-stagings';

    protected $description = 'Delete expired backup download stagings and their staged objects.';

    public function handle(BackupDownloadStager $stager): int
    {
        $expired = BackupDownloadStaging::query()->expired()->get();

        foreach ($expired as $row) {
            $stager->deleteStaged($row);
            $row->delete();
        }

        $this->info('Pruned '.$expired->count().' expired download staging(s).');

        return self::SUCCESS;
    }
}
