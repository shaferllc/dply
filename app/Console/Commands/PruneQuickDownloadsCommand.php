<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\QuickDownload;
use App\Services\Servers\QuickDownloadBuildStager;
use Illuminate\Console\Command;

/**
 * Deletes quick-download staging objects whose retention window has closed (see
 * config/quick_download.php retention_minutes) and prunes stale rows. A scheduled
 * command rather than an S3 lifecycle rule so failed rows also age out of the
 * table, and so retention can be tuned below lifecycle's 1-day granularity.
 * Sibling to {@see PruneBackupDownloadStagingsCommand}.
 */
class PruneQuickDownloadsCommand extends Command
{
    protected $signature = 'dply:prune-quick-downloads';

    protected $description = 'Delete expired quick-download objects and prune stale rows.';

    public function handle(QuickDownloadBuildStager $stager): int
    {
        $sweepable = QuickDownload::query()->sweepable()->get();

        foreach ($sweepable as $row) {
            // Best-effort object delete (no-op once consume() already removed it).
            $stager->deleteObject($row);
            $row->delete();
        }

        $this->info('Pruned '.$sweepable->count().' expired/stale quick-download(s).');

        return self::SUCCESS;
    }
}
