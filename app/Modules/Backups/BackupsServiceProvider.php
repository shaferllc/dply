<?php

declare(strict_types=1);

namespace App\Modules\Backups;

use App\Modules\Backups\Console\DbBackupCommand;
use App\Modules\Backups\Console\DispatchDueBackupSchedulesCommand;
use App\Modules\Backups\Console\PruneBackupDownloadStagingsCommand;
use App\Modules\Backups\Console\PruneBackupsCommand;
use App\Modules\Backups\Console\RunBackupScheduleCommand;
use App\Modules\Backups\Console\DbRestoreCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Backups module wiring (docs/adr/modular-monolith-structure.md).
 *
 * The backup engine: DB/file exporters, restorer, downloader, S3 client factories,
 * download stager (extracted by capability from Services\Servers + Services\Backups),
 * the export/restore/stage jobs, and the prune/schedule/db-backup commands.
 *
 * Re-registers the commands here. The two backup observers (Server/SiteFileBackup
 * ::observe) and BackupConfigurationPolicy (Gate::policy) stay wired in
 * AppServiceProvider with repointed references. Per rule (i), the Servers backup
 * workspace tabs + concerns stay in the shell; the 5 backup models stay in
 * app/Models. Backup destinations are NOT a Backups tab and have no page of
 * their own: a bucket key is a secret handed to a third party, so they are rows
 * on the org Credentials table (App\Livewire\Credentials\Index, filter=storage).
 * /backups/storage keeps its route name and redirects there.
 */
class BackupsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                DbBackupCommand::class,
                DispatchDueBackupSchedulesCommand::class,
                PruneBackupDownloadStagingsCommand::class,
                PruneBackupsCommand::class,
                RunBackupScheduleCommand::class,
                DbRestoreCommand::class,
            ]);
        }
    }
}
