<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\QuickDownload;
use App\Services\Servers\QuickDownloadBuildStager;
use Illuminate\Console\Command;

/**
 * Deletes expired quick-download staging objects (4h window) and prunes stale
 * rows. A scheduled command rather than an S3 lifecycle rule because lifecycle
 * granularity is 1 day, far coarser than the 4h download window — and because
 * consumed/failed rows should age out of the table too. Sibling to
 * {@see PruneBackupDownloadStagingsCommand}.
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
